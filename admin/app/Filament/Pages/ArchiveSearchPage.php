<?php

namespace App\Filament\Pages;

use App\Filament\Resources\RecordResource;
use App\Models\Museum;
use App\Support\ArchiveSearch;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/** Пошук по слову в усіх документах архіву з уривками тексту, де слово знайдено. */
class ArchiveSearchPage extends Page
{
    use WithPagination;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?string $title = 'Пошук по текстах документів';

    protected static ?string $navigationLabel = 'Пошук по документах';

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'search';

    protected string $view = 'filament.pages.archive-search';

    #[Url(as: 'q')]
    public string $q = '';

    #[Url(as: 'archive')]
    public ?int $museum = null;

    public static function canAccess(): bool
    {
        return RecordResource::canViewAny();
    }

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function updatedMuseum(): void
    {
        $this->resetPage();
    }

    public function museums(): array
    {
        return Museum::visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id')->all();
    }

    public function results(): ?LengthAwarePaginator
    {
        if (! ArchiveSearch::terms($this->q)) {
            return null;
        }

        return ArchiveSearch::query($this->q, auth()->user(), $this->museum ?: null)->paginate(20);
    }

    public function snippets($record)
    {
        return ArchiveSearch::snippets($record, $this->q);
    }

    public function viewUrl($record): string
    {
        return RecordResource::getUrl('view', ['record' => $record]);
    }

    public function reviewUrl($record): string
    {
        return RecordResource::getUrl('review', ['record' => $record]);
    }
}
