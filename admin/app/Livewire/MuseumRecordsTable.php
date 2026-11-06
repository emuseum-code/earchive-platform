<?php

namespace App\Livewire;

use App\Filament\Resources\RecordResource;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;

/**
 * Таблиця документів одного архіву всередині «Єдиної таблиці» (розгорнутий архів).
 * Ті самі колонки, фільтри, пошук і масові дії, що й у RecordResource, лише в межах архіву.
 */
class MuseumRecordsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public int $museumId;

    public function table(Table $table): Table
    {
        return RecordResource::table($table)
            ->query(fn () => RecordResource::getEloquentQuery()->where('museum_id', $this->museumId));
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div>{{ $this->table }}</div>
        BLADE;
    }
}
