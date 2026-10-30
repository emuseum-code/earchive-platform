<?php

namespace App\Filament\Resources\AdaptationRuleResource\Pages;

use App\Filament\Resources\AdaptationRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdaptationRules extends ListRecords
{
    protected static string $resource = AdaptationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
