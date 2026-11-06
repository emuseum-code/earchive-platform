<?php

namespace App\Filament\Pages;

use App\Enums\VerificationStatus;
use App\Filament\Resources\RecordResource;
use App\Models\Museum;
use App\Models\Territory;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * «Єдина таблиця документів»: архіви один під одним. Клік по назві архіву розгортає під нею таблицю його документів
 * (згруповану за справами), повторний клік згортає. Одночасно відкритий один архів.
 */
class RecordMuseums extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|\UnitEnum|null $navigationGroup = 'Дані та звірка';

    protected static ?string $title = 'Єдина таблиця документів';

    protected static ?string $navigationLabel = 'Єдина таблиця';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'records-by-museum';

    protected string $view = 'filament.pages.record-museums';

    /** Розгорнутий архів (у адресі, щоб після переходу до звірки й назад він лишався відкритим) */
    #[Url(as: 'museum')]
    public ?int $openMuseum = null;

    public string $search = '';

    public static function canAccess(): bool
    {
        return RecordResource::canViewAny();
    }

    public function toggleMuseum(int $id): void
    {
        $this->openMuseum = $this->openMuseum === $id ? null : $id;
    }

    /** @return Collection<int, array{name: string, museums: Collection}> */
    public function groups(): Collection
    {
        $museums = Museum::visibleTo(auth()->user())
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->withCount(['records', 'records as verified_count' => fn ($query) => $query->where('verification_status', VerificationStatus::Verified->value)])
            ->orderBy('name')->get();
        $territories = Territory::whereIn('id', $museums->pluck('territory_id')->filter()->unique())->pluck('name', 'id');

        return $museums->groupBy(fn ($m) => (int) $m->territory_id)
            ->map(fn ($items, $tid) => ['name' => $territories[$tid] ?? 'Без території', 'museums' => $items, 'last' => $tid === 0])
            ->sortBy(fn ($g) => [$g['last'] ? 1 : 0, $g['name']])->values();
    }

    public function recordsUrl(Museum $museum): string
    {
        return RecordResource::getUrl('index', ['filters' => ['museum_id' => ['value' => $museum->id]]]);
    }
}
