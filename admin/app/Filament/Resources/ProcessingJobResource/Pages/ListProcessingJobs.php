<?php

namespace App\Filament\Resources\ProcessingJobResource\Pages;

use App\Enums\JobStatus;
use App\Filament\Resources\ProcessingJobResource;
use App\Filament\Widgets\QueueStats;
use App\Models\ProcessingJob;
use App\Services\QueueManager;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProcessingJobs extends ListRecords
{
    protected static string $resource = ProcessingJobResource::class;

    protected function getHeaderWidgets(): array
    {
        return [QueueStats::class];
    }

    public function getTabs(): array
    {
        $tab = fn (string $label, array $statuses) => Tab::make($label)
            ->badge(ProcessingJob::whereIn('status', $statuses)->count())
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $statuses));

        return [
            'active' => $tab('Активні', [JobStatus::Queued->value, JobStatus::Running->value, JobStatus::Paused->value]),
            'failed' => $tab('Помилки', [JobStatus::Failed->value]),
            'done' => $tab('Виконані', [JobStatus::Done->value]),
            'all' => Tab::make('Усі'),
        ];
    }

    protected function getHeaderActions(): array
    {
        $paused = (bool) Settings::get('queue.paused');

        return [
            Action::make('pause')->label($paused ? 'Відновити обробку' : 'Призупинити обробку')
                ->icon($paused ? 'heroicon-o-play' : 'heroicon-o-pause')->color($paused ? 'success' : 'warning')
                ->requiresConfirmation()
                ->modalDescription($paused ? null : 'Нові завдання розпізнавання не стартуватимуть, поки обробку не відновлять. Інтерактивна «Перевірити AI» працює.')
                ->action(function () use ($paused) {
                    $paused ? app(QueueManager::class)->resumeAll() : app(QueueManager::class)->pauseAll();
                    Notification::make()->success()->title($paused ? 'Обробку відновлено' : 'Обробку призупинено')->send();
                }),
            Action::make('recover')->label('Відновити чергу')->icon('heroicon-o-lifebuoy')->color('gray')
                ->tooltip('Повернути завислі завдання та перевідправити невиконані воркеру')
                ->action(fn () => Notification::make()->success()->title('Перевідправлено: '.app(QueueManager::class)->recover())->send()),
        ];
    }
}
