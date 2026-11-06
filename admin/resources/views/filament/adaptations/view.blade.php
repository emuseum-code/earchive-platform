@php
    $a = $this->record->load(['record', 'schema.fields']);
    $issues = collect($a->issues ?? [])->groupBy('field');
    $stale = $a->isStale();
    $canEdit = $this->canEdit();
@endphp
<x-filament-panels::page>
    @if ($stale)
        <div class="bk-alert bk-alert--warning">Потребує оновлення: джерельний документ змінився після адаптації (зараз v{{ $a->record->lock_version }}, адаптовано з v{{ $a->source_record_version }}). Запустіть адаптацію повторно.</div>
    @endif
    @if (! $a->is_current)<div class="bk-alert bk-alert--info">Це не поточна версія адаптації.</div>@endif
    <div class="bk-card">
        <div class="bk-status-row">
            <span class="bk-badge bk-badge--{{ $a->status->getColor() }}">{{ $a->status->getLabel() }}</span>
            <span class="bk-badge bk-badge--{{ $a->errors_count ? 'danger' : 'gray' }}">Помилок: {{ $a->errors_count }}</span>
            <span class="bk-badge bk-badge--{{ $a->warnings_count ? 'warning' : 'gray' }}">Неоднозначностей: {{ $a->warnings_count }}</span>
            <span class="bk-muted">правила v{{ $a->rules_version }} @if ($a->confirmed_at)· підтвердив {{ $a->confirmer?->name }} {{ \App\Support\Tz::fmt($a->confirmed_at, 'd.m.Y H:i') }}@endif</span>
        </div>
        <div class="bk-table-wrap">
            <table class="bk-table bk-table--adapt">
                <thead><tr><th style="width:24%">Поле реєстру</th><th style="width:30%">Джерело в справі</th><th>Адаптоване значення</th></tr></thead>
                <tbody>
                @foreach ($a->schema->fields as $f)
                    @php $fi = $issues->get($f->key, collect()); $src = $a->sources[$f->key] ?? null; @endphp
                    <tr @class(['has-error' => $fi->where('level', 'error')->count(), 'is-uncertain' => $fi->where('level', '!=', 'error')->count()])>
                        <td><strong>{{ $f->required ? '*' : '' }}{{ $f->label }}</strong><div class="bk-muted bk-mono">{{ $f->api_path ?: $f->key }}</div></td>
                        <td>@if ($src)<div>{{ $src['raw'] ?? '—' }}</div><div class="bk-muted">{{ collect($src['fields'] ?? [])->map(fn ($x) => \App\Models\Record::label($x))->implode(', ') }} · {{ \App\Models\AdaptationRule::TRANSFORMS[$src['rule']] ?? $src['rule'] }}</div>@else<span class="bk-muted">—</span>@endif</td>
                        <td>
                            @if ($canEdit)
                                <textarea class="bk-input" rows="{{ mb_strlen((string) ($values[$f->key] ?? '')) > 70 ? 3 : 1 }}" wire:model="values.{{ $f->key }}"></textarea>
                            @else
                                {{ $values[$f->key] ?? '—' }}
                            @endif
                            @foreach ($fi as $i)<div class="bk-issue bk-issue--{{ $i['level'] }}">{{ $i['message'] }}</div>@endforeach
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($canEdit)
            <div class="bk-footer">
                <button type="button" class="bk-btn" wire:click="save">Зберегти виправлення</button>
                <button type="button" class="bk-btn bk-btn--primary" wire:click="confirm" @disabled($stale)>✓ Підтвердити адаптацію</button>
            </div>
        @endif
    </div>
</x-filament-panels::page>
