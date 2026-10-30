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
            TextInput::make('folder_path')->label('Папка'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Назва'),
            TextColumn::make('code')->label('Шифр'),
            TextColumn::make('books_count')->label('Книг')->counts('books'),
            TextColumn::make('folder_path')->label('Папка')->fontFamily('mono')->size('xs'),
        ])->headerActions([CreateAction::make()])->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
