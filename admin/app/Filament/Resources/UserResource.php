<?php

namespace App\Filament\Resources;

use App\Enums\Role;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Support\Access;
use App\Models\User;
use App\Support\Activity;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Персональні акаунти, ролі та межі доступу (ТЗ 5, 18.1). */
class UserResource extends Resource
{
    use Access;

    protected static function viewAccess(): string
    {
        return 'admin';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'Адміністрування';

    protected static ?string $modelLabel = 'користувач';

    protected static ?string $pluralModelLabel = 'Користувачі';

    protected static ?int $navigationSort = 1;

    public static function canDelete($record): bool
    {
        return false; // акаунт відкликають (вимикають), історія дій зберігається
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Акаунт')->columnSpanFull()->schema([Grid::make(3)->schema([
                TextInput::make('name')->label('Ім’я та прізвище')->required(),
                TextInput::make('email')->label('Пошта (логін)')->email()->required()->unique(ignoreRecord: true),
                TextInput::make('password')->label('Пароль')->password()->revealable()
                    ->required(fn ($operation) => $operation === 'create')->dehydrated(fn ($state) => filled($state))->minLength(8),
                TextInput::make('position')->label('Посада'),
                TextInput::make('phone')->label('Телефон'),
                Toggle::make('is_active')->label('Акаунт активний')->default(true)->inline(false)
                    ->helperText('Вимкніть, щоб відкликати доступ. Історія дій зберігається.'),
            ])]),
            Section::make('Роль і доступ')->columnSpanFull()->schema([Grid::make(2)->schema([
                Select::make('role')->label('Роль')->options(Role::options())->required()->live()->default(Role::Viewer->value),
                Toggle::make('can_adapt_export')->label('Дозволити адаптацію та експорт')->inline(false)
                    ->visible(fn (Get $get) => in_array($get('role'), [Role::Operator->value, Role::Operator], true)
                        || (in_array($get('role'), [Role::Verifier->value, Role::Verifier], true) && $get('can_operate')))
                    ->helperText('Окремий дозвіл оператору (ТЗ 18.1).'),
                Toggle::make('can_verify')->label('Суміщає роль верифікатора')->inline(false)
                    ->visible(fn (Get $get) => in_array($get('role'), [Role::Operator->value, Role::Operator], true))
                    ->helperText('Оператор зможе також звіряти й підтверджувати записи (ТЗ 18.5).'),
                Toggle::make('can_operate')->label('Суміщає роль оператора')->inline(false)->live()
                    ->visible(fn (Get $get) => in_array($get('role'), [Role::Verifier->value, Role::Verifier], true))
                    ->helperText('Верифікатор зможе також синхронізувати й запускати розпізнавання (ТЗ 18.5).'),
                Select::make('museums')->label('Доступні музеї (усі книги музею)')->relationship('museums', 'name')->multiple()->searchable()->preload()
                    ->hidden(fn (Get $get) => in_array($get('role'), [Role::Admin->value, Role::Admin], true)),
                Select::make('books')->label('Окремі книги')->relationship('books', 'title')->multiple()->searchable()
                    ->hidden(fn (Get $get) => in_array($get('role'), [Role::Admin->value, Role::Admin], true))
                    ->helperText('Доступ лише до цих книг, якщо музей не обрано повністю.'),
            ])]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')->columns([
            TextColumn::make('name')->label('Ім’я')->searchable()->sortable()->weight('medium')->description(fn (User $u) => $u->position),
            TextColumn::make('email')->label('Пошта')->searchable(),
            TextColumn::make('role')->label('Роль')->badge()
                ->description(fn (User $u) => collect([$u->can_verify ? '+ звірка' : null, $u->can_operate ? '+ обробка' : null])->filter()->implode(', ') ?: null),
            IconColumn::make('can_adapt_export')->label('Адаптація/експорт')->boolean()->state(fn (User $u) => $u->canAdaptExport()),
            TextColumn::make('museums.name')->label('Музеї')->listWithLineBreaks()->limitList(2)->placeholder(fn (User $u) => $u->isAdmin() ? 'Усі' : '—'),
            TextColumn::make('books_count')->label('Окремих книг')->counts('books')->alignEnd()->toggleable(),
            IconColumn::make('is_active')->label('Активний')->boolean(),
            TextColumn::make('last_login_at')->label('Останній вхід')->since()->sortable(),
        ])->filters([SelectFilter::make('role')->label('Роль')->options(Role::options())])
            ->recordActions([EditAction::make()->label('')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function logChange(User $user, string $action): void
    {
        Activity::log($action, $user, $user->name.' · '.$user->role->getLabel(), [
            'museums' => $user->museums()->pluck('name')->all(), 'books' => $user->books()->count(), 'active' => $user->is_active,
        ]);
    }
}
