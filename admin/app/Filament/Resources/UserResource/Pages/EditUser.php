<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function afterSave(): void
    {
        UserResource::logChange($this->record, 'user.updated');
    }

    protected function afterCreate(): void
    {
        UserResource::logChange($this->record, 'user.updated');
    }
}
