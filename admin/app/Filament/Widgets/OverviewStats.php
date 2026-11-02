<?php

namespace App\Filament\Widgets;

use App\Models\Book;
use App\Models\Museum;
use App\Models\Record;
use App\Models\Scan;
use App\Support\Backups;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Огляд прогресу в межах доступу користувача (ТЗ 10). */
class OverviewStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $u = auth()->user();
        $books = Book::visibleTo($u);
        $b = (clone $books)->toBase()->selectRaw('count(*) c, coalesce(sum(scans_count),0) s, coalesce(sum(scans_recognized),0) r, coalesce(sum(scans_failed),0) f, coalesce(sum(scans_missing),0) m, coalesce(sum(records_count),0) rc, coalesce(sum(records_verified),0) rv, coalesce(sum(records_adapted),0) ra')->first();
        $records = Record::visibleTo($u);
        $pct = fn ($a, $t) => $t ? round($a * 100 / $t).'%' : '0%';

        $stats = [
            Stat::make('Музеїв', Museum::visibleTo($u)->count())->description(number_format($b->c, 0, ',', ' ').' книг')->icon('heroicon-o-building-library'),
            Stat::make('Сканів', number_format($b->s, 0, ',', ' '))->description($pct($b->r, $b->s - $b->m).' розпізнано')->icon('heroicon-o-photo'),
            Stat::make('Записів', number_format($b->rc, 0, ',', ' '))
                ->description(number_format($b->rv, 0, ',', ' ').' перевірено · '.(clone $records)->where('verification_status', 'needs_clarification')->count().' на уточненні')
                ->icon('heroicon-o-table-cells'),
            Stat::make('Адаптовано до РМФУ', number_format($b->ra, 0, ',', ' '))
                ->description((clone $records)->where('export_status', 'ready')->count().' готові до експорту · '.(clone $records)->where('adaptation_status', 'stale')->count().' потребують оновлення')
                ->icon('heroicon-o-arrows-right-left'),
            Stat::make('Проблемні сторінки', number_format($b->f + $b->m, 0, ',', ' '))
                ->description($b->f.' помилок розпізнавання · '.$b->m.' недоступних · '.Scan::visibleTo($u)->where('needs_recheck', true)->count().' змінених')
                ->color($b->f + $b->m ? 'danger' : null)->icon('heroicon-o-exclamation-triangle'),
        ];
        if ($u->isAdmin()) {
            $last = Backups::last();
            $stats[] = Stat::make('Резервна копія бази', $last ? \App\Support\Tz::fmt($last['time'], 'd.m H:i') : 'ще немає')
                ->description(Backups::healthy() ? 'перевірено, зберігаються 14 днів' : 'потрібна увага: див. Налаштування → Резервні копії')
                ->color(Backups::healthy() ? 'success' : 'danger')->icon('heroicon-o-circle-stack')
                ->url(\App\Filament\Pages\Settings::getUrl());
        }

        return $stats;
    }
}
