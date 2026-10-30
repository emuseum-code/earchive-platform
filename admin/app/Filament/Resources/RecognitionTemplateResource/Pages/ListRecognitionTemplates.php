<?php

namespace App\Filament\Resources\RecognitionTemplateResource\Pages;

use App\Filament\Resources\RecognitionTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRecognitionTemplates extends ListRecords
{
    protected static string $resource = RecognitionTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
