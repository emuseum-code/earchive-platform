<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\BookResource;
use App\Models\Book;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Прогрес книг: частка оброблених сторінок і перевірених записів (ТЗ 10). */
class BooksProgress extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Прогрес книг';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Book::visibleTo(auth()->user())->with('museum'))
            ->defaultSort('updated_at', 'desc')
            ->paginated([10, 25])
            ->columns([
                TextColumn::make('title')->label('Книга')->wrap()->weight('medium')->description(fn (Book $b) => $b->museum?->name)->searchable(),
                TextColumn::make('scans_count')->label('Сканів')->alignEnd()->sortable()
                    ->description(fn (Book $b) => $b->expected_pages ? 'з '.$b->expected_pages : null),
                TextColumn::make('rec')->label('Розпізнано')->state(fn (Book $b) => $b->recognitionProgress())->alignEnd()
                    ->formatStateUsing(fn ($state) => $state.'%')->color(fn ($state) => $state >= 100 ? 'success' : 'warning'),
                TextColumn::make('records_count')->label('Записів')->alignEnd()->sortable(),
                TextColumn::make('ver')->label('Перевірено')->state(fn (Book $b) => $b->verificationProgress())->alignEnd()
                    ->formatStateUsing(fn ($state) => $state.'%')->color(fn ($state) => $state >= 100 ? 'success' : ($state ? 'warning' : 'gray')),
                TextColumn::make('records_adapted')->label('Адаптовано')->alignEnd(),
                TextColumn::make('err')->label('Помилки')->state(fn (Book $b) => $b->scans_failed + $b->scans_missing)->alignEnd()
                    ->color(fn ($state) => $state ? 'danger' : 'gray'),
            ])
            ->recordUrl(fn (Book $b) => BookResource::getUrl('view', ['record' => $b]));
    }
}
