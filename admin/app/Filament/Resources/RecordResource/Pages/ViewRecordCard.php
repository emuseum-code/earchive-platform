<?php

namespace App\Filament\Resources\RecordResource\Pages;

use App\Filament\Resources\RecordResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** Картка запису: значення, джерельні сторінки, історія змін, зауваження, адаптація (ТЗ 7). */
class ViewRecordCard extends ViewRecord
{
    protected static string $resource = RecordResource::class;

    protected string $view = 'filament.records.view';

    public function getTitle(): string
    {
        return 'Запис № '.($this->record->record_no ?: $this->record->id);
    }

    public function getSubheading(): ?string
    {
        return $this->record->book?->title.' · '.$this->record->museum?->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('review')->label('Провести звірку')->icon('heroicon-o-document-magnifying-glass')
                ->url(RecordResource::getUrl('review', ['record' => $this->record])),
            EditAction::make()->label('Редагувати')->color('gray'),
        ];
    }
}
