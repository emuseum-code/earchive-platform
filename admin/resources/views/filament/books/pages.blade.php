<x-filament-panels::page>
    @php
        $scans = $this->getScans();
        $list = $scans->map(fn ($s) => ['id' => $s->id, 'url' => route('scans.image', $s->id), 'thumb' => route('scans.thumb', $s->id),
            'filename' => $s->filename, 'label' => $s->page_label, 'page_order' => $s->page_order])->values();
    @endphp
    <div class="bk-card">
        @if ($list->isEmpty())
            <p class="bk-muted">У книзі ще немає сканів. Запустіть синхронізацію папки.</p>
        @else
            <x-scan-viewer :scans="$list" height="78vh" />
        @endif
    </div>
</x-filament-panels::page>
