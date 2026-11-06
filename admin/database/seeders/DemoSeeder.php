<?php

namespace Database\Seeders;

use App\Enums\BookType;
use App\Enums\ExportFormat;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Models\Book;
use App\Models\Export;
use App\Models\ProcessingJob;
use App\Models\RecognitionTemplate;
use App\Models\Record;
use App\Models\ReviewRemark;
use App\Models\Scan;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\AdaptationService;
use App\Services\ExportService;
use App\Services\RecognitionService;
use App\Services\StorageSync;
use Illuminate\Database\Seeder;

/**
 * Демо-справа «Листування про ремонт школи» (Ф. Р-101, оп. 1, спр. 7): 4 аркуші, 4 документи,
 * лист на двох аркушах, різні статуси звірки, опис ISAD(G) і пробний експорт.
 * Документи вигадані, зображення згенеровані (tools/make_demo_case.py).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/data/demo-case.json'), true);
        $admin = User::where('email', 'admin@archive.test')->first();
        $operator = User::where('email', 'operator@archive.test')->first();
        $verifier = User::where('email', 'verifier@archive.test')->first();
        auth()->setUser($admin);

        $schema = (new RegistrySeeder)->run();

        // 1. Синхронізація демо-сховища: територія → архів → фонд → опис → справа → скани
        app(StorageSync::class)->run(SyncRun::create(['user_id' => $operator->id, 'scope_path' => '', 'status' => 'queued']));
        $book = Book::where('folder_path', $data['case_dir'])->firstOrFail();
        $template = RecognitionTemplate::where('name', 'Архівний документ (універсальний)')->firstOrFail();
        $book->update([
            'title' => 'Листування з окружною інспектурою народної освіти про ремонт школи',
            'book_type' => BookType::Mixed, 'book_number' => '7', 'inventory_no' => '1', 'years' => '12.03.1927–1928', 'language' => 'українська',
            'recognition_template_id' => $template->id, 'expected_pages' => 4,
            'notes' => 'Демонстраційна справа: документи вигадані, зображення згенеровані.',
        ]);
        $book->museum->update(['short_name' => 'Демо-архів', 'contact_person' => 'Відділ забезпечення збереженості документів']);
        $book->fund?->update(['code' => 'Р-101']);
        $operator->museums()->syncWithoutDetaching([$book->museum_id]);
        $verifier->museums()->syncWithoutDetaching([$book->museum_id]);
        User::where('email', 'viewer@archive.test')->first()->books()->syncWithoutDetaching([$book->id]);

        // 2. Результат розпізнавання кожного аркуша: той самий шлях, що й відповідь Gemini
        $recognition = app(RecognitionService::class);
        $box = fn (array $r) => [(int) round($r['y'] * 1000), (int) round($r['x'] * 1000), (int) round(($r['y'] + $r['h']) * 1000), (int) round(($r['x'] + $r['w']) * 1000)];
        $seen = [];
        foreach ($data['pages'] as $i => $page) {
            $scan = Scan::where('book_id', $book->id)->where('filename', $page['filename'])->firstOrFail();
            $scan->update(['page_order' => $i + 1, 'page_label' => $page['label']]);
            $records = [];
            foreach ($page['records'] as $r) {
                $doc = $data['documents'][$r['doc']];
                $continuation = isset($seen[$r['doc']]);
                $seen[$r['doc']] = true;
                $values = $continuation ? ['full_text' => $r['text']] : collect($doc)->except('full_text')->all() + ['full_text' => $r['text']];
                $records[] = [
                    'continuation' => $continuation,
                    'values' => array_filter($values, fn ($v) => $v !== null),
                    'uncertain' => $r['doc'] === '4' ? [['field' => 'full_text', 'reason' => 'Кут аркуша відірвано, сума неповна']] : [],
                    'regions' => collect($r['regions'])->map(fn ($reg, $f) => ['field' => $f, 'box' => $box($reg)])->values()->all(),
                    'row_box' => $box($r['row_box']),
                ];
            }
            $json = ['page_label' => $page['label'], 'records' => $records];
            $job = ProcessingJob::create(['type' => JobType::Recognize, 'status' => JobStatus::Done, 'target_type' => 'scan', 'target_id' => $scan->id,
                'user_id' => $operator->id, 'attempts' => 1, 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()->addSeconds(30)]);
            $recognition->store($scan, $template, ['json' => $json, 'raw' => json_encode($json, JSON_UNESCAPED_UNICODE), 'model' => 'Gemini AI (демо-приклад)'], $job);
        }

        $byNo = Record::where('book_id', $book->id)->get()->keyBy('record_no');

        // 3. Звірка: різні стани
        foreach (['1', '3'] as $no) {
            $byNo[$no]->refresh()->confirmVerification($verifier);
        }
        $r2 = $byNo['2'];
        ReviewRemark::create(['record_id' => $r2->id, 'book_id' => $book->id, 'scan_id' => $r2->scans()->value('scans.id'), 'type' => 'field', 'field' => 'addressee',
            'region' => $r2->field_regions['author'] ?? null, 'comment' => 'Адресата в довідці не зазначено, але над текстом видно згаслий напис «До трудшколи». Уточнити за оригіналом.',
            'current_value' => null, 'suggested_value' => 'Вишнівська трудова школа', 'user_id' => $verifier->id]);
        $r2->update(['verification_status' => 'needs_clarification']);

        // 4. Опис ISAD(G) для перевірених документів
        $service = app(AdaptationService::class);
        foreach (['1', '3'] as $no) {
            $a = $service->adapt($byNo[$no]->refresh(), $admin, null, $schema);
            if ($a->errors_count === 0) {
                $service->confirm($a, $verifier);
            }
        }
        $book->refreshCounters();

        // 5. Пробний експорт готових описів
        $ready = Record::where('book_id', $book->id)->where('export_status', 'ready')->pluck('id')->all();
        if ($ready) {
            app(ExportService::class)->build(Export::create(['user_id' => $admin->id, 'format' => ExportFormat::Registry, 'status' => 'queued', 'filters' => ['ids' => $ready]]));
        }
        auth()->forgetGuards();
    }
}
