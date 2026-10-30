<?php

namespace App\Filament\Resources\RecognitionTemplateResource\Pages;

use App\Filament\Resources\RecognitionTemplateResource;
use Filament\Resources\Pages\EditRecord;

class EditRecognitionTemplate extends EditRecord
{
    protected static string $resource = RecognitionTemplateResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by'] = auth()->id();

        return $data;
    }

    protected function afterSave(): void
    {
        \App\Support\Activity::log('template.updated', $this->record, $this->record->name.' v'.$this->record->version);
    }
}
