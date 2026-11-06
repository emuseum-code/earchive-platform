<?php

namespace App\Filament\Resources\ExportResource\Pages;

use App\Filament\Resources\ExportResource;
use App\Filament\Resources\RecordResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;

class ListExports extends ListRecords
{
    protected static string $resource = ExportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('registry')->label('Експорт готових документів у РМФУ')->icon('heroicon-o-arrow-up-tray')
                ->schema([Select::make('format')->label('Формат')->options(RecordResource::exportFormats())->default('registry')->required()])
                ->action(fn (array $data) => RecordResource::startExport($data['format'], $data['format'] === 'registry' ? ['export_status' => ['ready']] : [])),
        ];
    }
}
