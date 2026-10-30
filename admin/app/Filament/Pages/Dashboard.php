<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BooksProgress;
use App\Filament\Widgets\OverviewStats;
use App\Filament\Widgets\QueueStats;
use Filament\Pages\Dashboard as BaseDashboard;

/** Огляд: музеї, книги, скани, записи, черга та помилки (ТЗ 10). */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Огляд';

    public function getWidgets(): array
    {
        $w = [OverviewStats::class];
        if (auth()->user()->canOperate()) {
            $w[] = QueueStats::class;
        }
        $w[] = BooksProgress::class;

        return $w;
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
