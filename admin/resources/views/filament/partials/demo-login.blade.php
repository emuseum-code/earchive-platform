@php
    $users = \App\Models\User::whereIn('email', \App\Models\User::DEMO_LOGINS)->where('is_active', true)->get()
        ->sortBy(fn ($u) => array_search($u->email, \App\Models\User::DEMO_LOGINS));
@endphp
@if ($users->isNotEmpty())
    <div class="bk-demo">
        <div class="bk-demo__title">Швидкий вхід для тестування</div>
        <div class="bk-demo__grid">
            @foreach ($users as $u)
                <form method="POST" action="{{ route('demo-login') }}">
                    @csrf
                    <input type="hidden" name="email" value="{{ $u->email }}">
                    <button type="submit" class="bk-demo__btn">
                        <span class="bk-demo__role">{{ $u->role->getLabel() }}</span>
                        <span class="bk-demo__name">{{ $u->name }}</span>
                        <span class="bk-demo__mail">{{ $u->email }}</span>
                    </button>
                </form>
            @endforeach
        </div>
    </div>
@endif
