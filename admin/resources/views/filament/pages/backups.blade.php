{{-- Стан резервних копій бази (ТЗ 12): щоденна копія, по неділях повне тестове відновлення --}}
@php($statusText = ['ok' => 'перевірено', 'restore_failed' => 'не вдалося відновити', 'archive_broken' => 'архів пошкоджений', 'dump_failed' => 'копію не створено'])
<div class="bk-backups">
    @if (! $last)
        <p class="bk-muted">Резервних копій ще немає. Перша з’являється за кілька хвилин після розгортання, далі щодня о 03:00–04:00 за Києвом.</p>
    @else
        <p>
            <span class="bk-badge {{ $healthy ? 'bk-badge--success' : 'bk-badge--danger' }}">{{ $healthy ? 'Усе гаразд' : 'Потрібна увага' }}</span>
            Остання копія: <strong>{{ \App\Support\Tz::fmt($last['time'], 'd.m.Y H:i') }}</strong>,
            {{ number_format(($last['size'] ?? 0) / 1048576, 1, ',', ' ') }} МБ,
            {{ $statusText[$last['status']] ?? $last['status'] }}
            ({{ ($last['check'] ?? '') === 'restore' ? 'повне тестове відновлення: '.$last['users'].' користувачів, '.$last['records'].' записів' : 'перевірено цілісність архіву' }}).
        </p>
        @if (! $healthy)
            <p class="bk-danger-text">Остання копія старша за 36 годин або з помилкою. Передайте це технічному відповідальному.</p>
        @endif
    @endif
    @if ($files)
        <table class="bk-table">
            <thead><tr><th>Файл</th><th>Створено</th><th>Розмір</th></tr></thead>
            <tbody>
            @foreach ($files as $f)
                <tr><td><code>{{ $f['name'] }}</code></td><td>{{ \App\Support\Tz::fmt($f['time'], 'd.m.Y H:i') }}</td><td>{{ number_format($f['size'] / 1048576, 1, ',', ' ') }} МБ</td></tr>
            @endforeach
            </tbody>
        </table>
    @endif
    <p class="bk-muted">Копії зберігаються 14 днів на сервері. Щоденно перевіряється цілісність архіву, щонеділі копія повністю відновлюється в тимчасову базу. Порядок відновлення описано в README репозиторію. Оригінальні JPG резервуються окремо, за погодженням із власником сховища.</p>
</div>
