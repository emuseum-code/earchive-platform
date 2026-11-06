<?php

namespace App\Filament\Resources\BookResource\Pages;

use App\Filament\Resources\BookResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Url;

/** Перегляд сторінок справи: мініатюри, оригінал, масштаб, поворот, перехід (ТЗ 4.3). */
class BookPages extends Page
{
    use InteractsWithRecord;

    protected static string $resource = BookResource::class;

    protected string $view = 'filament.books.pages';

    #[Url]
    public ?int $scan = null;

    public int $viewerIdx = 0;

    public ?array $highlight = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(BookResource::getEloquentQuery()->whereKey($this->record->id)->exists(), 403);
        if ($this->scan) {
            $this->viewerIdx = max(0, $this->record->scans()->pluck('id')->search($this->scan));
        }
    }

    public function getTitle(): string
    {
        return 'Сторінки: '.$this->record->title;
    }

    public function getScans()
    {
        return $this->record->scans()->get(['id', 'filename', 'page_order', 'page_label', 'file_status', 'recognition_status', 'width', 'height']);
    }
}
