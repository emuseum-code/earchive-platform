<?php

namespace App\Filament\Resources;

use App\Enums\ExportFormat;
use App\Filament\Resources\ExportResource\Pages;
use App\Filament\Support\Access;
use App\Models\Export;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Сформовані файли обміну зі складом і версіями записів (ТЗ 11). */
class ExportResource extends Resource
{
    use Access;

    protected static function viewAccess(): string
    {
        return 'adapt';
    }

    protected static function editAccess(): string
    {
        return 'none';
    }

    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $model = Export::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|\UnitEnum|null $navigationGroup = 'Адаптація й експорт';

    protected static ?string $modelLabel = 'експорт';

    protected static ?string $pluralModelLabel = 'Експорт';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        $u = auth()->user();

        return parent::getEloquentQuery()->with('user')->when(! $u->isAdmin(), fn ($q) => $q->where('user_id', $u->id));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->poll('10s')->columns([
            TextColumn::make('created_at')->label('Створено')->dateTime('d.m.Y H:i')->sortable(),
            TextColumn::make('format')->label('Формат')->badge(),
            TextColumn::make('status')->label('Стан')->badge(),
            TextColumn::make('records_count')->label('Записів')->alignEnd(),
            TextColumn::make('filters')->label('Склад')->state(fn (Export $e) => ! empty($e->filters['ids']) ? 'Вибрані: '.count($e->filters['ids'])
                : (collect($e->filters)->filter()->keys()->implode(', ') ?: 'Усі доступні записи'))->wrap()->size('xs'),
            TextColumn::make('user.name')->label('Користувач'),
            TextColumn::make('file_size')->label('Розмір')->formatStateUsing(fn ($state) => $state ? number_format($state / 1024, 0).' КБ' : '—')->alignEnd(),
            TextColumn::make('error')->label('Помилка')->color('danger')->wrap()->placeholder('—'),
        ])->filters([SelectFilter::make('format')->label('Формат')->options(ExportFormat::options())])
            ->recordActions([
                Action::make('download')->label('Завантажити')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (Export $e) => $e->file_path)->url(fn (Export $e) => route('exports.download', $e)),
                Action::make('items')->label('Склад')->icon('heroicon-o-list-bullet')->color('gray')
                    ->modalHeading('Склад і версії експортованих записів')->modalSubmitAction(false)->modalCancelActionLabel('Закрити')
                    ->modalContent(fn (Export $e) => view('filament.exports.items', ['items' => $e->items()->with('record')->limit(500)->get(), 'total' => $e->records_count])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListExports::route('/')];
    }
}
