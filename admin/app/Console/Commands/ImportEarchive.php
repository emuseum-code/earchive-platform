<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Museum;
use App\Models\RecognitionTemplate;
use App\Models\SyncRun;
use App\Services\RecognitionService;
use App\Services\StorageSync;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Документи розділу каталогу earchive.mfa.gov.ua: PDF → аркуші JPG у сховищі
 * (м. Київ / ЦДАВО України / Ф. N … / Опис N / Справа N), синхронізація, шифри фонду, опису й справи.
 * Метадані — database/data/earchive/category-N.json (tools/earchive_subset.py), PDF — database/data/earchive/pdfs (надіслав Дмитро: сайт за Cloudflare).
 */
class ImportEarchive extends Command
{
    public const RECOGNIZED_MODEL = 'Claude (розпізнано вручну для показу, без Gemini)';

    protected $signature = 'archive:import-earchive {category=2 : Розділ каталогу} {--ids= : ID документів через кому}
        {--pdf-dir= : Папка з PDF (типово database/data/earchive/pdfs)} {--dpi=150 : Роздільність аркушів}
        {--recognized : Завантажити готові результати розпізнавання з database/data/earchive/recognized}';

    protected $description = 'Імпортувати документи розділу каталогу еАрхіву МЗС: PDF → аркуші справ';

    public function handle(StorageSync $sync, RecognitionService $recognition): int
    {
        $data = json_decode(File::get(database_path('data/earchive/category-'.$this->argument('category').'.json')), true);
        $ids = array_filter(array_map('intval', explode(',', (string) $this->option('ids'))));
        $pdfDir = $this->option('pdf-dir') ?: database_path('data/earchive/pdfs');
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
                'language' => Str::limit(collect($docs)->pluck('languages')->flatten()->filter()->unique()->implode(', '), 29, '…') ?: null,
                'years' => self::years($docs),
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
        if ($this->option('recognized')) {
            $this->loadRecognized(array_keys($done), $recognition);
        }
        // розділ каталогу — для фільтра «Розділ еАрхіву»
        if ($data['category_id'] ?? null) {
            foreach (Book::whereIn('folder_path', array_keys($done))->get() as $book) {
                foreach ($book->records()->get() as $record) {
                    if (empty($record->extra_fields['earchive_category'])) {
                        $record->update(['extra_fields' => array_merge($record->extra_fields ?? [], ['earchive_category' => $data['category']])]);
                    }
                }
            }
        }
        Museum::where('name', 'ЦДАВО України')->update(['notes' => 'Центральний державний архів вищих органів влади та управління України']);
        $this->info('Справ: '.count($done).', документів: '.collect($done)->flatten(1)->count());

        return self::SUCCESS;
    }

    /**
     * Готові результати розпізнавання (JSON за схемою шаблону: сторінка → page_label, records) проходять той самий
     * шлях, що й відповідь Gemini: перевірка схеми, документи, продовження на наступних сторінках. Далі документ перевіряє верифікатор і адаптує в ISAD(G).
     */
    private function loadRecognized(array $dirs, RecognitionService $recognition): void
    {
        $results = [];
        foreach (glob(database_path('data/earchive/recognized/*.json')) as $file) {
            $results += json_decode(File::get($file), true);
        }
        $template = RecognitionTemplate::where('is_active', true)->firstOrFail();
        $pages = 0;
        foreach (Book::whereIn('folder_path', $dirs)->get() as $book) {
            foreach ($book->scans()->orderBy('page_order')->get() as $scan) {
                if (! isset($results[$scan->filename]) || $scan->results()->exists()) {
                    continue;
                }
                $recognition->store($scan, $template, [
                    'json' => $results[$scan->filename],
                    'raw' => json_encode($results[$scan->filename], JSON_UNESCAPED_UNICODE),
                    'model' => self::RECOGNIZED_MODEL,
                ]);
                $pages++;
            }
        }
        $this->info('Розпізнано сторінок: '.$pages);
    }

    /** м. Київ / ЦДАВО України / Ф. 3696 Міністерство закордонних справ УНР / Опис 2 / Справа 21а */
    /** Роки справи для поля на 50 знаків: «1920» або «1920–1921» (повні дати лишаються в примітках). */
    public static function years(array $docs): ?string
    {
        preg_match_all('/\b(1[5-9]\d\d|20\d\d)\b/u', collect($docs)->pluck('date')->implode(' '), $m);
        $y = collect($m[1])->map(fn ($x) => (int) $x)->unique()->sort()->values();

        return $y->isEmpty() ? null : ($y->first() === $y->last() ? (string) $y->first() : $y->first().'–'.$y->last());
    }

    public static function caseDir(array $doc): string
    {
        $clean = fn (?string $s) => trim(str_replace(['/', '\\'], ' ', (string) $s));
        $fundName = Str::limit($clean(Str::before((string) $doc['fund_name'], ',')), 110, '');

        // українські центральні державні архіви — у Києві, архіви інших країн і посольства — «За кордоном»
        $place = match (true) {
            preg_match('/^ЦДА[А-ЯІЇЄҐ]/u', (string) $doc['archive']) === 1 => 'м. Київ',
            in_array($doc['archive'], [null, '', 'Архів не вказано'], true) => 'Не вказано',
            default => 'За кордоном',
        };

        return implode('/', [$place, $clean($doc['archive'] ?: 'Архів'), trim('Ф. '.$doc['fund'].' '.$fundName),
            'Опис '.$clean($doc['inventory'] ?: '1'), 'Справа '.$clean($doc['case'])]);
    }
}
