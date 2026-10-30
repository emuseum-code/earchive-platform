<?php

use App\Support\Settings;
use Illuminate\Support\Facades\Schedule;

// Синхронізація за розкладом, якщо її ввімкнено в налаштуваннях сховища (ТЗ 4.2)
Schedule::command('books:sync --scheduled')->hourly()->withoutOverlapping()
    ->when(fn () => Settings::get('storage.schedule') === 'hourly');
Schedule::command('books:sync --scheduled')->dailyAt('02:00')->withoutOverlapping()
    ->when(fn () => Settings::get('storage.schedule') === 'daily');
Schedule::command('books:sync --scheduled')->weeklyOn(0, '03:00')->withoutOverlapping()
    ->when(fn () => Settings::get('storage.schedule') === 'weekly');

// Відновлення черги: завислі завдання повертаються, невиконані перевідправляються воркеру
Schedule::command('books:recover')->everyTenMinutes()->withoutOverlapping();
