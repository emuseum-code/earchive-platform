<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(): void
    {
        UserResource::logChange($this->record, 'user.created');
    }

    protected function afterCreate(): void
    {
        UserResource::logChange($this->record, 'user.created');
    }
}
