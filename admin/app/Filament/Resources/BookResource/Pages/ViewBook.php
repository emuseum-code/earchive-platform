<?php

namespace App\Filament\Resources\BookResource\Pages;

use App\Filament\Resources\BookResource;
use App\Filament\Resources\RecordResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/** Картка книги (ТЗ 4.1): статуси, прогрес, сторінки. */
class ViewBook extends ViewRecord
{
    protected static string $resource = BookResource::class;

    public function getTitle(): string
    {
        return $this->record->title;
    }

    protected function getHeaderActions(): array
    {
        $u = auth()->user();

        return [
            BookResource::reviewAction()->record($this->record),
            Action::make('records')->label('Записи книги')->icon('heroicon-o-table-cells')->color('gray')
                ->url(RecordResource::getUrl('index', ['filters' => ['book_id' => ['value' => $this->record->id]]])),
            Action::make('pages')->label('Переглянути сторінки')->icon('heroicon-o-photo')->color('gray')
                ->url(BookResource::getUrl('pages', ['record' => $this->record])),
            BookResource::uploadAction($this->record),
            ActionGroup::make([
                Action::make('recognize')->label('Розпізнати нові та помилкові сторінки')->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()->action(fn () => BookResource::recognize([$this->record])),
                Action::make('recognizeAll')->label('Розпізнати всю книгу повторно')->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()->modalDescription('Кожна сторінка отримає нову версію результату. Ручні правки не перезаписуються: зміни прийдуть як пропозиції.')
                    ->action(fn () => BookResource::recognize([$this->record], false)),
                Action::make('refresh')->label('Перерахувати лічильники')->icon('heroicon-o-calculator')
                    ->action(fn () => $this->record->refreshCounters()),
            ])->label('Обробка')->button()->color('gray')->visible($u->canOperate()),
            EditAction::make(),
        ];
    }
}
