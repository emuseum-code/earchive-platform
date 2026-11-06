<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** Стан резервних копій бази: їх робить контейнер archive-backup у storage/backups (ТЗ 12, ТВ-13). */
class Backups
{
    public static function dir(): string
    {
        return storage_path('backups');
    }

    /** @return array{time: ?Carbon, file: ?string, size: int, status: string, check: ?string, users: ?string, records: ?string}|null */
    public static function last(): ?array
    {
        $file = self::dir().'/last.json';
        if (! is_file($file) || ! ($j = json_decode((string) file_get_contents($file), true))) {
            return null;
        }
        $j['time'] = isset($j['time']) ? Carbon::parse($j['time'])->setTimezone(config('app.timezone')) : null;

        return $j;
    }

    /** @return list<array{name: string, size: int, time: Carbon}> */
    public static function files(): array
    {
        return collect(glob(self::dir().'/books-*.dump') ?: [])
            ->map(fn ($f) => ['name' => basename($f), 'size' => (int) filesize($f), 'time' => Carbon::createFromTimestamp(filemtime($f))->setTimezone(config('app.timezone'))])
            ->sortByDesc('time')->values()->all();
    }

    /** Остання копія свіжа (до 36 год) і перевірена. */
    public static function healthy(): bool
    {
        $last = self::last();

        return $last && $last['status'] === 'ok' && $last['time']?->gt(now()->subHours(36));
    }
}
