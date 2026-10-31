@php
    $u = auth()->user();
    $role = $u?->role?->getLabel();
    if ($u?->role?->value === 'operator' && $u->can_verify) { $role .= ' + верифікатор'; }
    if ($u?->role?->value === 'verifier' && $u->can_operate) { $role .= ' + оператор'; }
@endphp
@if ($u)
    {{-- Під яким акаунтом працює користувач і кнопка виходу --}}
    <div class="bk-account">
        <div class="bk-account__who" title="{{ $u->email }}">
            <span class="bk-account__name">{{ $u->name }}</span>
            <span class="bk-account__role">{{ $role }} · {{ $u->email }}</span>
        </div>
        <form method="POST" action="{{ filament()->getLogoutUrl() }}">
            @csrf
            <button type="submit" class="bk-account__logout" title="Вийти з акаунта">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l-5-5 5-5M5 12h12"/></svg>
                <span>Вийти</span>
            </button>
        </form>
    </div>
@endif
