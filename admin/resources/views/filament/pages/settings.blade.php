<x-filament-panels::page>
    <form wire:submit="save" class="bk-stack">
        {{ $this->form }}
        <div><x-filament::button type="submit" icon="heroicon-o-check">Зберегти</x-filament::button></div>
    </form>
</x-filament-panels::page>
