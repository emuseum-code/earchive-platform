<?php

namespace App\Filament\Resources;

use App\Enums\AdaptationStatus;
use App\Filament\Resources\AdaptationResource\Pages;
use App\Filament\Support\Access;
use App\Models\Adaptation;
use App\Models\Book;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Адаптовані документи у форматі реєстру з помилками та неоднозначностями (ТЗ 9). */
class AdaptationResource extends Resource
{
    use Access;

    protected static function editAccess(): string
    {
        return 'none';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = Adaptation::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Адаптація й експорт';

    protected static ?string $modelLabel = 'адаптація';

    protected static ?string $pluralModelLabel = 'Архівний опис';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $ids = auth()->user()->bookScope();

        return parent::getEloquentQuery()->where('is_current', true)->with(['record.book', 'schema'])
            ->when($ids !== null, fn ($q) => $q->whereHas('record', fn ($r) => $r->whereIn('book_id', $ids)));
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', AdaptationStatus::NeedsClarification->value)->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('record.record_no')->label('Документ №')->searchable(),
            TextColumn::make('values.name_uk')->label('Назва (реєстр)')->wrap()->lineClamp(2),
            TextColumn::make('values.num_inventory')->label('Інв. номер'),
            TextColumn::make('record.book.title')->label('Справа')->wrap()->size('xs'),
            TextColumn::make('status')->label('Стан')->badge(),
            TextColumn::make('errors_count')->label('Помилок')->alignEnd()->color(fn ($state) => $state ? 'danger' : null),
            TextColumn::make('warnings_count')->label('Неоднозн.')->alignEnd()->color(fn ($state) => $state ? 'warning' : null),
            TextColumn::make('version')->label('Версія')->formatStateUsing(fn ($state, Adaptation $a) => 'v'.$state.' ← документ v'.$a->source_record_version)->size('xs'),
            TextColumn::make('updated_at')->label('Змінено')->since()->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('Стан')->options(AdaptationStatus::options())->multiple(),
            SelectFilter::make('book')->label('Справа')->options(fn () => Book::visibleTo(auth()->user())->pluck('title', 'id'))
                ->query(fn ($query, $data) => $query->when($data['value'] ?? null, fn ($q, $v) => $q->whereHas('record', fn ($r) => $r->where('book_id', $v)))),
        ])->recordActions([ViewAction::make()->label('Відкрити')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdaptations::route('/'),
            'view' => Pages\ViewAdaptation::route('/{record}'),
        ];
    }
}
