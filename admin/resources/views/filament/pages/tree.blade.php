@php $bookUrl = fn ($b) => \App\Filament\Resources\BookResource::getUrl('view', ['record' => $b]); @endphp
<x-filament-panels::page>
<div class="bk-card">
    <div class="bk-tree-search"><input class="bk-input" type="search" placeholder="Пошук музею" wire:model.live.debounce.400ms="search"></div>
    <ul class="bk-tree">
        @php $groups = $this->territories()->map(fn ($t) => ['key' => 't'.$t->id, 'id' => $t->id, 'name' => $t->name, 'count' => $t->museums_count]);
             if ($this->hasUnassignedMuseums()) $groups->push(['key' => 't0', 'id' => null, 'name' => 'Без території', 'count' => null]); @endphp
        @forelse ($groups as $t)
            @php $tOpen = $open[$t['key']] ?? ($search !== ''); @endphp
            <li wire:key="{{ $t['key'] }}">
                <button type="button" class="bk-tree__node" wire:click="toggle('{{ $t['key'] }}')">
                    <span class="bk-tree__caret">{{ $tOpen ? '▾' : '▸' }}</span> 🗺 {{ $t['name'] }}
                    @if ($t['count'])<span class="bk-muted">· музеїв {{ $t['count'] }}</span>@endif
                </button>
                @if ($tOpen)
                    <ul>
                        @foreach ($this->museums($t['id']) as $m)
                            @php $mOpen = $open['m'.$m->id] ?? false; @endphp
                            <li wire:key="m{{ $m->id }}">
                                <button type="button" class="bk-tree__node" wire:click="toggle('m{{ $m->id }}')">
                                    <span class="bk-tree__caret">{{ $mOpen ? '▾' : '▸' }}</span> 🏛 {{ $m->name }}
                                    <span class="bk-muted">· книг {{ $m->books_count }} · сканів {{ number_format((int) $m->books_sum_scans_count, 0, ',', ' ') }} · записів {{ (int) $m->books_sum_records_count }} ({{ (int) $m->books_sum_records_verified }} перевірено)</span>
                                </button>
                                <a class="bk-link" href="{{ \App\Filament\Pages\MuseumDesk::getUrl(['museum' => $m->id]) }}" title="Книги, скани, звірка лише цього музею">→ працювати з музеєм</a>
                                @if ($mOpen)
                                    <ul>
                                        @foreach ($this->funds($m->id) as $f)
                                            @php $fOpen = $open['f'.$f->id] ?? false; @endphp
                                            <li wire:key="f{{ $f->id }}">
                                                <button type="button" class="bk-tree__node" wire:click="toggle('f{{ $f->id }}')"><span class="bk-tree__caret">{{ $fOpen ? '▾' : '▸' }}</span> 📁 {{ $f->name }}@if ($f->planned_books) <span class="bk-muted">· книг {{ $f->books_count }} з {{ $f->planned_books }}</span>@endif</button>
                                                @if ($fOpen)<ul>@foreach ($this->books($m->id, $f->id) as $b) @include('filament.pages.tree-book', ['b' => $b]) @endforeach</ul>@endif
                                            </li>
                                        @endforeach
                                        @foreach ($this->books($m->id, null) as $b) @include('filament.pages.tree-book', ['b' => $b]) @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @empty
            <li class="bk-muted">Дерево порожнє. Задайте кореневу директорію в налаштуваннях і запустіть синхронізацію.</li>
        @endforelse
    </ul>
</div>
</x-filament-panels::page>
