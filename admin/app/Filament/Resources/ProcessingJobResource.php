<?php

namespace App\Filament\Resources;

use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Filament\Resources\ProcessingJobResource\Pages;
use App\Filament\Support\Access;
use App\Models\ProcessingJob;
use App\Services\QueueManager;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Черга обробки: статус, прогрес, помилки, пауза, скасування, повтор (ТЗ 6.3, 18.2). */
class ProcessingJobResource extends Resource
{
    use Access;

    protected static function viewAccess(): string
    {
        return 'operate';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = ProcessingJob::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|\UnitEnum|null $navigationGroup = 'Розпізнавання';

    protected static ?string $modelLabel = 'завдання';

    protected static ?string $pluralModelLabel = 'Черга обробки';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $n = ProcessingJob::whereIn('status', [JobStatus::Queued->value, JobStatus::Running->value])->count();

        return $n ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['target', 'user']);
    }

    public static function table(Table $table): Table
    {
        $q = fn () => app(QueueManager::class);

        return $table
            ->defaultSort('id', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('id')->label('№')->alignEnd(),
                TextColumn::make('type')->label('Тип')->badge(),
                TextColumn::make('target_label')->label('Об’єкт')->state(fn (ProcessingJob $j) => $j->targetLabel())->wrap(),
                TextColumn::make('status')->label('Стан')->badge(),
                TextColumn::make('attempts')->label('Спроб')->formatStateUsing(fn ($state, ProcessingJob $j) => $state.' / '.$j->max_attempts)->alignEnd(),
                TextColumn::make('error')->label('Помилка')->wrap()->lineClamp(2)->color('danger')->placeholder('—'),
                TextColumn::make('user.name')->label('Запустив')->toggleable(),
                TextColumn::make('created_at')->label('Створено')->dateTime('d.m H:i:s')->sortable(),
                TextColumn::make('duration')->label('Тривалість')->state(fn (ProcessingJob $j) => $j->started_at && $j->finished_at ? $j->started_at->diffInSeconds($j->finished_at).' с' : null)
                    ->placeholder('—')->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Стан')->options(JobStatus::options())->multiple(),
                SelectFilter::make('type')->label('Тип')->options(JobType::options()),
            ])
            ->recordActions([
                Action::make('cancel')->label('Скасувати')->icon('heroicon-o-x-mark')->color('gray')
                    ->visible(fn (ProcessingJob $j) => in_array($j->status, [JobStatus::Queued, JobStatus::Paused], true))
                    ->action(fn (ProcessingJob $j) => $q()->cancel($j)),
                Action::make('retry')->label('Повторити')->icon('heroicon-o-arrow-path')
                    ->visible(fn (ProcessingJob $j) => in_array($j->status, [JobStatus::Failed, JobStatus::Cancelled], true))
                    ->action(fn (ProcessingJob $j) => $q()->retry($j)),
            ])
            ->toolbarActions([
                BulkAction::make('cancel')->label('Скасувати невиконані')->icon('heroicon-o-x-mark')->requiresConfirmation()
                    ->action(fn (Collection $jobs) => $jobs->each(fn ($j) => $q()->cancel($j))),
                BulkAction::make('retry')->label('Повторити помилкові')->icon('heroicon-o-arrow-path')
                    ->action(fn (Collection $jobs) => $jobs->each(fn ($j) => $q()->retry($j))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListProcessingJobs::route('/')];
    }
}
