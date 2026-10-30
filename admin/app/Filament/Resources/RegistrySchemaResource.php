<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RegistrySchemaResource\Pages;
use App\Filament\Resources\RegistrySchemaResource\RelationManagers\FieldsRelationManager;
use App\Filament\Support\Access;
use App\Models\RegistrySchema;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Цільова схема реєстру: поля шаблону імпорту, обов’язковість, типи (ТЗ 9). */
class RegistrySchemaResource extends Resource
{
    use Access;

    protected static function viewAccess(): string
    {
        return 'staff';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = RegistrySchema::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|\UnitEnum|null $navigationGroup = 'Адаптація й експорт';

    protected static ?string $modelLabel = 'схема реєстру';

    protected static ?string $pluralModelLabel = 'Схема реєстру';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make()->columnSpanFull()->columns(3)->schema([
            TextInput::make('name')->label('Назва')->required(),
            TextInput::make('version')->label('Версія')->required(),
            Toggle::make('is_active')->label('Активна')->inline(false),
            Textarea::make('description')->label('Опис')->rows(2)->columnSpanFull(),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Схема')->weight('medium'),
            TextColumn::make('version')->label('Версія'),
            IconColumn::make('is_active')->label('Активна')->boolean(),
            TextColumn::make('fields_count')->label('Полів')->counts('fields'),
            TextColumn::make('rules_count')->label('Правил')->counts('rules'),
        ])->recordActions([EditAction::make()->label('')]);
    }

    public static function getRelations(): array
    {
        return [FieldsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRegistrySchemas::route('/'),
            'create' => Pages\CreateRegistrySchema::route('/create'),
            'edit' => Pages\EditRegistrySchema::route('/{record}/edit'),
        ];
    }
}
