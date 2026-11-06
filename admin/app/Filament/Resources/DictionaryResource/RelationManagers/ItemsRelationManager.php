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
            TextInput::make('value_en')->label('Англійською')->columnSpanFull(),
            TextInput::make('code')->label('ID у реєстрі'),
            TextInput::make('sort')->label('Порядок')->numeric()->default(0),
            TagsInput::make('synonyms')->label('Як записано в справах')->columnSpanFull()
                ->helperText('Варіанти з справ, які зіставляються з цим значенням: «хорошее», «удовлетворит.» …'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('sort')->modifyQueryUsing(fn ($query) => $query->with('parent'))->columns([
            TextColumn::make('value')->label('Значення')->searchable()->weight('medium')
                ->description(fn ($record) => $record->is_group ? 'має підпункти' : null),
            TextColumn::make('parent.value')->label('Батьківський термін')->color('gray'),
            TextColumn::make('value_en')->label('EN')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('code')->label('ID у реєстрі')->searchable(),
            TextColumn::make('synonyms')->label('Синоніми з справ')->badge()->color('gray'),
        ])->headerActions([CreateAction::make()])->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
