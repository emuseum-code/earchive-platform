<?php

namespace Database\Seeders;

use App\Enums\AdaptationStatus;
use App\Enums\BookType;
use App\Enums\ExportFormat;
use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Enums\RecognitionStatus;
use App\Enums\VersionSource;
use App\Models\Adaptation;
use App\Models\Book;
use App\Models\Export;
use App\Models\ProcessingJob;
use App\Models\RecognitionResult;
use App\Models\RecognitionTemplate;
use App\Models\Record;
use App\Models\ReviewRemark;
use App\Models\Scan;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\AdaptationService;
use App\Services\ExportService;
use App\Services\StorageSync;
use Illuminate\Database\Seeder;

/**
 * Демо-книга зі зразка Замовника «Інвентарна книга музею розпізнаний текст.xlsx»:
 * 4 розвороти, записи з різними статусами звірки, адаптація до РМФУ з аркуша «Адаптація для РМФУ».
 * Зображення розворотів згенеровані з розпізнаного тексту (tools/make_demo_scans.py).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/data/demo-book.json'), true);
        $admin = User::where('email', 'admin@books.test')->first();
        $operator = User::where('email', 'operator@books.test')->first();
        $verifier = User::where('email', 'verifier@books.test')->first();
        auth()->setUser($admin);

        $schema = (new RegistrySeeder)->run($data['rmfu_headers']);

        // 1. Синхронізація демо-сховища створює дерево територія → музей → фонд → книга → скани
        $run = app(StorageSync::class)->run(SyncRun::create(['user_id' => $operator->id, 'scope_path' => '', 'status' => 'queued']));
        $book = Book::where('folder_path', $data['book_dir'])->firstOrFail();
        $template = RecognitionTemplate::first();
        $book->update([
            'title' => 'Інвентарна книга Д-ІІІ (документи)',
            'book_type' => BookType::Handwritten, 'book_number' => 'Д-ІІІ', 'years' => '1993–2005', 'language' => 'російська',
            'recognition_template_id' => $template->id, 'expected_pages' => 51,
            'notes' => 'Засвідчувальний напис: «В данной книге пронумеровано и прошнуровано 51 (пятьдесят один) лист». Звірка 2005 р.: усього номерів — 57, предметів — 74. У демо-наборі 4 розвороти.',
        ]);
        $book->museum->update(['short_name' => 'Лисичанський КМ', 'contact_person' => 'Н. В. Кирсанова, зберігач фондів']);
        $book->fund?->update(['code' => 'Д']);
        $operator->museums()->syncWithoutDetaching([$book->museum_id]);
        $verifier->museums()->syncWithoutDetaching([$book->museum_id]);
        User::where('email', 'viewer@books.test')->first()->books()->syncWithoutDetaching([$book->id]);

        $order = ['photo_2026-10-29_16-09-49.jpg' => 1, 'photo_2026-10-29_16-09-41.jpg' => 2, 'photo_2026-10-29_16-09-45.jpg' => 3, 'photo_2026-10-29_16-09-36.jpg' => 4];
        $labels = ['photo_2026-10-29_16-09-49.jpg' => 'Розворот, арк. 45', 'photo_2026-10-29_16-09-41.jpg' => 'Розворот, арк. 30', 'photo_2026-10-29_16-09-45.jpg' => 'Розворот, арк. 4',
            'photo_2026-10-29_16-09-36.jpg' => 'Остання сторінка, засвідчувальний напис'];

        // 2. Результати розпізнавання та записи єдиної таблиці
        $byNo = [];
        foreach ($data['pages'] as $page) {
            $scan = Scan::where('book_id', $book->id)->where('filename', $page['filename'])->firstOrFail();
            $scan->update(['page_order' => $order[$page['filename']] ?? $scan->page_order, 'page_label' => $labels[$page['filename']] ?? $page['label']]);
            $job = ProcessingJob::create(['type' => JobType::Recognize, 'status' => JobStatus::Done, 'target_type' => 'scan', 'target_id' => $scan->id,
                'user_id' => $operator->id, 'attempts' => 1, 'started_at' => now()->subDays(1), 'finished_at' => now()->subDays(1)->addSeconds(40)]);
            $isLast = str_contains($page['label'], 'остання');
            $parsedRecords = collect($page['records'])->map(fn ($r) => [
                'continuation' => is_string($r['row'][0]) && str_contains($r['row'][0], 'продовження'),
                'values' => collect($r['row'])->mapWithKeys(fn ($v, $i) => ['c'.($i + 1) => $v === null ? null : (string) $v])->all(),
            ])->all();
            $parsed = $isLast
                ? ['page_label' => $page['label'], 'records' => [], 'page_notes' => collect($page['records'])->map(fn ($r) => ['label' => $r['row'][0], 'text' => $r['row'][3]])->all()]
                : ['page_label' => $page['label'], 'records' => $parsedRecords];
            $result = RecognitionResult::create([
                'scan_id' => $scan->id, 'book_id' => $book->id, 'processing_job_id' => $job->id, 'recognition_template_id' => $template->id,
                'template_version' => $template->version, 'version' => 1, 'model' => 'Gemini AI (тестове розпізнавання Замовника, xlsx)',
                'raw_response' => json_encode($parsed, JSON_UNESCAPED_UNICODE), 'parsed' => $parsed, 'schema_valid' => true,
                'records_found' => count($parsed['records']), 'processed_at' => now()->subDays(1),
            ]);
            $scan->update(['recognition_status' => RecognitionStatus::Done, 'recognized_at' => now()->subDays(1)]);
            if ($isLast) {
                continue;
            }
            foreach ($page['records'] as $pos => $r) {
                $vals = [];
                foreach ($data['keys'] as $i => $k) {
                    $v = $r['row'][$i];
                    $vals[$k] = $v === null || $v === '' ? null : (string) $v;
                }
                $continuation = str_contains((string) $vals['record_no'], 'продовження');
                $uncertain = [];
                if ($continuation) {
                    $vals['record_no'] = preg_replace('/\D.*$/u', '', $vals['record_no']);
                    $uncertain['description'] = 'Початок опису — на попередньому розвороті, якого немає в наборі сканів';
                }
                if (preg_match('/^(\d+)\s*\((\d+)\)$/u', (string) $vals['record_no'], $m)) {
                    $vals['record_no'] = $m[1];
                    $uncertain['record_no'] = 'Поруч у дужках старий номер '.$m[2];
                }
                $record = Record::create($vals + [
                    'book_id' => $book->id, 'museum_id' => $book->museum_id, 'fund_id' => $book->fund_id, 'recognition_result_id' => $result->id,
                    'sequence' => $scan->page_order * 100 + $pos + 1, 'uncertain_fields' => $uncertain ?: null,
                    'field_regions' => collect($r['regions'])->map(fn ($b) => $b + ['scan_id' => $scan->id])->all(), 'created_by' => $operator->id,
                ]);
                $record->pages()->create(['scan_id' => $scan->id, 'position' => 1, 'is_continuation' => $continuation, 'region' => $r['row_box']]);
                $record->versions()->create(['version' => 1, 'source' => VersionSource::Recognition->value, 'data' => $record->snapshot(), 'user_id' => $operator->id]);
                $byNo[$vals['record_no']] = $record;
            }
        }

        // 3. Звірка: різні стани для демонстрації
        foreach (['1816', '1817', '1818', '1819', '1686'] as $no) {
            $byNo[$no]->confirmVerification($verifier);
        }
        $r1688 = $byNo['1688'];
        ReviewRemark::create(['record_id' => $r1688->id, 'book_id' => $book->id, 'scan_id' => $r1688->scans()->value('scans.id'), 'type' => 'field', 'field' => 'dimensions',
            'region' => ($r1688->field_regions['dimensions'] ?? null), 'comment' => 'Пропущено одиниці виміру; у книзі «10,5 х 7,5» без «см».',
            'current_value' => $r1688->dimensions, 'suggested_value' => '10,5 х 7,5 см', 'user_id' => $verifier->id]);
        $r1688->update(['verification_status' => 'needs_clarification']);
        $r1685 = $byNo['1685'];
        ReviewRemark::create(['record_id' => $r1685->id, 'book_id' => $book->id, 'scan_id' => $r1685->scans()->value('scans.id'), 'type' => 'continuation',
            'comment' => 'Опис продовжується з попереднього розвороту (арк. 44). Потрібен скан арк. 44, щоб звірити початок запису.', 'user_id' => $verifier->id]);
        $r1685->update(['verification_status' => 'needs_clarification']);

        // 4. Адаптація до РМФУ: значення з аркуша «Адаптація для РМФУ» Замовника
        $keys = array_keys(RegistrySeeder::FIELDS);
        $service = app(AdaptationService::class);
        foreach ($data['rmfu_rows'] as $row) {
            $record = ($byNo[(string) $row[0]] ?? null)?->refresh();
            if (! $record || $record->verification_status->value !== 'verified') {
                continue;
            }
            $values = [];
            foreach ($keys as $i => $k) {
                $values[$k] = $row[$i] === null ? null : (string) $row[$i];
            }
            $sources = [];
            foreach ($schema->rules as $rule) {
                $sources[$rule->target_field] = ['fields' => $rule->source_fields, 'rule' => $rule->use_ai ? 'ai' : $rule->transform,
                    'raw' => collect($rule->source_fields)->map(fn ($f) => $record->$f)->filter()->implode(' | ') ?: null];
            }
            $a = new Adaptation(['record_id' => $record->id, 'registry_schema_id' => $schema->id, 'version' => 1, 'source_record_version' => $record->lock_version,
                'rules_version' => $schema->rulesVersion(), 'is_current' => true, 'values' => $values, 'sources' => $sources, 'created_by' => $admin->id]);
            $service->validate($a, $schema->fresh('fields'));
            $a->save();
            $record->update(['adaptation_status' => $a->status, 'export_status' => 'not_ready']);
            if ($a->errors_count === 0) {
                $service->confirm($a, $verifier);
            }
        }
        $book->refreshCounters();

        // 5. Експорт готових записів у шаблон РМФУ
        $ready = Record::where('book_id', $book->id)->where('export_status', 'ready')->whereIn('record_no', ['1816', '1817', '1818'])->pluck('id')->all();
        if ($ready) {
            app(ExportService::class)->build(Export::create(['user_id' => $admin->id, 'format' => ExportFormat::Registry, 'status' => 'queued', 'filters' => ['ids' => $ready]]));
        }
        auth()->forgetGuards();
    }
}
