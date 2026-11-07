<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Record;
use App\Models\SyncRun;
use App\Services\StorageSync;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * PDF документів еАрхіву МЗС (папка documents-earchive з сервера старого сайту) → аркуші справ «Архіву».
 * Без розпізнавання: сторінки лише додаються до справи й прив'язуються до документа каталогу,
 * розпізнають їх потім у програмі. Повторний запуск не конвертує PDF, для яких аркуші вже є.
 */
class AttachEarchiveScans extends Command
{
    protected $signature = 'archive:attach-earchive-scans
        {--pdf-dir= : Папка, у якій лежить documents-earchive (типово storage/app/earchive-files)}
        {--dpi=150 : Роздільність аркушів} {--limit=0 : Скільки документів обробити за раз (0 — усі)}';

    protected $description = 'Додати скани (PDF → аркуші) до документів каталогу еАрхіву МЗС без розпізнавання';

    public function handle(StorageSync $sync): int
    {
        $data = json_decode(File::get(database_path('data/earchive/catalog.json')), true);
        $pdfDir = rtrim($this->option('pdf-dir') ?: storage_path('app/earchive-files'), '/');
        $root = rtrim((string) Settings::get('storage.root'), '/');
        $limit = (int) $this->option('limit');
        $done = $missing = $noRecord = 0;
        $pending = [];

        foreach ($data['documents'] as $doc) {
            if (! $doc['file']) {
                continue;
            }
            $pdf = $pdfDir.'/'.preg_replace('#^/?files/#', '', (string) $doc['file']);
            if (! is_file($pdf)) {
                $missing++;

                continue;
            }
            $record = Record::where('extra_fields->earchive_id', $doc['id'])->with('book')->first();
            if (! $record?->book) {
                $noRecord++;

                continue;
            }
            if ($record->scans()->exists()) {
                continue;
            }
            $dir = $record->book->folder_path;
            $abs = $root.'/'.$dir;
            File::ensureDirectoryExists($abs);
            $prefix = pathinfo($pdf, PATHINFO_FILENAME);
            if (! glob($abs.'/'.$prefix.'-*.jpg')) {
                $p = new Process(['pdftoppm', '-r', (string) $this->option('dpi'), '-jpeg', '-jpegopt', 'quality=85', $pdf, $abs.'/'.$prefix]);
                $p->setTimeout(1800)->run();
                if (! $p->isSuccessful()) {
                    $this->warn("PDF не прочитано: {$pdf}");

                    continue;
                }
            }
            $pending[$dir][] = [$record, $prefix, $doc['sheets'] ?? null];
            $this->line("{$doc['id']}: {$dir} ← ".basename($pdf));
            if ($limit && ++$done >= $limit) {
                break;
            }
        }

        foreach ($pending as $dir => $items) {
            $sync->run(SyncRun::create(['scope_path' => $dir, 'status' => 'queued']));
            $book = Book::where('folder_path', $dir)->first();
            if (! $book) {
                continue;
            }
            $scans = $book->scans()->get();
            foreach ($items as [$record, $prefix, $sheets]) {
                $pages = $scans->filter(fn ($s) => preg_match('/^'.preg_quote($prefix, '/').'-(\d+)\.jpg$/u', $s->filename))
                    ->sortBy(fn ($s) => (int) preg_replace('/^.*-(\d+)\.jpg$/u', '$1', $s->filename))->values();
                $attach = [];
                foreach ($pages as $i => $scan) {
                    $n = (int) preg_replace('/^.*-(\d+)\.jpg$/u', '$1', $scan->filename);
                    if (! $scan->page_label) {
                        $scan->update(['page_label' => mb_substr(($sheets ? 'арк. '.trim($sheets).', ' : '').'с. '.$n, 0, 255)]);
                    }
                    $attach[$scan->id] = ['position' => $i + 1, 'is_continuation' => $i > 0];
                }
                $record->scans()->syncWithoutDetaching($attach);
            }
            $book->refreshCounters();
        }
        $this->info('Документів зі сканами: '.collect($pending)->flatten(1)->count().", PDF не знайдено: {$missing}, без документа в базі: {$noRecord}");

        return self::SUCCESS;
    }
}
