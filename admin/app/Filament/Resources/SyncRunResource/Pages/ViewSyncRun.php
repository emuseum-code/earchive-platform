<?php

namespace App\Filament\Resources\SyncRunResource\Pages;

use App\Filament\Resources\SyncRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSyncRun extends ViewRecord
{
    protected static string $resource = SyncRunResource::class;

    public function getTitle(): string
    {
        return 'Звіт синхронізації № '.$this->record->id;
    }
}
