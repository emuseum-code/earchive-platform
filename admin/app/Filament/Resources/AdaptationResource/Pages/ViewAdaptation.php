<?php

namespace App\Filament\Resources\AdaptationResource\Pages;

use App\Enums\JobType;
use App\Filament\Resources\AdaptationResource;
use App\Filament\Resources\RecordResource;
use App\Services\AdaptationService;
use App\Services\QueueManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/** Порівняння джерельних і адаптованих значень, ручне виправлення та підтвердження (ТЗ 9, 18.3 п.9). */
class ViewAdaptation extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AdaptationResource::class;

    protected string $view = 'filament.adaptations.view';

    public array $values = [];

    public int $lockVersion = 0;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(AdaptationResource::getEloquentQuery()->whereKey($this->record->id)->exists(), 403);
        $this->load();
    }

    protected function load(): void
    {
        $this->record->refresh();
        $this->values = collect($this->record->values)->map(fn ($v) => $v === null ? null : (string) $v)->all();
        $this->lockVersion = $this->record->lock_version;
    }

    public function getTitle(): string
    {
        return 'Адаптація запису № '.($this->record->record->record_no ?: $this->record->record_id);
    }

    public function getSubheading(): ?string
    {
        return 'v'.$this->record->version.' · '.$this->record->status->getLabel().' · схема '.$this->record->schema?->name.' '.$this->record->schema?->version;
    }

    public function canEdit(): bool
    {
        return (bool) auth()->user()?->canVerify() && $this->record->is_current;
    }

    public function save(): void
    {
        abort_unless($this->canEdit(), 403);
        $changed = collect($this->values)->filter(fn ($v, $k) => (string) ($this->record->values[$k] ?? '') !== (string) $v)
            ->map(fn ($v) => $v === '' ? null : $v)->all();
        if (! $changed) {
            return;
        }
        try {
            $this->record = app(AdaptationService::class)->updateValues($this->record, $changed, auth()->user(), $this->lockVersion);
        } catch (\DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        $this->load();
        Notification::make()->success()->title('Значення збережено')->send();
    }

    public function confirm(): void
    {
        abort_unless($this->canEdit(), 403);
        $this->save();
        try {
            app(AdaptationService::class)->confirm($this->record->refresh(), auth()->user());
        } catch (\DomainException $e) {
            Notification::make()->warning()->title('Не підтверджено')->body($e->getMessage())->send();

            return;
        }
        $this->load();
        Notification::make()->success()->title('Адаптацію підтверджено, запис готовий до експорту')->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record')->label('Джерельний запис')->icon('heroicon-o-document-text')->color('gray')
                ->url(RecordResource::getUrl('view', ['record' => $this->record->record_id])),
            Action::make('readapt')->label('Адаптувати повторно')->icon('heroicon-o-arrow-path')
                ->visible(fn () => auth()->user()->canAdaptExport())->requiresConfirmation()
                ->action(function () {
                    app(QueueManager::class)->enqueue(JobType::Adapt, $this->record->record, auth()->user());
                    Notification::make()->success()->title('Адаптацію поставлено в чергу')->send();
                }),
        ];
    }
}
