@php $m = $this->current(); @endphp
<x-filament-panels::page>
@if (! $m)
    {{-- Вибір архіву --}}
    <div class="bk-card">
        <div class="bk-tree-search"><input class="bk-input" type="search" placeholder="Пошук архіву" wire:model.live.debounce.400ms="search"></div>
        <ul class="bk-tree">
            @forelse ($this->pickerGroups() as $t)
                <li wire:key="t-{{ $loop->index }}">
                    <div class="bk-tree__node">🗺 {{ $t['name'] }} <span class="bk-muted">· архівів {{ $t['museums']->count() }}</span></div>
                    <ul>
                        @foreach ($t['museums'] as $mu)
                            <li wire:key="m{{ $mu->id }}">
                                <a class="bk-link" href="{{ \App\Filament\Pages\MuseumDesk::getUrl(['museum' => $mu->id]) }}">🏛 {{ $mu->name }}</a>
                                <span class="bk-muted">· справ {{ $mu->books_count }} · документів {{ (int) $mu->books_sum_records_count }} ({{ (int) $mu->books_sum_records_verified }} перевірено)</span>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @empty
                <li class="bk-muted">Архівів не знайдено.</li>
            @endforelse
        </ul>
    </div>
@else
    @php $t = $this->totals(); @endphp
    <div class="bk-status-row">
        <span class="bk-badge bk-badge--gray">Справ {{ $t['books'] ?? 0 }}</span>
        <span class="bk-badge bk-badge--gray">Сканів {{ number_format((int) ($t['scans'] ?? 0), 0, ',', ' ') }}</span>
        <span class="bk-badge bk-badge--info">Розпізнано {{ number_format((int) ($t['recognized'] ?? 0), 0, ',', ' ') }}</span>
        <span class="bk-badge bk-badge--gray">Документів {{ number_format((int) ($t['records'] ?? 0), 0, ',', ' ') }}</span>
        <span class="bk-badge bk-badge--success">Перевірено {{ number_format((int) ($t['verified'] ?? 0), 0, ',', ' ') }}</span>
        @if ($m->folder_path)<span class="bk-muted bk-mono">Папка у сховищі: {{ $m->folder_path }}</span>@endif
    </div>

    @forelse ($this->fundGroups() as $fund => $books)
        <div class="bk-card" wire:key="f-{{ md5($fund) }}">
            <div class="bk-card__head"><h3>📁 {{ $fund }} <span class="bk-muted">· справ {{ $books->count() }}</span></h3></div>
            <div class="bk-table-wrap">
                <table class="bk-table bk-table--mid">
                    <thead><tr><th>Справа</th><th>Сканів</th><th>Розпізнано</th><th>Документів</th><th>Перевірено</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($books as $b)
                            @php $review = $b->records_count ? $this->reviewUrl($b) : null; @endphp
                            <tr wire:key="b{{ $b->id }}">
                                <td><a class="bk-link" href="{{ \App\Filament\Resources\BookResource::getUrl('view', ['record' => $b]) }}">📖 {{ $b->title }}</a>
                                    @if ($b->scans_failed + $b->scans_missing)<span class="bk-badge bk-badge--danger">помилок {{ $b->scans_failed + $b->scans_missing }}</span>@endif</td>
                                <td>{{ $b->scans_count }}</td>
                                <td>{{ $b->recognitionProgress() }}%</td>
                                <td>{{ $b->records_count }}</td>
                                <td>{{ $b->records_verified }} · {{ $b->verificationProgress() }}%</td>
                                <td>
                                    <div class="bk-actions bk-actions--wrap">
                                        @if ($review)<a class="bk-btn bk-btn--primary" href="{{ $review }}">Звірка</a>@endif
                                        @if ($b->records_count)<a class="bk-btn" href="{{ $this->recordsUrl($b) }}">Документи</a>@endif
                                        <a class="bk-btn" href="{{ \App\Filament\Resources\BookResource::getUrl('pages', ['record' => $b]) }}">Сторінки</a>
                                        <a class="bk-btn" href="{{ \App\Filament\Resources\BookResource::getUrl('view', ['record' => $b]) }}" title="Картка справи: додати скани, розпізнати">Справа</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="bk-card"><p class="bk-muted">У цьому архіви ще немає справ. Натисніть «Додати скани» вгорі, щоб створити першу справу.</p></div>
    @endforelse
@endif
</x-filament-panels::page>
