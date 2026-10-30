@php
    use App\Models\Record;
    $r = $this->record->load(['scans', 'versions.user', 'remarks.user', 'currentAdaptation.schema.fields', 'aiChecks.proposals', 'verifier']);
    $groups = collect(Record::FIELDS)->groupBy(fn ($f) => $f[1], true);
    $a = $r->currentAdaptation;
@endphp
<x-filament-panels::page>
<div class="bk-cols">
    <div class="bk-stack">
        <div class="bk-card">
            <div class="bk-status-row">
                <span class="bk-badge bk-badge--{{ $r->verification_status->getColor() }}">Звірка: {{ $r->verification_status->getLabel() }}</span>
                <span class="bk-badge bk-badge--{{ $r->ai_check_status->getColor() }}">AI: {{ $r->ai_check_status->getLabel() }}</span>
                <span class="bk-badge bk-badge--{{ $r->adaptation_status->getColor() }}">Адаптація: {{ $r->adaptation_status->getLabel() }}</span>
                <span class="bk-badge bk-badge--{{ $r->export_status->getColor() }}">Експорт: {{ $r->export_status->getLabel() }}</span>
                <span class="bk-badge bk-badge--gray">Версія {{ $r->lock_version }}</span>
            </div>
            @if ($r->verified_at)<p class="bk-muted">Перевірив {{ $r->verifier?->name }} {{ $r->verified_at->format('d.m.Y H:i') }}, версія {{ $r->verified_version }}</p>@endif
            @foreach ($groups as $group => $fields)
                <h4 class="bk-group">{{ $group }}</h4>
                <dl class="bk-dl">
                    @foreach ($fields as $key => $f)
                        <dt>{{ $f[0] }} @if (isset($r->uncertain_fields[$key]))<span class="bk-badge bk-badge--warning" title="{{ $r->uncertain_fields[$key] }}">нерозбірливо</span>@endif</dt>
                        <dd>{{ $r->$key ?? '—' }}</dd>
                    @endforeach
                </dl>
            @endforeach
            @if ($r->extra_fields)
                <h4 class="bk-group">Додаткові поля з книги</h4>
                <dl class="bk-dl">@foreach ($r->extra_fields as $k => $v)<dt>{{ $k }}</dt><dd>{{ is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v }}</dd>@endforeach</dl>
            @endif
            <h4 class="bk-group">Належність і джерело</h4>
            <dl class="bk-dl">
                <dt>Музей</dt><dd>{{ $r->museum?->name }}</dd>
                <dt>Фонд</dt><dd>{{ $r->fund?->name ?? '—' }}</dd>
                <dt>Книга</dt><dd><a class="bk-link" href="{{ \App\Filament\Resources\BookResource::getUrl('view', ['record' => $r->book_id]) }}">{{ $r->book?->title }}</a></dd>
                <dt>Сторінки</dt>
                <dd>@foreach ($r->scans as $s)<a class="bk-link" href="{{ \App\Filament\Resources\BookResource::getUrl('pages', ['record' => $r->book_id, 'scan' => $s->id]) }}">{{ $s->page_label ?: $s->filename }}</a>@if ($s->pivot->is_continuation) <span class="bk-muted">(продовження)</span>@endif @if (! $loop->last), @endif @endforeach</dd>
                <dt>Ідентифікатор</dt><dd class="bk-mono">{{ $r->uid }}</dd>
            </dl>
        </div>

        @if ($a)
            <div class="bk-card">
                <div class="bk-card__head">
                    <h3>Адаптація до реєстру · v{{ $a->version }}</h3>
                    <a class="bk-link" href="{{ \App\Filament\Resources\AdaptationResource::getUrl('view', ['record' => $a]) }}">Відкрити</a>
                </div>
                <p class="bk-muted">{{ $a->status->getLabel() }} · з версії запису {{ $a->source_record_version }} · правила v{{ $a->rules_version }} · схема {{ $a->schema?->name }} {{ $a->schema?->version }}</p>
            </div>
        @endif
    </div>

    <div class="bk-stack">
        <div class="bk-card">
            <h3>Історія змін</h3>
            @foreach ($r->versions as $v)
                <div class="bk-history">
                    <div><strong>v{{ $v->version }}</strong> · {{ $v->source->getLabel() }} <span class="bk-muted">· {{ $v->user?->name ?? 'система' }} · {{ $v->created_at->format('d.m.Y H:i') }}</span></div>
                    @if ($v->comment)<div class="bk-muted">{{ $v->comment }}</div>@endif
                    @foreach ($v->changes ?? [] as $field => [$old, $new])
                        <div class="bk-change"><span>{{ Record::label($field) }}:</span> <del>{{ is_array($old) ? json_encode($old, JSON_UNESCAPED_UNICODE) : ($old ?? '—') }}</del> → <ins>{{ is_array($new) ? json_encode($new, JSON_UNESCAPED_UNICODE) : ($new ?? '—') }}</ins></div>
                    @endforeach
                </div>
            @endforeach
        </div>
        <div class="bk-card">
            <h3>AI-перевірки</h3>
            @forelse ($r->aiChecks as $c)
                <div class="bk-history">
                    <div><span class="bk-badge bk-badge--{{ $c->status->getColor() }}">{{ $c->status->getLabel() }}</span> <span class="bk-muted">{{ $c->model }} · {{ $c->created_at->format('d.m.Y H:i') }} · {{ $c->whole_record ? 'увесь запис' : 'зауважень: '.count($c->remark_ids ?? []) }}</span></div>
                    @if ($c->summary)<div class="bk-muted">{{ $c->summary }}</div>@endif
                    @foreach ($c->proposals as $p)
                        <div class="bk-change"><span>{{ Record::label($p->field) }}:</span> {{ $p->new_value ?? '—' }} <span class="bk-badge bk-badge--{{ $p->decision->getColor() }}">{{ $p->decision->getLabel() }}</span></div>
                    @endforeach
                </div>
            @empty <p class="bk-muted">Не запускалися.</p> @endforelse
        </div>
        <div class="bk-card">
            <h3>Зауваження</h3>
            @forelse ($r->remarks as $m)
                <div class="bk-history"><span class="bk-badge bk-badge--{{ $m->status->getColor() }}">{{ $m->status->getLabel() }}</span> <strong>{{ $m->fieldLabel() }}</strong> — {{ $m->comment }}
                    @if ($m->suggested_value !== null) <span class="bk-muted">→ {{ $m->suggested_value }}</span>@endif
                    <div class="bk-muted">{{ $m->user?->name }} · {{ $m->created_at->format('d.m.Y H:i') }}</div></div>
            @empty <p class="bk-muted">Немає.</p> @endforelse
        </div>
    </div>
</div>
</x-filament-panels::page>
