<x-filament-panels::page>
<style>
    .as-form{display:flex;gap:.5rem;flex-wrap:wrap}
    .as-form .bk-input{flex:1 1 20rem}
    .as-hit{display:grid;gap:.35rem}
    .as-hit h3{font-weight:600;font-size:1rem;line-height:1.35}
    .as-meta{font-size:.85rem;color:rgb(107 114 128)}
    .as-snip{font-size:.9rem;line-height:1.5}
    .as-snip b{font-weight:600;color:rgb(75 85 99);margin-right:.35rem}
    .as-snip mark{background:#fde68a;color:inherit;border-radius:2px;padding:0 1px}
    .as-links{display:flex;gap:1rem;font-size:.85rem}
</style>
<div class="bk-stack">
    <div class="bk-card">
        <div class="as-form">
            <input class="bk-input" type="search" autofocus placeholder="Слово, прізвище, місце, шифр… Фраза — у лапках: &quot;Річ Посполита&quot;" wire:model.live.debounce.400ms="q" aria-label="Пошук по архіву">
            <select class="bk-input" style="flex:0 1 16rem" wire:model.live="museum" aria-label="Архів">
                <option value="">Усі архіви</option>
                @foreach ($this->museums() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            @if ($sections = $this->sections())
                <select class="bk-input" style="flex:0 1 16rem" wire:model.live="section" aria-label="Розділ еАрхіву">
                    <option value="">Усі розділи еАрхіву</option>
                    @foreach ($sections as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif
        </div>
        <p class="bk-muted" style="margin-top:.5rem">Шукає в повному тексті документів, заголовках, змісті, особах, місцях, установах і шифрах. Кілька слів — знайдуться документи, де є всі.</p>
    </div>

    @php $results = $this->results(); @endphp
    @if ($results === null)
        <div class="bk-card"><p class="bk-muted">Введіть слово (від 2 літер).</p></div>
    @elseif ($results->isEmpty())
        <div class="bk-card"><p class="bk-muted">Нічого не знайдено. Спробуйте іншу форму слова або коротшу основу, наприклад «Петлюр» замість «Петлюрою».</p></div>
    @else
        <p class="bk-muted">Знайдено документів: {{ $results->total() }}</p>
        @foreach ($results as $r)
            <div class="bk-card as-hit" wire:key="r{{ $r->id }}">
                <h3><a class="bk-link" href="{{ $this->viewUrl($r) }}">{{ $r->title ?: ($r->doc_type ?: 'Документ № '.$r->id) }}</a></h3>
                <div class="as-meta">
                    {{ $r->museum?->name }} · {{ $r->book?->archiveCode() }}@if ($r->sheets), арк. {{ $r->sheets }}@endif
                    @if ($r->doc_date) · {{ $r->doc_date }}@endif
                    @if ($r->doc_type) · {{ $r->doc_type }}@endif
                    · {{ $r->verification_status->getLabel() }}
                </div>
                @foreach ($this->snippets($r) as $s)
                    <div class="as-snip"><b>{{ $s['field'] }}:</b>{!! $s['html'] !!}</div>
                @endforeach
                <div class="as-links">
                    <a class="bk-link" href="{{ $this->viewUrl($r) }}">Картка документа</a>
                    <a class="bk-link" href="{{ $this->reviewUrl($r) }}">Звірка зі сканом</a>
                </div>
            </div>
        @endforeach
        {{ $results->links() }}
    @endif
</div>
</x-filament-panels::page>
