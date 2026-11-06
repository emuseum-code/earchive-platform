<?php

namespace App\Filament\Pages;

use App\Models\Book;
use App\Models\Fund;
use App\Models\Museum;
use App\Models\Territory;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Дерево територія → архів → фонд → справа з поступовим довантаженням (ТЗ 4.1, 12). */
class Tree extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Облік сканів';

    protected static ?string $title = 'Дерево сканів';

    protected static ?string $navigationLabel = 'Дерево';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.tree';

    /** Розкриті вузли: ['t1' => true, 'm5' => true, 'f3' => true] */
    public array $open = [];

    public string $search = '';

    public function toggle(string $node): void
    {
        $this->open[$node] = ! ($this->open[$node] ?? false);
    }

    public function territories()
    {
        $museumIds = auth()->user()->museumScope();

        return Territory::query()
            ->whereHas('museums', fn ($q) => $q->when($museumIds !== null, fn ($q) => $q->whereIn('id', $museumIds)))
            ->withCount(['museums' => fn ($q) => $q->when($museumIds !== null, fn ($q) => $q->whereIn('id', $museumIds))])
            ->orderBy('name')->get();
    }

    public function museums(?int $territoryId)
    {
        return Museum::visibleTo(auth()->user())->where('territory_id', $territoryId)
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
            ->withCount('books')->withSum('books', 'scans_count')->withSum('books', 'records_count')->withSum('books', 'records_verified')
            ->orderBy('name')->get();
    }

    public function funds(int $museumId)
    {
        return Fund::where('museum_id', $museumId)->withCount('books')->orderBy('name')->get();
    }

    public function books(int $museumId, ?int $fundId)
    {
        return Book::visibleTo(auth()->user())->where('museum_id', $museumId)->where('fund_id', $fundId)->orderBy('title')->get();
    }

    public function hasUnassignedMuseums(): bool
    {
        return Museum::visibleTo(auth()->user())->whereNull('territory_id')->exists();
    }
}
