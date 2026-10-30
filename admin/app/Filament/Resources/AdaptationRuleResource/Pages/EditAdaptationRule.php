<?php

namespace App\Filament\Resources\AdaptationRuleResource\Pages;

use App\Filament\Resources\AdaptationRuleResource;
use Filament\Resources\Pages\EditRecord;

class EditAdaptationRule extends EditRecord
{
    protected static string $resource = AdaptationRuleResource::class;

    protected function afterSave(): void
    {
        AdaptationRuleResource::logSaved($this->record);
    }
}
