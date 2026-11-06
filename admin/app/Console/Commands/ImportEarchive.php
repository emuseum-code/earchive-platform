<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Museum;
use App\Models\SyncRun;
use App\Services\StorageSync;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Документи розділу каталогу earchive.mfa.gov.ua: PDF → аркуші JPG у сховищі
 * (м. Київ / ЦДАВО України / Ф. N … / Опис N / Справа N), синхронізація, шифри фонду, опису й справи.
 * Метадані — database/data/earchive/category-N.json (tools/earchive_subset.py), PDF — з гілки data/earchive-pdfs.
 */
class ImportEarchive extends Command
{
    protected $signature = 'archive:import-earchive {category=2 : Розділ каталогу} {--ids= : ID документів через кому}
        {--pdf-dir= : Папка з PDF (типово storage/app/earchive-pdfs)} {--dpi=150 : Роздільність аркушів}';

    protected $description = 'Імпортувати документи розділу каталогу еАрхіву МЗС: PDF → аркуші справ';

    public function handle(StorageSync $sync): int
    {
        $data = json_decode(File::get(database_path('data/earchive/category-'.$this->argument('category').'.json')), true);
        $ids = array_filter(array_map('intval', explode(',', (string) $this->option('ids'))));
        $pdfDir = $this->option('pdf-dir') ?: storage_path('app/earchive-pdfs');
        $root = rtrim((string) Settings::get('storage.root'), '/');
        File::ensureDirectoryExists($root);
        $done = [];

        foreach ($data['documents'] as $doc) {
            if ($ids && ! in_array($doc['id'], $ids, true)) {
                continue;
            }
            $pdf = $pdfDir.'/'.basename($doc['file']);
            if (! is_file($pdf)) {
                $this->warn("Немає PDF: {$pdf}");

                continue;
            }
            if (! $doc['fund'] || ! $doc['case']) {
                $this->warn("Без шифру справи, пропускаю: {$doc['title']}");

                continue;
            }
            $dir = self::caseDir($doc);
            $abs = $root.'/'.$dir;
            File::ensureDirectoryExists($abs);
            $prefix = pathinfo($pdf, PATHINFO_FILENAME);
            if (! glob($abs.'/'.$prefix.'-*.jpg')) {
                $p = new Process(['pdftoppm', '-r', (string) $this->option('dpi'), '-jpeg', '-jpegopt', 'quality=85', $pdf, $abs.'/'.$prefix]);
                $p->setTimeout(600)->mustRun();
            }
            $done[$dir][] = $doc;
            $this->line("{$doc['id']}: {$dir} ← ".basename($pdf));
        }

        foreach ($done as $dir => $docs) {
            $sync->run(SyncRun::create(['scope_path' => $dir, 'status' => 'queued']));
            $book = Book::where('folder_path', $dir)->first();
            if (! $book) {
                $this->error("Справу не створено: {$dir} (перевірте правила папок)");

                continue;
            }
            $d = $docs[0];
            $book->fund?->update(['code' => $d['fund']]);
            $book->museum?->update(['short_name' => $d['archive']]);
            $catalog = collect($docs)->map(fn ($x) => '• арк. '.$x['sheets'].': '.$x['title'].' ('.$x['date'].') '.$x['url'])->implode("\n");
            $book->update([
                'inventory_no' => $d['inventory'], 'book_number' => $d['case'],
                'language' => collect($docs)->pluck('languages')->flatten()->filter()->unique()->implode(', ') ?: null,
                'years' => collect($docs)->pluck('date')->implode('; '),
                'notes' => "За каталогом еАрхіву МЗС, розділ «{$data['category']}»:\n".$catalog,
            ]);
            // підпис сторінки: PDF документа й номер сторінки в ньому
            foreach ($book->scans()->get() as $scan) {
                if (preg_match('/^(.*)-(\d+)\.jpg$/u', $scan->filename, $m)) {
                    $doc = collect($docs)->first(fn ($x) => pathinfo($x['file'], PATHINFO_FILENAME) === $m[1]);
                    $scan->update(['page_label' => ($doc ? 'арк. '.$doc['sheets'].', ' : '').'с. '.(int) $m[2]]);
                }
            }
            $book->refreshCounters();
        }
        Museum::where('name', 'ЦДАВО України')->update(['notes' => 'Центральний державний архів вищих органів влади та управління України']);
        $this->info('Справ: '.count($done).', документів: '.collect($done)->flatten(1)->count());

        return self::SUCCESS;
    }

    /** м. Київ / ЦДАВО України / Ф. 3696 Міністерство закордонних справ УНР / Опис 2 / Справа 21а */
    public static function caseDir(array $doc): string
    {
        $clean = fn (?string $s) => trim(str_replace(['/', '\\'], ' ', (string) $s));
        $fundName = Str::limit($clean(Str::before((string) $doc['fund_name'], ',')), 110, '');

        return implode('/', ['м. Київ', $clean($doc['archive'] ?: 'Архів'), trim('Ф. '.$doc['fund'].' '.$fundName),
            'Опис '.$clean($doc['inventory'] ?: '1'), 'Справа '.$clean($doc['case'])]);
    }
}
