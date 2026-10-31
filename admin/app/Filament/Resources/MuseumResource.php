<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MuseumResource\Pages;
use App\Filament\Resources\MuseumResource\RelationManagers\FundsRelationManager;
use App\Filament\Support\Access;
use App\Models\Museum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Музеї (рівень дерева, ТЗ 4.1). */
class MuseumResource extends Resource
{
    use Access;

    protected static function editAccess(): string
    {
        return 'admin';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = Museum::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|\UnitEnum|null $navigationGroup = 'Облік сканів';

    protected static ?string $modelLabel = 'музей';

    protected static ?string $pluralModelLabel = 'Музеї';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->with('territory')
            ->withCount('books')->withSum('books', 'scans_count')->withSum('books', 'records_count')->withSum('books', 'records_verified');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->schema([Grid::make(3)->schema([
                TextInput::make('name')->label('Назва музею')->required()->columnSpan(2),
                TextInput::make('short_name')->label('Скорочена назва'),
                Select::make('territory_id')->label('Територія')->relationship('territory', 'name')->searchable()->preload()
                    ->createOptionForm([TextInput::make('name')->label('Назва')->required()]),
                TextInput::make('edrpou')->label('Код ЄДРПОУ')->maxLength(12),
                TextInput::make('rmfu_id')->label('Ідентифікатор у РМФУ'),
                TextInput::make('folder_path')->label('Папка у сховищі')->helperText('Відносно кореневої директорії. Заповнюється синхронізацією.')->columnSpan(2),
                TextInput::make('contact_person')->label('Відповідальна особа'),
                TextInput::make('contact_email')->label('Пошта')->email(),
                Textarea::make('notes')->label('Примітки')->rows(2)->columnSpanFull(),
            ])]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Музей')->searchable()->sortable()->weight('medium')->wrap(),
                TextColumn::make('territory.name')->label('Територія')->sortable()->toggleable(),
                TextColumn::make('books_count')->label('Книг')->sortable()->alignEnd(),
                TextColumn::make('books_sum_scans_count')->label('Сканів')->numeric()->alignEnd(),
                TextColumn::make('books_sum_records_count')->label('Записів')->numeric()->alignEnd(),
                TextColumn::make('books_sum_records_verified')->label('Перевірено')->numeric()->alignEnd(),
                TextColumn::make('folder_path')->label('Папка')->toggleable(isToggledHiddenByDefault: true)->fontFamily('mono')->size('xs'),
            ])
            ->filters([SelectFilter::make('territory_id')->label('Територія')->relationship('territory', 'name')])
            ->recordUrl(fn (Museum $r) => BookResource::getUrl('index', ['filters' => ['museum_id' => ['value' => $r->id]]]))
            ->recordActions([
                // ТЗ 6.1: запуск розпізнавання для цілого музею (нові, помилкові й змінені сторінки всіх його книг)
                Action::make('recognize')->label('Розпізнати музей')->icon('heroicon-o-sparkles')->color('gray')
                    ->visible(fn () => auth()->user()->canOperate())
                    ->requiresConfirmation()->modalHeading(fn (Museum $record) => 'Розпізнати всі книги: '.$record->name)
                    ->modalDescription('У чергу підуть нерозпізнані, помилкові та змінені сторінки всіх доступних вам книг музею. Уже розпізнані сторінки не повторюються.')
                    ->action(fn (Museum $record) => BookResource::recognize(\App\Models\Book::visibleTo(auth()->user())->where('museum_id', $record->id)->get())),
                EditAction::make()->label(''),
            ]);
    }

    public static function getRelations(): array
    {
        return [FundsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMuseums::route('/'),
            'create' => Pages\CreateMuseum::route('/create'),
            'edit' => Pages\EditMuseum::route('/{record}/edit'),
        ];
    }
}
