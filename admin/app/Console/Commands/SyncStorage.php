<?php

namespace App\Console\Commands;

use App\Models\SyncRun;
use App\Services\StorageSync;
use App\Support\Activity;
use Illuminate\Console\Command;

/** Синхронізація сховища з консолі та за розкладом (ТЗ 4.2). */
class SyncStorage extends Command
{
    protected $signature = 'books:sync {path? : Папка відносно кореневої директорії} {--checksum : Рахувати SHA-256} {--scheduled}';

    protected $description = 'Знайти нові, змінені й недоступні скани';

    public function handle(StorageSync $sync): int
    {
        $run = SyncRun::create(['scope_path' => (string) $this->argument('path'), 'with_checksum' => $this->option('checksum'),
            'scheduled' => $this->option('scheduled'), 'status' => 'queued']);
        Activity::log('sync.started', $run, 'Консоль: '.($run->scope_path ?: 'усе сховище'));
        $run = $sync->run($run);
        $this->table(['Папок', 'Файлів', 'Нових справ', 'Нових сканів', 'Змінених', 'Недоступних', 'Невизначених папок', 'Помилок'],
            [array_map(fn ($v) => (int) $v, [$run->folders_found, $run->files_found, $run->new_books, $run->new_scans, $run->changed_scans, $run->missing_scans, $run->unmapped_folders, $run->errors_count])]);
        foreach ($run->report['errors'] ?? [] as $e) {
            $this->error($e);
        }

        return $run->errors_count ? self::FAILURE : self::SUCCESS;
    }
}
