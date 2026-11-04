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

    /** Швидкі дії на головній: скани в потрібний музей і перехід до роботи з одним музеєм. */
    protected function getHeaderActions(): array
    {
        return [
            \App\Filament\Resources\BookResource::uploadAction()->label('Додати скани'),
            \Filament\Actions\Action::make('museumDesk')->label('Робота з музеєм')->icon('heroicon-o-building-library')->color('gray')
                ->url(MuseumDesk::getUrl()),
        ];
    }

    public function getWidgets(): array
    {
        $w = [OverviewStats::class];
        if (auth()->user()->canOperate()) {
            $w[] = QueueStats::class;
        }
        $w[] = BooksProgress::class;
        if (auth()->user()->isAdmin()) {
            $w[] = \App\Filament\Widgets\VerifierActivity::class;
        }

        return $w;
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
