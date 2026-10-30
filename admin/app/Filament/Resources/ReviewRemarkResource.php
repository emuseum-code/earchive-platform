<?php

namespace App\Filament\Resources;

use App\Enums\RemarkStatus;
use App\Enums\RemarkType;
use App\Filament\Resources\ReviewRemarkResource\Pages;
use App\Filament\Support\Access;
use App\Models\Book;
use App\Models\Record;
use App\Models\ReviewRemark;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Усі зауваження звірки — щоб продовжити роботу пізніше (ТЗ 8.2). */
class ReviewRemarkResource extends Resource
{
    use Access;

    protected static function editAccess(): string
    {
        return 'none';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = ReviewRemark::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|\UnitEnum|null $navigationGroup = 'Дані та звірка';

    protected static ?string $modelLabel = 'зауваження';

    protected static ?string $pluralModelLabel = 'Зауваження звірки';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->visibleTo(auth()->user())->with(['record', 'book', 'user']);
    }

    public static function getNavigationBadge(): ?string
    {
        $n = ReviewRemark::visibleTo(auth()->user())->where('status', 'open')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('status')->label('Стан')->badge(),
            TextColumn::make('type')->label('Тип')->badge()->toggleable(),
            TextColumn::make('field')->label('Поле')->formatStateUsing(fn ($state) => Record::label($state))->placeholder('—'),
            TextColumn::make('comment')->label('Коментар')->wrap()->lineClamp(2)->searchable(),
            TextColumn::make('suggested_value')->label('Правильне значення')->wrap()->lineClamp(2)->placeholder('—'),
            TextColumn::make('record.record_no')->label('Запис №'),
            TextColumn::make('book.title')->label('Книга')->wrap()->size('xs'),
            TextColumn::make('user.name')->label('Автор'),
            TextColumn::make('created_at')->label('Створено')->dateTime('d.m.Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('Стан')->options(RemarkStatus::options())->default('open'),
            SelectFilter::make('type')->label('Тип')->options(RemarkType::options()),
            SelectFilter::make('book_id')->label('Книга')->options(fn () => Book::visibleTo(auth()->user())->pluck('title', 'id'))->searchable(),
        ])->recordActions([
            Action::make('review')->label('До звірки')->icon('heroicon-o-document-magnifying-glass')
                ->visible(fn (ReviewRemark $r) => $r->record_id)
                ->url(fn (ReviewRemark $r) => $r->record_id ? RecordResource::getUrl('review', ['record' => $r->record_id]) : null),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReviewRemarks::route('/')];
    }
}
