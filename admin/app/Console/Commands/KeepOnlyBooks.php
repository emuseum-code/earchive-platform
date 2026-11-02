<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\Export;
use App\Models\ProcessingJob;
use App\Models\Scan;
use App\Models\UnmappedFolder;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Прибрати тестові дані: залишити лише вказані книги (за назвою) з їхніми сканами, записами, звіркою й адаптаціями.
 * Видаляє інші книги разом з їхніми файлами в сховищі, експорти, завдання черги без цілі та невдалі завдання,
 * і вимикає повторне копіювання демо-сканів (books:storage-init).
 * Запускається один раз під час деплою (прапорець data.cleaned_keep); вручну: books:keep-only Test2 --force.
 */
class KeepOnlyBooks extends Command
{
    protected $signature = 'books:keep-only {titles* : Назви книг, які залишити} {--force : Виконати, навіть якщо вже виконувалося} {--dry-run : Лише показати, що буде видалено}';

    protected $description = 'Видалити всі книги, крім вказаних, і прибрати тестові дані';

    public function handle(): int
    {
        $titles = $this->argument('titles');
        $flag = 'data.cleaned_keep';
        if (! $this->option('force') && ! $this->option('dry-run') && Settings::get($flag)) {
            $this->info('Очищення вже виконано ('.json_encode(Settings::get($flag), JSON_UNESCAPED_UNICODE).').');

            return self::SUCCESS;
        }

        $keep = Book::whereIn('title', $titles)->get();
        if ($keep->isEmpty()) {
            $this->warn('Книг з такими назвами не знайдено, нічого не видаляю: '.implode(', ', $titles));

            return self::SUCCESS;
        }
        $keepIds = $keep->pluck('id')->all();
        $keepPaths = Scan::whereIn('book_id', $keepIds)->pluck('path')->flip();
        $drop = Book::whereNotIn('id', $keepIds)->get();

        $this->info('Залишаю: '.$keep->map(fn ($record) => "{$record->title} (#{$record->id}, сканів ".Scan::where('book_id', $record->id)->count().')')->implode('; '));
        $this->info('Видаляю книг: '.$drop->count().' ('.$drop->pluck('title')->implode('; ').')');
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $root = rtrim((string) Settings::get('storage.root'), '/');
        $managed = $root !== '' && in_array($root, [storage_path('app/scans'), storage_path('demo-scans')], true);
        $files = 0;

        DB::transaction(function () use ($drop, $keepIds, $keepPaths, $managed, &$files) {
            foreach ($drop as $book) {
                // файли видаляємо лише у власному сховищі сайту; справжнє сховище музею не чіпаємо
                if ($managed) {
                    foreach (Scan::where('book_id', $book->id)->get() as $scan) {
                        if (! isset($keepPaths[$scan->path]) && is_file($scan->absolutePath())) {
                            @unlink($scan->absolutePath());
                            $files++;
                        }
                    }
                }
            }
            $this->deleteBooks($drop->pluck('id')->all());

            // експорти, у яких не лишилося записів
            foreach (Export::whereDoesntHave('items')->get() as $export) {
                if ($export->file_path) {
                    Storage::disk('local')->delete($export->file_path);
                }
                $export->delete();
            }

            // завдання черги: невдалі/скасовані та ті, чия ціль уже видалена
            ProcessingJob::whereIn('status', ['failed', 'cancelled'])->delete();
            foreach (['book' => Book::class, 'scan' => Scan::class, 'record' => \App\Models\Record::class, 'export' => Export::class] as $type => $class) {
                ProcessingJob::where('target_type', $type)->whereNotIn('target_id', $class::query()->select('id'))->delete();
            }
            UnmappedFolder::query()->delete();
        });

        // демо-файли, які storage-init копіював у сховище, і порожні папки
        if ($managed) {
            foreach ([storage_path('demo-scans'), storage_path('sample-scans')] as $src) {
                if (! is_dir($src)) {
                    continue;
                }
                foreach (File::allFiles($src) as $f) {
                    $rel = $f->getRelativePathname();
                    if (! isset($keepPaths[$rel]) && is_file($root.'/'.$rel)) {
                        @unlink($root.'/'.$rel);
                        $files++;
                    }
                }
            }
            $this->removeEmptyDirs($root);
        }

        Settings::set($flag, ['keep' => $keep->pluck('title')->all(), 'at' => now()->toDateTimeString()]);
        Settings::set('storage.demo_disabled', true);
        $this->info("Готово. Видалено книг: {$drop->count()}, файлів: {$files}.");

        return self::SUCCESS;
    }

    /** Явне видалення залежних рядків (не покладаємося на каскад: у SQLite зовнішні ключі можуть бути вимкнені). */
    private function deleteBooks(array $bookIds): void
    {
        foreach (array_chunk($bookIds, 500) as $books) {
            $records = DB::table('records')->whereIn('book_id', $books)->pluck('id')->all();
            $scans = DB::table('scans')->whereIn('book_id', $books)->pluck('id')->all();
            foreach (array_chunk($records, 500) as $ids) {
                $checks = DB::table('ai_checks')->whereIn('record_id', $ids)->pluck('id')->all();
                DB::table('ai_check_proposals')->whereIn('ai_check_id', $checks)->orWhereIn('record_id', $ids)->delete();
                foreach (['export_items', 'adaptations', 'review_remarks', 'ai_checks', 'record_versions', 'record_pages'] as $t) {
                    DB::table($t)->whereIn('record_id', $ids)->delete();
                }
            }
            foreach (array_chunk($scans, 500) as $ids) {
                foreach (['record_pages', 'recognition_results'] as $t) {
                    DB::table($t)->whereIn('scan_id', $ids)->delete();
                }
            }
            foreach (['review_remarks', 'recognition_results', 'records', 'scans', 'book_user'] as $t) {
                DB::table($t)->whereIn('book_id', $books)->delete();
            }
            DB::table('unmapped_folders')->whereIn('book_id', $books)->update(['book_id' => null]);
            DB::table('books')->whereIn('id', $books)->delete();
        }
    }

    private function removeEmptyDirs(string $dir): bool
    {
        $empty = true;
        foreach (File::directories($dir) as $sub) {
            $empty = $this->removeEmptyDirs($sub) && $empty;
        }
        if (count(File::files($dir, true)) > 0) {
            $empty = false;
        }
        if ($empty && $dir !== $this->rootDir()) {
            @rmdir($dir);
        }

        return $empty;
    }

    private function rootDir(): string
    {
        return rtrim((string) Settings::get('storage.root'), '/');
    }
}
