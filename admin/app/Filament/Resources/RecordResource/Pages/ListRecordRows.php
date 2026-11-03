<?php

namespace App\Filament\Resources\RecordResource\Pages;

use App\Enums\VerificationStatus;
use App\Filament\Pages\RecordMuseums;
use App\Filament\Resources\RecordResource;
use App\Models\Museum;
use App\Models\Record;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/** Записи одного музею (вибір музею — сторінка RecordMuseums), згруповані за книгами. */
class ListRecordRows extends ListRecords
{
    protected static string $resource = RecordResource::class;

    public function mount(): void
    {
        parent::mount();
        // без музею, книги чи пошуку таблиця не показує все в купу, а веде до вибору музею
        if (! $this->museumId() && blank($this->tableFilters['book_id']['value'] ?? null) && blank($this->tableSearch ?? null)) {
            $this->redirect(RecordMuseums::getUrl());
        }
    }

    protected function museumId(): ?int
    {
        $id = $this->tableFilters['museum_id']['value'] ?? null;

        return $id ? (int) $id : null;
    }

    public function getTitle(): string|Htmlable
    {
        return Museum::find($this->museumId())?->name ?? 'Єдина таблиця записів';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->museumId() ? 'Єдина таблиця записів · за книгами' : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('museums')->label('Усі музеї')->icon('heroicon-o-arrow-left')->color('gray')->url(RecordMuseums::getUrl()),
        ];
    }

    public function getTabs(): array
    {
        $museumId = $this->museumId();
        $count = fn (?VerificationStatus $s) => Record::visibleTo(auth()->user())
            ->when($museumId, fn ($q) => $q->where('museum_id', $museumId))
            ->when($s, fn ($q) => $q->where('verification_status', $s->value))->count();

        return [
            'all' => Tab::make('Усі')->badge($count(null)),
            ...collect(VerificationStatus::cases())->mapWithKeys(fn ($s) => [$s->value => Tab::make($s->getLabel())->badge($count($s))->badgeColor($s->getColor())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('verification_status', $s->value))])->all(),
        ];
    }
}
