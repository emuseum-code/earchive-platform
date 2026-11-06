<div class="bk-table-wrap">
    <table class="bk-table">
        <thead><tr><th>Документ</th><th>Інв. номер</th><th>Версія документа</th><th>Версія адаптації</th><th>Приймання реєстром</th></tr></thead>
        <tbody>
        @foreach ($items as $i)
            <tr><td>№ {{ $i->record?->record_no }}</td><td>{{ $i->record?->archive_code }}</td><td>v{{ $i->record_version }}</td>
                <td>{{ $i->adaptation_version ? 'v'.$i->adaptation_version : '—' }}</td><td>{{ $i->api_status->getLabel() }}@if ($i->external_id) · {{ $i->external_id }}@endif</td></tr>
        @endforeach
        </tbody>
    </table>
    @if ($total > $items->count())<p class="bk-muted">Показано {{ $items->count() }} з {{ $total }}.</p>@endif
    <p class="bk-muted">Створений файл не є підтвердженням приймання даних реєстром.</p>
</div>
