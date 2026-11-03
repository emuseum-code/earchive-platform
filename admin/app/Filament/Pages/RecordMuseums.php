<?php

namespace App\Filament\Pages;

use App\Enums\VerificationStatus;
use App\Filament\Resources\RecordResource;
use App\Models\Museum;
use App\Models\Territory;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Вхід в «Єдину таблицю записів»: територія → музей. Клік по музею відкриває таблицю записів лише цього музею
 * (згруповану за книгами), щоб записи різних музеїв не змішувалися.
 */
class RecordMuseums extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|\UnitEnum|null $navigationGroup = 'Дані та звірка';

    protected static ?string $title = 'Єдина таблиця записів';

    protected static ?string $navigationLabel = 'Єдина таблиця';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'records-by-museum';

    protected string $view = 'filament.pages.record-museums';

    /** Згорнуті території: ['t1' => false]; типово всі розгорнуті */
    public array $open = [];

    public string $search = '';

    public static function canAccess(): bool
    {
        return RecordResource::canViewAny();
    }

    public function toggle(string $node): void
    {
        $this->open[$node] = ! ($this->open[$node] ?? true);
    }

    /** @return Collection<int, array{key: string, name: string, museums: Collection}> */
    public function groups(): Collection
    {
        $museums = Museum::visibleTo(auth()->user())
            ->when($this->search !== '', fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
            ->withCount(['records', 'records as verified_count' => fn ($query) => $query->where('verification_status', VerificationStatus::Verified->value)])
            ->orderBy('name')->get();
        $territories = Territory::whereIn('id', $museums->pluck('territory_id')->filter()->unique())->pluck('name', 'id');

        return $museums->groupBy(fn ($m) => (int) $m->territory_id)
            ->map(fn ($items, $tid) => ['key' => 't'.$tid, 'name' => $territories[$tid] ?? 'Без території', 'museums' => $items])
            ->sortBy(fn ($g) => [$g['key'] === 't0' ? 1 : 0, $g['name']])->values();
    }

    public function recordsUrl(Museum $museum): string
    {
        return RecordResource::getUrl('index', ['filters' => ['museum_id' => ['value' => $museum->id]]]);
    }
}
