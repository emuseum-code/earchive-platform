<?php

namespace App\Filament\Resources\DictionaryResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Значення';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('value')->label('Значення реєстру')->required()->columnSpanFull(),
            TextInput::make('code')->label('Код'),
            TextInput::make('sort')->label('Порядок')->numeric()->default(0),
            TagsInput::make('synonyms')->label('Як записано в книгах')->columnSpanFull()
                ->helperText('Варіанти з книг, які зіставляються з цим значенням: «хорошее», «удовлетворит.» …'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('sort')->columns([
            TextColumn::make('value')->label('Значення')->searchable()->weight('medium'),
            TextColumn::make('code')->label('Код'),
            TextColumn::make('synonyms')->label('Синоніми з книг')->badge()->color('gray'),
        ])->headerActions([CreateAction::make()])->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
