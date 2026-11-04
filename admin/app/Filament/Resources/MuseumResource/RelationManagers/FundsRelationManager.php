<?php

namespace App\Filament\Resources\MuseumResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FundsRelationManager extends RelationManager
{
    protected static string $relationship = 'funds';

    protected static ?string $title = 'Напрями обліку / фонди';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Назва')->required(),
            TextInput::make('code')->label('Шифр'),
            TextInput::make('planned_books')->label('Книг за переліком')->numeric()->minValue(0)
                ->helperText('Скільки інвентарних книг у групі за переліком музею: для прогресу «оцифровано X з N».'),
            TextInput::make('folder_path')->label('Папка'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('name')->defaultPaginationPageOption(25)->columns([
            TextColumn::make('name')->label('Назва'),
            TextColumn::make('code')->label('Шифр'),
            TextColumn::make('books_count')->label('Оцифровано книг')->counts('books')
                ->formatStateUsing(fn ($state, $record) => $record->planned_books ? "{$state} з {$record->planned_books}" : (string) $state)
                ->badge()->color(fn ($state, $record) => ! $record->planned_books ? 'gray' : ($state >= $record->planned_books ? 'success' : ($state > 0 ? 'warning' : 'danger'))),
            TextColumn::make('folder_path')->label('Папка')->fontFamily('mono')->size('xs'),
        ])->description(function () {
            $funds = $this->getOwnerRecord()->funds()->withCount('books')->get();
            $planned = (int) $funds->sum('planned_books');

            return $planned ? 'Оцифровано '.$funds->sum(fn ($f) => min($f->books_count, $f->planned_books ?? $f->books_count))." з {$planned} книг за переліком" : null;
        })->headerActions([CreateAction::make()])->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
