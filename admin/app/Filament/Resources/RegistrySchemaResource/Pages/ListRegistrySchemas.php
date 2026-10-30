<?php

namespace App\Filament\Resources\RegistrySchemaResource\Pages;

use App\Filament\Resources\RegistrySchemaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRegistrySchemas extends ListRecords
{
    protected static string $resource = RegistrySchemaResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
