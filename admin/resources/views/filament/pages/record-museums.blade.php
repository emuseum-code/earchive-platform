<x-filament-panels::page>
<div class="bk-card">
    <div class="bk-tree-search"><input class="bk-input" type="search" placeholder="Пошук музею" wire:model.live.debounce.400ms="search"></div>
    <ul class="bk-tree">
        @forelse ($this->groups() as $t)
            @php $tOpen = $open[$t['key']] ?? true; @endphp
            <li wire:key="{{ $t['key'] }}">
                <button type="button" class="bk-tree__node" wire:click="toggle('{{ $t['key'] }}')">
                    <span class="bk-tree__caret">{{ $tOpen ? '▾' : '▸' }}</span> 🗺 {{ $t['name'] }}
                    <span class="bk-muted">· музеїв {{ $t['museums']->count() }} · записів {{ number_format($t['museums']->sum('records_count'), 0, ',', ' ') }}</span>
                </button>
                @if ($tOpen)
                    <ul>
                        @foreach ($t['museums'] as $m)
                            <li wire:key="m{{ $m->id }}">
                                <a class="bk-link" href="{{ $this->recordsUrl($m) }}">🏛 {{ $m->name }}</a>
                                <span class="bk-muted">· записів {{ number_format($m->records_count, 0, ',', ' ') }} · перевірено {{ number_format($m->verified_count, 0, ',', ' ') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </li>
        @empty
            <li class="bk-muted">Музеїв не знайдено.</li>
        @endforelse
    </ul>
</div>
</x-filament-panels::page>
