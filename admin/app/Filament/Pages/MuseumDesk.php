<?php

namespace App\Filament\Pages;

use App\Enums\VerificationStatus;
use App\Filament\Resources\BookResource;
use App\Filament\Resources\RecordResource;
use App\Models\Book;
use App\Models\Museum;
use App\Models\Record;
use App\Models\Territory;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Робота з одним музеєм: вибір музею (територія → музей), далі його фонди й книги з прогресом,
 * додавання сканів у цей музей, перехід до звірки, записів і сторінок книги без пошуку по всій системі.
 * Скани, як і раніше, лежать у кореневій директорії сховища (папка музею → фонд → книга).
 */
class MuseumDesk extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|\UnitEnum|null $navigationGroup = 'Облік сканів';

    protected static ?string $navigationLabel = 'Робота з музеєм';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'museum-desk';

    protected string $view = 'filament.pages.museum-desk';

    #[Url]
    public ?int $museum = null;

    public string $search = '';

    public static function canAccess(): bool
    {
        return BookResource::canViewAny();
    }

    public function mount(): void
    {
        if ($this->museum && ! $this->current()) {
            $this->museum = null; // музей недоступний цьому користувачу — показуємо вибір
        }
    }

    public function current(): ?Museum
    {
        return $this->museum ? once(fn () => Museum::visibleTo(auth()->user())->find($this->museum)) : null;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->current()?->name ?? 'Робота з музеєм';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->current() ? null : 'Оберіть музей, щоб працювати лише з його книгами, сканами й записами.';
    }

    /** Вибір музею: територія → музеї з кількістю книг і записів. */
    public function pickerGroups(): Collection
    {
        $museums = Museum::visibleTo(auth()->user())
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->withCount('books')->withSum('books', 'records_count')->withSum('books', 'records_verified')
            ->orderBy('name')->get();
        $territories = Territory::whereIn('id', $museums->pluck('territory_id')->filter()->unique())->pluck('name', 'id');

        return $museums->groupBy(fn ($m) => (int) $m->territory_id)
            ->map(fn ($items, $tid) => ['name' => $territories[$tid] ?? 'Без території', 'museums' => $items, 'last' => $tid === 0])
            ->sortBy(fn ($g) => [$g['last'] ? 1 : 0, $g['name']])->values();
    }

    /** Книги музею, згруповані за фондами (книги без фонду — окремою групою). */
    public function fundGroups(): Collection
    {
        $books = Book::visibleTo(auth()->user())->where('museum_id', $this->museum)->with('fund')->orderBy('title')->get();

        return $books->groupBy(fn (Book $b) => $b->fund?->name ?? 'Без фонду')
            ->sortKeysUsing(fn ($a, $b) => [$a === 'Без фонду', $a] <=> [$b === 'Без фонду', $b]);
    }

    public function totals(): array
    {
        $b = Book::visibleTo(auth()->user())->where('museum_id', $this->museum)
            ->selectRaw('count(*) as books, coalesce(sum(scans_count),0) as scans, coalesce(sum(scans_recognized),0) as recognized,
                coalesce(sum(records_count),0) as records, coalesce(sum(records_verified),0) as verified')->first();

        return $b ? $b->toArray() : [];
    }

    public function reviewUrl(?Book $book = null): ?string
    {
        $q = Record::visibleTo(auth()->user())->where('museum_id', $this->museum)->when($book, fn ($query) => $query->where('book_id', $book->id));
        $r = (clone $q)->where('verification_status', '!=', VerificationStatus::Verified->value)->orderBy('book_id')->orderBy('sequence')->first()
            ?? ($book ? $q->orderBy('sequence')->first() : null);

        return $r ? RecordResource::getUrl('review', ['record' => $r]) : null;
    }

    public function recordsUrl(?Book $book = null): string
    {
        return RecordResource::getUrl('index', ['filters' => $book
            ? ['museum_id' => ['value' => $this->museum], 'book_id' => ['value' => $book->id]]
            : ['museum_id' => ['value' => $this->museum]]]);
    }

    protected function getHeaderActions(): array
    {
        if (! $this->current()) {
            return [];
        }

        return [
            BookResource::uploadAction(null, $this->museum)->label('Додати скани'),
            Action::make('review')->label('Звірка: наступний запис')->icon('heroicon-o-document-magnifying-glass')
                ->url(fn () => $this->reviewUrl())->visible(fn () => $this->reviewUrl() !== null),
            Action::make('records')->label('Записи музею')->icon('heroicon-o-table-cells')->color('gray')
                ->url(fn () => $this->recordsUrl()),
            \App\Filament\Support\Ai::button(Action::make('recognize')->label('Розпізнати нові сторінки'))
                ->visible(fn () => auth()->user()->canOperate())->requiresConfirmation()
                ->modalDescription('У чергу підуть нерозпізнані, помилкові та змінені сторінки всіх доступних вам книг цього музею.')
                ->action(fn () => BookResource::recognize(Book::visibleTo(auth()->user())->where('museum_id', $this->museum)->get())),
            Action::make('other')->label('Інший музей')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(static::getUrl()),
        ];
    }
}
