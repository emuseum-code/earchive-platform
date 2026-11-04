<x-filament-panels::page>
<div class="bk-stack">
    <div class="bk-tree-search"><input class="bk-input" type="search" placeholder="Пошук музею" wire:model.live.debounce.400ms="search"></div>
    @forelse ($this->groups() as $t)
        <div class="bk-muted" style="margin-top:.5rem">🗺 {{ $t['name'] }}</div>
        @foreach ($t['museums'] as $m)
            @php $isOpen = $openMuseum === $m->id; @endphp
            <div class="bk-card" wire:key="m{{ $m->id }}">
                <button type="button" class="bk-tree__node" style="width:100%;text-align:left" wire:click="toggleMuseum({{ $m->id }})" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                    <span class="bk-tree__caret">{{ $isOpen ? '▾' : '▸' }}</span> <strong>🏛 {{ $m->name }}</strong>
                    <span class="bk-muted">· записів {{ number_format($m->records_count, 0, ',', ' ') }} · перевірено {{ number_format($m->verified_count, 0, ',', ' ') }}</span>
                </button>
                @if ($isOpen)
                    <div style="margin-top:.75rem">
                        @if ($m->records_count)
                            @livewire(\App\Livewire\MuseumRecordsTable::class, ['museumId' => $m->id], key('records-'.$m->id))
                            <p class="bk-muted" style="margin-top:.5rem"><a class="bk-link" href="{{ $this->recordsUrl($m) }}">Відкрити таблицю музею на всю сторінку (з вкладками статусів) →</a></p>
                        @else
                            <p class="bk-muted">Записів ще немає: спершу розпізнайте сторінки книг музею.</p>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    @empty
        <div class="bk-card"><p class="bk-muted">Музеїв не знайдено.</p></div>
    @endforelse
</div>
</x-filament-panels::page>
