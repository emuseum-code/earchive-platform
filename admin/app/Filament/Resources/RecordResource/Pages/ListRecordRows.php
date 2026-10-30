<?php

namespace App\Filament\Resources\RecordResource\Pages;

use App\Enums\VerificationStatus;
use App\Filament\Resources\RecordResource;
use App\Models\Record;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRecordRows extends ListRecords
{
    protected static string $resource = RecordResource::class;

    public function getTabs(): array
    {
        $count = fn (?VerificationStatus $s) => Record::visibleTo(auth()->user())->when($s, fn ($q) => $q->where('verification_status', $s->value))->count();

        return [
            'all' => Tab::make('Усі')->badge($count(null)),
            ...collect(VerificationStatus::cases())->mapWithKeys(fn ($s) => [$s->value => Tab::make($s->getLabel())->badge($count($s))->badgeColor($s->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('verification_status', $s->value))])->all(),
        ];
    }
}
