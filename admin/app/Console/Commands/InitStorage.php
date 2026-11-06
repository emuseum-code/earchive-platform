<?php

namespace App\Console\Commands;

use App\Models\Scan;
use App\Services\StorageSync;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Сховище сканів у постійному томі (storage/app/scans), щоб скани, завантажені з браузера, переживали деплой.
 * Демо-скани з образу (storage/demo-scans) і зразки реальних сканів (storage/sample-scans) копіюються туди без перезапису.
 * Якщо коренева директорія вказує на чужу папку (справжнє сховище), команда нічого не змінює.
 */
class InitStorage extends Command
{
    protected $signature = 'archive:storage-init';

    protected $description = 'Перенести сховище сканів у постійний том і скопіювати демо-скани';

    public function handle(StorageSync $sync): int
    {
        $target = storage_path('app/scans');
        $root = rtrim((string) Settings::get('storage.root'), '/');
        if ($root !== '' && $root !== $target && $root !== storage_path('demo-scans') && $root !== storage_path('app/scans')) {
            $this->info("Коренева директорія {$root} задана вручну, не змінюю.");

            return self::SUCCESS;
        }
        File::ensureDirectoryExists($target);
        $before = Scan::count();
        // storage.demo_disabled = true: демо-скани більше не копіюються
        $demo = Settings::get('storage.demo_disabled') ? [] : [storage_path('demo-scans')];
        foreach ($demo as $src) {
            if (! is_dir($src)) {
                continue;
            }
            foreach (File::allFiles($src) as $f) {
                $dst = $target.'/'.$f->getRelativePathname();
                if (! file_exists($dst)) {
                    File::ensureDirectoryExists(dirname($dst));
                    copy($f->getPathname(), $dst);
                    touch($dst, $f->getMTime());
                }
            }
        }
        if ($root !== $target) {
            Settings::set('storage.root', $target);
            $this->info("Коренева директорія: {$target}");
        }

        return self::SUCCESS;
    }
}
