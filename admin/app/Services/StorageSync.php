<?php

namespace App\Services;

use App\Enums\FileStatus;
use App\Enums\FolderLevel;
use App\Enums\JobStatus;
use App\Models\Book;
use App\Models\FolderRule;
use App\Models\Fund;
use App\Models\Museum;
use App\Models\Scan;
use App\Models\SyncRun;
use App\Models\Territory;
use App\Models\UnmappedFolder;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Support\Str;

/**
 * Синхронізація серверних папок зі структурою в базі (ТЗ 4.2).
 * Повторний запуск не створює дублікатів: об’єкти шукаються за відносним шляхом.
 * Зниклі файли позначаються недоступними, документи та історія не видаляються.
 */
class StorageSync
{
    private string $root;

    private array $rules;

    private array $report = ['new' => [], 'changed' => [], 'missing' => [], 'restored' => [], 'corrupted' => [], 'unmapped' => [], 'errors' => []];

    private array $touchedBooks = [];

    public function run(SyncRun $run): SyncRun
    {
        $run->update(['status' => JobStatus::Running, 'started_at' => now()]);
        $startedAt = now()->subSecond();
        try {
            $this->root = rtrim((string) Settings::get('storage.root'), '/');
            if ($this->root === '' || ! is_dir($this->root) || ! is_readable($this->root)) {
                throw new \RuntimeException('Коренева директорія недоступна: '.($this->root ?: 'не задано'));
            }
            $scope = trim($run->scope_path, '/');
            $scopeAbs = $scope === '' ? $this->root : $this->root.'/'.$scope;
            if (! is_dir($scopeAbs) || ! str_starts_with(realpath($scopeAbs), realpath($this->root))) {
                throw new \RuntimeException('Папка поза кореневою директорією або не існує: '.$scope);
            }
            $this->rules = FolderRule::where('is_active', true)->orderBy('priority')->get()->all();
            $withChecksum = $run->with_checksum || Settings::get('storage.checksum');
            $exts = array_map('strtolower', (array) Settings::get('storage.extensions'));

            $folders = $this->collectFolders($scopeAbs, $exts);
            $run->folders_found = count($folders);
            foreach ($folders as $rel => $files) {
                $run->files_found += count($files);
                $book = $this->resolveBook($rel, count($files), $run);
                if (! $book) {
                    continue;
                }
                $this->syncFiles($book, $rel, $files, $withChecksum, $run);
            }

            // Файли в області перевірки, яких не знайдено, — недоступні
            Scan::query()->where('file_status', '!=', FileStatus::Missing->value)
                ->when($scope !== '', fn ($q) => $q->where('path', 'like', $scope.'/%'))
                ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $startedAt))
                ->chunkById(500, function ($scans) use ($run) {
                    foreach ($scans as $scan) {
                        $scan->update(['file_status' => FileStatus::Missing]);
                        $this->touchedBooks[$scan->book_id] = true;
                        $this->report['missing'][] = $scan->path;
                        $run->missing_scans++;
                    }
                });

            foreach (array_keys($this->touchedBooks) as $id) {
                Book::find($id)?->forceFill(['synced_at' => now()])->refreshCounters();
            }
            $run->status = JobStatus::Done;
        } catch (\Throwable $e) {
            $this->report['errors'][] = $e->getMessage();
            $run->status = JobStatus::Failed;
        }
        $run->errors_count = count($this->report['errors']);
        // у звіті — не більше 1000 шляхів на список, щоб звіт по всьому сховищу лишався читабельним
        $run->report = array_map(fn ($l) => array_slice($l, 0, 1000), $this->report);
        $run->finished_at = now();
        $run->save();
        Activity::log('sync.finished', $run, sprintf('Нових сканів: %d, змінених: %d, недоступних: %d, помилок: %d',
            $run->new_scans, $run->changed_scans, $run->missing_scans, $run->errors_count), [], $run->user);

        return $run;
    }

    /**
     * Скани, завантажені з браузера в одну справу, — одразу в базу, без синхронізації:
     * сховище не обходиться, інші папки й зниклі файли не перевіряються.
     *
     * @param  list<string>  $names  імена файлів, уже збережених у папці справи
     * @return array{0: int, 1: list<string>} нових сторінок і помилки
     */
    public function registerUploaded(Book $book, array $names): array
    {
        $this->root = rtrim((string) Settings::get('storage.root'), '/');
        // як і синхронізація, реєструємо лише дозволені розширення (Налаштування → Сховище)
        $exts = array_map('strtolower', (array) Settings::get('storage.extensions'));
        foreach ($names as $i => $name) {
            if (! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $exts, true)) {
                $this->report['errors'][] = $name.': розширення не входить до дозволених ('.implode(', ', $exts).')';
                unset($names[$i]);
            }
        }
        usort($names, fn ($a, $b) => [self::pageKindRank($a), strnatcasecmp($a, $b)] <=> [self::pageKindRank($b), 0]);
        // лише лічильники для syncFiles; у журнал синхронізацій нічого не пишеться
        $run = new SyncRun;
        $run->forceFill(['new_scans' => 0, 'changed_scans' => 0, 'restored_scans' => 0, 'corrupted_scans' => 0]);
        $this->syncFiles($book, $book->folder_path, array_values($names), (bool) Settings::get('storage.checksum'), $run);
        $book->forceFill(['synced_at' => now()])->refreshCounters();

        return [(int) $run->new_scans, $this->report['errors']];
    }

    /** @return array<string, list<string>> відносна папка => JPG-файли (природний порядок) */
    private function collectFolders(string $scopeAbs, array $exts): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($scopeAbs, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
            \RecursiveIteratorIterator::SELF_FIRST, \RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($it as $file) {
            if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), $exts, true)) {
                continue;
            }
            $rel = ltrim(Str::after($file->getPath(), $this->root), '/');
            $out[$rel][] = $file->getFilename();
        }
        foreach ($out as &$files) {
            // обкладинка («обложка.JPG») завжди перша, остання сторінка («посл.стр..JPG») — остання,
            // решта (зазвичай IMG_0001.JPG від камери) — у природному порядку
            usort($files, fn ($a, $b) => [self::pageKindRank($a), strnatcasecmp($a, $b)] <=> [self::pageKindRank($b), 0]);
            $files = array_values($files);
        }
        ksort($out, SORT_NATURAL);

        return $out;
    }

    /** Вид сторінки за назвою файлу: обкладинка, остання сторінка чи звичайний розворот. */
    public static function pageKind(string $filename): string
    {
        $name = mb_strtolower(pathinfo($filename, PATHINFO_FILENAME));
        if (preg_match('~^(обложка|обкладинка|обкл|cover)~u', $name)) {
            return 'cover';
        }
        if (preg_match('~^(посл|остання|останн|last)~u', $name)) {
            return 'last';
        }

        return 'content';
    }

    private static function pageKindRank(string $filename): int
    {
        return ['cover' => 0, 'content' => 1, 'last' => 2][self::pageKind($filename)];
    }

    /**
     * Номер з назви папки: «Опис 2» → «2», «Ф. Р-1 Губвиконком» → «Р-1», «Справа 15а» → «15а».
     * $prefixed — лише якщо назва починається зі слова «фонд», «справа» тощо (інакше null).
     */
    public static function number(string $segment, bool $prefixed = false): ?string
    {
        if (preg_match('/^\s*(?:ф(?:онд)?|оп(?:ис)?|спр(?:ава)?|д(?:ело)?|f(?:ond)?)\.?\s*№?\s*([А-ЯҐЄІЇA-Z]{0,3}-?\d+[а-яa-z]?)/iu', $segment, $m)) {
            return $m[1];
        }

        return $prefixed ? null : (preg_match('/(\d+[а-яa-z]?)/u', $segment, $m) ? $m[1] : $segment);
    }

    /** Рівень сегмента шляху за правилами: шаблон назви має пріоритет над глибиною. */
    private function levelOf(string $segment, int $depth): ?FolderLevel
    {
        foreach ($this->rules as $r) {
            if ($r->pattern && @preg_match('~'.$r->pattern.'~iu', $segment)) {
                return $r->level;
            }
        }
        foreach ($this->rules as $r) {
            if (! $r->pattern && $r->depth === $depth) {
                return $r->level;
            }
        }

        return null;
    }

    private function resolveBook(string $rel, int $jpgCount, SyncRun $run): ?Book
    {
        if ($book = Book::withTrashed()->where('folder_path', $rel)->first()) {
            if ($book->trashed()) {
                $book->restore();
            }

            return $book;
        }
        // Папку раніше вручну віднесли до архіву як справу
        $manual = UnmappedFolder::where('path', $rel)->first();
        if ($manual?->resolution === FolderLevel::Book && $manual->museum_id) {
            return $this->createBook($manual->museum_id, null, $rel, $run, $manual);
        }
        if ($manual?->resolution === FolderLevel::Skip) {
            return null;
        }

        $segments = explode('/', $rel);
        $territory = $museum = null;
        $fundParts = [];
        $inventory = null;
        foreach ($segments as $depth => $seg) {
            $isLast = $depth === count($segments) - 1;
            $level = $isLast ? FolderLevel::Book : $this->levelOf($seg, $depth);
            if ($level === FolderLevel::Skip && $isLast) {
                return null;
            }
            $path = implode('/', array_slice($segments, 0, $depth + 1));
            match ($level) {
                FolderLevel::Territory => $territory = Territory::firstOrCreate(['folder_path' => $path], ['name' => $seg]),
                FolderLevel::Museum => $museum = Museum::withTrashed()->firstOrCreate(['folder_path' => $path], ['name' => $seg, 'territory_id' => $territory?->id]),
                FolderLevel::Fund => $fundParts[] = [$seg, $path],
                FolderLevel::Inventory => $inventory = self::number($seg),
                default => null,
            };
        }
        if (! $museum) {
            $folder = UnmappedFolder::updateOrCreate(['path' => $rel], ['jpg_count' => $jpgCount, 'sync_run_id' => $run->id]);
            if (! $folder->resolution) {
                $this->report['unmapped'][] = $rel;
                $run->unmapped_folders++;
            }

            return null;
        }
        $fund = null;
        if ($fundParts) {
            [$name, $path] = end($fundParts);
            $fund = Fund::firstOrCreate(['folder_path' => $path], ['museum_id' => $museum->id, 'name' => $name, 'code' => self::number($name, true)]);
        }

        return $this->createBook($museum->id, $fund?->id, $rel, $run, null, $inventory);
    }

    private function createBook(int $museumId, ?int $fundId, string $rel, SyncRun $run, ?UnmappedFolder $manual = null, ?string $inventory = null): Book
    {
        $title = Str::afterLast($rel, '/') ?: $rel;
        $book = Book::create([
            'museum_id' => $museumId,
            'fund_id' => $fundId,
            'inventory_no' => $inventory,
            'book_number' => self::number($title, true),
            'title' => $title,
            'folder_path' => $rel,
        ]);
        $manual?->update(['book_id' => $book->id]);
        $run->new_books++;

        return $book;
    }

    private function syncFiles(Book $book, string $rel, array $files, bool $withChecksum, SyncRun $run): void
    {
        $existing = Scan::where('book_id', $book->id)->get()->keyBy('path');
        $maxOrder = (int) $existing->max('page_order');
        foreach ($files as $i => $name) {
            $path = $rel.'/'.$name;
            $abs = $this->root.'/'.$path;
            try {
                $size = filesize($abs);
                $mtime = filemtime($abs);
                $scan = $existing[$path] ?? Scan::where('path', $path)->first();
                $checksum = $withChecksum ? hash_file('sha256', $abs) : null;
                $dims = @getimagesize($abs);
                $status = $dims ? FileStatus::Present : FileStatus::Corrupted;

                if (! $scan) {
                    Scan::create([
                        'book_id' => $book->id, 'path' => $path, 'filename' => $name,
                        'page_order' => $existing->isEmpty() ? $i + 1 : ++$maxOrder,
                        'page_kind' => self::pageKind($name),
                        'file_size' => $size, 'file_mtime' => date('Y-m-d H:i:s', $mtime), 'checksum' => $checksum,
                        'width' => $dims[0] ?? null, 'height' => $dims[1] ?? null,
                        'file_status' => $status, 'last_seen_at' => now(),
                    ]);
                    $run->new_scans++;
                    $this->report['new'][] = $path;
                } else {
                    $changed = $scan->file_size !== $size || $scan->file_mtime?->timestamp !== $mtime
                        || ($checksum && $scan->checksum && $scan->checksum !== $checksum);
                    if ($scan->file_status === FileStatus::Missing) {
                        $run->restored_scans++;
                        $this->report['restored'][] = $path;
                    }
                    if ($changed) {
                        $run->changed_scans++;
                        $this->report['changed'][] = $path;
                    }
                    $scan->fill([
                        'file_size' => $size, 'file_mtime' => date('Y-m-d H:i:s', $mtime),
                        'checksum' => $checksum ?? $scan->checksum,
                        'width' => $dims[0] ?? $scan->width, 'height' => $dims[1] ?? $scan->height,
                        'file_status' => $status, 'last_seen_at' => now(),
                        // змінений скан — повторна перевірка пов’язаних результатів (ТЗ 4.2)
                        'needs_recheck' => $scan->needs_recheck || ($changed && $scan->recognized_at !== null),
                    ])->save();
                }
                if ($status === FileStatus::Corrupted) {
                    $run->corrupted_scans++;
                    $this->report['corrupted'][] = $path;
                }
            } catch (\Throwable $e) {
                $this->report['errors'][] = $path.': '.$e->getMessage();
            }
        }
        $this->touchedBooks[$book->id] = true;
    }
}
