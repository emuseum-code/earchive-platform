<?php

namespace App\Console\Commands;

use App\Models\Scan;
use App\Models\SyncRun;
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
    protected $signature = 'books:storage-init';

    protected $description = 'Перенести сховище сканів у постійний том і додати зразки сканів';

    public function handle(StorageSync $sync): int
    {
        $target = storage_path('app/scans');
        $root = rtrim((string) Settings::get('storage.root'), '/');
        if ($root !== '' && $root !== $target && $root !== storage_path('demo-scans')) {
            $this->info("Коренева директорія {$root} задана вручну, не змінюю.");

            return self::SUCCESS;
        }
        File::ensureDirectoryExists($target);
        $before = Scan::count();
        // після очищення тестових даних (books:keep-only) демо-скани більше не копіюються
        $demo = Settings::get('storage.demo_disabled') ? [] : [storage_path('demo-scans'), storage_path('sample-scans')];
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
        // нові папки зразків (наприклад, реальні скани пілоту) — одразу в базу
        if ($demo && is_dir(storage_path('sample-scans'))) {
            $run = $sync->run(SyncRun::create(['scope_path' => '', 'status' => 'queued']));
            $this->info('Синхронізація: нових сканів '.(int) $run->new_scans.', усього було '.$before);
        }

        $this->lysychanskFunds();

        return self::SUCCESS;
    }

    /** Групи збереження Лисичанського музею з переліку Мінкульту (15 груп, 51 інвентарна книга). */
    private function lysychanskFunds(): void
    {
        $museum = \App\Models\Museum::where('name', 'like', 'Лисичанський%')->first();
        if (! $museum) {
            return;
        }
        // шифр => [назва, книг за переліком]; у переліку музею пункту 14 немає, за списком 48 книг із заявлених 51
        $groups = ['Д' => ['Документи', 15], 'ДР' => ['Деревина', 1], 'М' => ['Метал', 1], 'Р' => ['Різне', 2], 'В' => ['Птахи', 1],
            'ТК' => ['Тканина', 2], 'КСП' => ['Кераміка, скло, пластмаса', 1], 'ЗД' => ['Зображувальна діяльність', 1],
            'ЛГК' => ['Листівки, газети, книги', 12], 'ОМЗ' => ['Ордени, медалі, знаки', 6], 'Ф' => ['Фото', 2],
            'ПГЗ' => ['Паперово-грошові знаки', 2], 'А' => ['Археологія', 1], 'ЗС' => ['Зброя, спорядження', 1]];
        foreach ($groups as $code => [$name, $planned]) {
            $fund = \App\Models\Fund::where('museum_id', $museum->id)
                ->where(fn ($q) => $q->where('code', $code)->orWhere('name', 'like', "%({$code})"))->first();
            if ($fund) {
                $fund->code ??= $code;
                $fund->planned_books ??= $planned; // правку з адмінки не перезаписуємо
                $fund->save();
            } else {
                \App\Models\Fund::create(['museum_id' => $museum->id, 'code' => $code, 'name' => "{$name} ({$code})", 'planned_books' => $planned]);
            }
        }
    }
}
