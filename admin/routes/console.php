<?php

use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

// Синхронізація за розкладом, якщо її ввімкнено в налаштуваннях сховища (ТЗ 4.2)
Schedule::command('archive:sync --scheduled')->hourly()->withoutOverlapping()
    ->when(fn () => Settings::get('storage.schedule') === 'hourly');
Schedule::command('archive:sync --scheduled')->dailyAt('02:00')->withoutOverlapping()
    ->when(fn () => Settings::get('storage.schedule') === 'daily');
Schedule::command('archive:sync --scheduled')->weeklyOn(0, '03:00')->withoutOverlapping()
    ->when(fn () => Settings::get('storage.schedule') === 'weekly');

// Відновлення черги: завислі завдання повертаються, невиконані перевідправляються воркеру
Schedule::command('archive:recover')->everyTenMinutes()->withoutOverlapping();

// Пароль демо-користувачів *@archive.test = ADMIN_PASSWORD (запускається при кожному деплої)
Artisan::command('archive:demo-password', function () {
    $password = trim((string) env('ADMIN_PASSWORD'));
    if ($password === '') {
        $this->warn('ADMIN_PASSWORD is empty, demo passwords unchanged');

        return;
    }
    foreach (User::where('email', 'like', '%@archive.test')->get() as $user) {
        $same = Hash::check($password, $user->password);
        if (! $same) {
            $user->forceFill(['password' => $password, 'is_active' => true])->save();
        }
        $this->line($user->email.': '.($same ? 'password ok' : 'password reset').' (length '.mb_strlen($password).')');
    }
})->purpose('Встановити пароль демо-користувачів з ADMIN_PASSWORD');
