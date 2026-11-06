<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Record;
use App\Models\SyncRun;
use App\Services\StorageSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Каталог еАрхіву МЗС (1014 документів з дампу сайту) → справи й документи «Архіву» без сканів.
 * Документи, PDF яких уже імпортовано й розпізнано (archive:import-earchive), пропускає.
 * Повторний запуск додає лише відсутні документи (ключ — extra_fields.earchive_id).
 */
class ImportEarchiveCatalog extends Command
{
    protected $signature = 'archive:import-earchive-catalog {--file= : JSON каталогу (за замовчуванням database/data/earchive/catalog.json)}';

    protected $description = 'Імпорт каталогу документів еАрхіву МЗС (метадані, без сканів)';

    public function handle(StorageSync $sync): int
    {
        $data = json_decode(File::get($this->option('file') ?: database_path('data/earchive/catalog.json')), true);
        $withPdf = collect(File::files(database_path('data/earchive/pdfs')))->map(fn ($f) => $f->getFilename())->all();
        $run = SyncRun::create(['scope_path' => 'еАрхів МЗС (каталог)', 'status' => 'queued']);
        $created = $skipped = $failed = 0;
        $books = [];

        foreach ($data['documents'] as $doc) {
            if (in_array(basename((string) $doc['file']), $withPdf, true)) {
                // уже розпізнані з PDF: лише розділ каталогу для фільтра
                foreach (Book::where('folder_path', ImportEarchive::caseDir($doc))->first()?->records()->get() ?? [] as $record) {
                    if (empty($record->extra_fields['earchive_category']) && $doc['category']) {
                        $record->update(['extra_fields' => array_merge($record->extra_fields ?? [], ['earchive_category' => $doc['category']])]);
                    }
                }
                $skipped++;

                continue;
            }
            if (Record::where('extra_fields->earchive_id', $doc['id'])->exists()) {
                $skipped++;

                continue;
            }
            $doc['archive'] = self::clean($doc['archive']) ?: 'Архів не вказано';
            $doc['case'] = self::clean($doc['case']) ?: 'б/н';
            $doc['fund'] = self::clean($doc['fund']) ?: 'б/н';
            $dir = ImportEarchive::caseDir($doc);
            $book = $books[$dir] ??= $sync->ensureBook($dir, $run);
            if (! $book) {
                $this->warn("Справу не створено: {$dir}");
                $failed++;

                continue;
            }
            $sheets = self::clean($doc['sheets']);
            $list = fn ($v) => collect((array) $v)->map(fn ($x) => self::clean($x))->filter()->unique()->implode(', ') ?: null;
            Record::create([
                'book_id' => $book->id, 'museum_id' => $book->museum_id, 'fund_id' => $book->fund_id,
                'sequence' => (int) $book->records()->max('sequence') + 100,
                'title' => self::clean($doc['title']),
                'description' => self::clean(strip_tags((string) $doc['description'])) ?: null,
                'doc_date' => Str::limit((string) self::clean($doc['date']), 100, '') ?: null,
                'sheets' => Str::limit((string) $sheets, 50, '') ?: null,
                'archive_code' => Str::limit($book->archiveCode().($sheets ? ', арк. '.$sheets : ''), 150, ''),
                'language' => Str::limit((string) $list($doc['languages']), 100, '') ?: null,
                'authenticity' => Str::limit((string) self::clean($doc['authenticity']), 100, '') ?: null,
                'reproduction' => Str::limit((string) $list($doc['reproduction']), 100, '') ?: null,
                'places' => $list($doc['countries']),
                'notes' => trim('Каталог еАрхіву МЗС'.($doc['category'] ? ', розділ «'.$doc['category'].'»' : '').': '.$doc['url']),
                'extra_fields' => array_filter([
                    'earchive_id' => $doc['id'], 'earchive_url' => $doc['url'], 'earchive_category' => $doc['category'],
                    'earchive_file' => $doc['file'], 'countries' => $list($doc['countries']),
                ]),
            ]);
            $created++;
        }
        foreach ($books as $book) {
            if ($book) {
                $docs = $book->records()->get();
                $book->update([
                    'years' => $book->years ?: ImportEarchive::years($docs->map(fn ($r) => ['date' => $r->doc_date])->all()),
                    'language' => $book->language ?: (Str::limit($docs->pluck('language')->filter()->unique()->implode(', '), 29, '…') ?: null),
                ]);
                $book->refreshCounters();
            }
        }
        $run->update(['status' => 'done', 'finished_at' => now()]);
        $this->info("Документів додано: {$created}, пропущено: {$skipped}, без справи: {$failed}, справ: ".count(array_filter($books)));

        return self::SUCCESS;
    }

    /** Прибирає невидимі символи (U+2060 тощо) і зайві пробіли. */
    private static function clean(mixed $s): ?string
    {
        $s = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', (string) $s)));

        return $s === '' ? null : $s;
    }
}
