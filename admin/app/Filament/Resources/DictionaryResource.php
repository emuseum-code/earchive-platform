<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DictionaryResource\Pages;
use App\Filament\Resources\DictionaryResource\RelationManagers\ItemsRelationManager;
use App\Filament\Support\Access;
use App\Models\Dictionary;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Довідники архівного опису для зіставлення значень (ТЗ 9). */
class DictionaryResource extends Resource
{
    use Access;

    protected static function viewAccess(): string
    {
        return 'staff';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = Dictionary::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|\UnitEnum|null $navigationGroup = 'Адаптація й експорт';

    protected static ?string $modelLabel = 'довідник';

    protected static ?string $pluralModelLabel = 'Довідники реєстру';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make()->columnSpanFull()->columns(3)->schema([
            TextInput::make('name')->label('Назва')->required(),
            TextInput::make('code')->label('Код')->required()->unique(ignoreRecord: true),
            TextInput::make('version')->label('Версія')->default('1'),
            Textarea::make('description')->label('Опис')->rows(2)->columnSpanFull(),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Довідник')->weight('medium')->searchable(),
            TextColumn::make('code')->label('Код')->fontFamily('mono'),
            TextColumn::make('version')->label('Версія'),
            TextColumn::make('items_count')->label('Значень')->counts('items')->alignEnd(),
            TextColumn::make('updated_at')->label('Змінено')->since(),
        ])->recordActions([EditAction::make()->label('')]);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDictionaries::route('/'),
            'create' => Pages\CreateDictionary::route('/create'),
            'edit' => Pages\EditDictionary::route('/{record}/edit'),
        ];
    }
}
