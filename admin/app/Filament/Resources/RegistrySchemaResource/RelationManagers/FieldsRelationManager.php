<?php

namespace App\Filament\Resources\RegistrySchemaResource\RelationManagers;

use App\Models\Dictionary;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FieldsRelationManager extends RelationManager
{
    public const TYPES = ['string' => 'Рядок', 'text' => 'Текст', 'date' => 'Дата', 'year' => 'Рік', 'number' => 'Число', 'money' => 'Сума, грн', 'bool' => 'Так/ні', 'dictionary' => 'Довідник'];

    protected static string $relationship = 'fields';

    protected static ?string $title = 'Поля шаблону';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')->label('Ключ')->required(),
            TextInput::make('label')->label('Назва в шаблоні')->required(),
            TextInput::make('api_path')->label('Шлях в API реєстру'),
            Select::make('type')->label('Тип')->options(self::TYPES)->required()->default('string'),
            Select::make('dictionary_id')->label('Довідник')->options(fn () => Dictionary::pluck('name', 'id')),
            TextInput::make('group')->label('Група'),
            TextInput::make('column_letter')->label('Колонка'),
            TextInput::make('sort')->label('Порядок')->numeric()->default(0),
            Toggle::make('required')->label('Обов’язкове'),
            Textarea::make('hint')->label('Підказка')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('sort')->reorderable('sort')->paginated([50, 100])->columns([
            TextColumn::make('column_letter')->label('Кол.')->fontFamily('mono'),
            TextColumn::make('label')->label('Поле')->wrap()->weight('medium')->description(fn ($record) => $record->api_path),
            TextColumn::make('key')->label('Ключ')->fontFamily('mono')->size('xs'),
            TextColumn::make('type')->label('Тип')->formatStateUsing(fn ($state) => self::TYPES[$state] ?? $state)->badge()->color('gray'),
            TextColumn::make('dictionary.name')->label('Довідник')->placeholder('—'),
            IconColumn::make('required')->label('Обов.')->boolean(),
        ])->headerActions([CreateAction::make()])->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
