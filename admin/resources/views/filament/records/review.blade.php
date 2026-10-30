@php
    use App\Models\Record;
    $record = $this->record;
    $canEdit = $this->canEdit();
    $check = $this->latestCheck;
    $running = $this->aiRunning();
    $proposals = $this->pendingProposals();
    $remarks = $this->remarks();
    $openRemarks = $remarks->where('status.value', 'open');
    $uncertain = $record->uncertain_fields ?? [];
    $groups = collect(Record::FIELDS)->groupBy(fn ($f) => $f[1], true);
    $long = ['title', 'description', 'acquisition_source', 'provenance', 'notes', 'material', 'location'];
@endphp
<x-filament-panels::page>
<div class="bk-review" @if ($running) wire:poll.2s="pollAi" @endif>
    {{-- Ліва панель: оригінальний скан --}}
    <section class="bk-review__left">
        <div class="bk-card bk-sticky">
            @if (count($this->scansList))
                <x-scan-viewer :scans="$this->scansList" :selectable="$canEdit" height="calc(100vh - 15rem)" />
            @else
                <p class="bk-muted">Запис не пов’язаний зі сканами.</p>
            @endif
        </div>
    </section>

    {{-- Права панель: розпізнаний запис --}}
    <section class="bk-review__right">
        <div class="bk-review__top">
            <div class="bk-status-row">
                <span class="bk-badge bk-badge--{{ $record->verification_status->getColor() }}">Звірка: {{ $record->verification_status->getLabel() }}</span>
                <span class="bk-badge bk-badge--{{ $record->ai_check_status->getColor() }}">AI: {{ $record->ai_check_status->getLabel() }}</span>
                <span class="bk-badge bk-badge--{{ $record->adaptation_status->getColor() }}">Адаптація: {{ $record->adaptation_status->getLabel() }}</span>
                <span class="bk-badge bk-badge--gray">Версія {{ $record->lock_version }}</span>
                @if ($record->possible_duplicate)<span class="bk-badge bk-badge--warning">Можливий дублікат</span>@endif
            </div>
            <div class="bk-nav">
                <button type="button" class="bk-btn" wire:click="go(-1)" title="Попередній запис">‹ Попередній</button>
                <select class="bk-select" wire:model.live="nav" title="Перехід між записами книги">
                    <option value="unchecked">Неперевірені</option>
                    <option value="all">Усі записи</option>
                </select>
                <button type="button" class="bk-btn" wire:click="go(1)" title="Наступний запис">Наступний ›</button>
            </div>
        </div>

        @if ($canEdit)
            {{-- Кнопка «Перевірити AI» (ТЗ 8.3): помітна, лишається видимою при прокручуванні --}}
            <div class="bk-ai-dock">
                <button type="button" class="bk-ai-btn" wire:click="runAiCheck" wire:loading.attr="disabled" wire:target="runAiCheck"
                        @disabled($running) aria-live="polite" aria-busy="{{ $running ? 'true' : 'false' }}">
                    @if ($running)
                        <span class="bk-spinner" aria-hidden="true"></span>
                        <span>AI перевіряє…</span>
                    @else
                        <span wire:loading.remove wire:target="runAiCheck" class="bk-ai-btn__inner">
                            <svg class="bk-ai-btn__icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2l1.9 5.6L19.5 9.5l-5.6 1.9L12 17l-1.9-5.6L4.5 9.5l5.6-1.9zM19 14l.9 2.6 2.6.9-2.6.9L19 21l-.9-2.6-2.6-.9 2.6-.9zM5 15l.7 1.8 1.8.7-1.8.7L5 20l-.7-1.8L2.5 17.5l1.8-.7z"/></svg>
                            <span>Перевірити AI</span>
                        </span>
                        <span wire:loading.flex wire:target="runAiCheck" class="bk-ai-btn__inner"><span class="bk-spinner"></span> AI перевіряє…</span>
                    @endif
                </button>
                <span class="bk-ai-hint">
                    @if ($openRemarks->count())
                        Буде перевірено {{ $openRemarks->count() }} {{ trans_choice('зауваження|зауваження|зауважень', $openRemarks->count()) }} з урахуванням сканів
                    @else
                        Зауважень немає — AI перевірить увесь запис
                    @endif
                </span>
            </div>
        @endif

        @if ($check && $check->status->value === 'failed')
            <div class="bk-alert bk-alert--danger" role="alert">
                <strong>AI-перевірка не вдалася.</strong> {{ $check->error }}
                Зауваження збережено. @if ($canEdit)<button type="button" class="bk-link" wire:click="runAiCheck">Повторити</button>@endif
            </div>
        @endif

        {{-- Результат AI: попереднє й запропоноване значення, прийняти / відхилити / виправити (ТЗ 8.4) --}}
        @if ($proposals->count())
            <div class="bk-card bk-proposals">
                <div class="bk-card__head">
                    <h3>Пропозиції AI <span class="bk-muted">· {{ $proposals->count() }}</span></h3>
                    @if ($canEdit)
                        <div class="bk-actions">
                            <button type="button" class="bk-btn bk-btn--success" wire:click="decideAll('accepted')">Прийняти всі</button>
                            <button type="button" class="bk-btn" wire:click="decideAll('rejected')">Відхилити всі</button>
                        </div>
                    @endif
                </div>
                @if ($proposals->first()->aiCheck?->summary)<p class="bk-muted">{{ $proposals->first()->aiCheck->summary }}</p>@endif
                @foreach ($proposals as $p)
                    <div class="bk-diff" wire:key="p-{{ $p->id }}">
                        <div class="bk-diff__field">{{ Record::label($p->field) }}
                            @if ($p->confidence)<span class="bk-muted">· впевненість {{ round($p->confidence * 100) }}%</span>@endif</div>
                        <div class="bk-diff__old"><span class="bk-diff__tag">Було</span> {{ $p->old_value ?? '—' }}</div>
                        <div class="bk-diff__new"><span class="bk-diff__tag">AI</span>
                            @if ($canEdit)
                                <textarea class="bk-input" rows="{{ mb_strlen((string) $p->new_value) > 80 ? 3 : 1 }}" wire:model="editProposal.{{ $p->id }}"
                                          placeholder="{{ $p->new_value }}">{{ $p->new_value }}</textarea>
                            @else {{ $p->new_value ?? '—' }} @endif
                        </div>
                        @if ($p->reason)<div class="bk-diff__reason">{{ $p->reason }}</div>@endif
                        @if ($canEdit)
                            <div class="bk-actions">
                                <button type="button" class="bk-btn bk-btn--success" wire:click="decide({{ $p->id }}, 'accepted')">✓ Прийняти</button>
                                <button type="button" class="bk-btn" wire:click="decide({{ $p->id }}, 'edited')" title="Зберегти значення з поля вище">✎ Прийняти з правкою</button>
                                <button type="button" class="bk-btn bk-btn--danger" wire:click="decide({{ $p->id }}, 'rejected')">✕ Відхилити</button>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @elseif ($check && $check->status->value === 'ready' && $check->summary)
            <div class="bk-alert bk-alert--info">{{ $check->summary }}</div>
        @endif

        {{-- Форма зауваження --}}
        @if ($remarkOpen && $canEdit)
            <div class="bk-card bk-remark-form" wire:key="remark-form">
                <div class="bk-card__head"><h3>Позначити помилку</h3><button type="button" class="bk-link" wire:click="$set('remarkOpen', false)">Скасувати</button></div>
                <div class="bk-grid2">
                    <label class="bk-field"><span>Тип зауваження</span>
                        <select class="bk-select" wire:model.live="remarkType">
                            @foreach (\App\Enums\RemarkType::cases() as $t)<option value="{{ $t->value }}">{{ $t->getLabel() }}</option>@endforeach
                        </select></label>
                    @if ($remarkType === 'field')
                        <label class="bk-field"><span>Поле</span>
                            <select class="bk-select" wire:model.live="remarkField">
                                <option value="">— оберіть —</option>
                                @foreach (Record::FIELDS as $k => $f)<option value="{{ $k }}">{{ $f[0] }}</option>@endforeach
                            </select>
                            @error('remarkField')<em class="bk-error">{{ $message }}</em>@enderror</label>
                    @endif
                </div>
                <label class="bk-field"><span>Коментар</span>
                    <textarea class="bk-input" rows="2" wire:model="remarkComment" placeholder="Напр.: «Третій символ номера — цифра 3», «Опис продовжується на наступній сторінці»"></textarea>
                    @error('remarkComment')<em class="bk-error">{{ $message }}</em>@enderror</label>
                @if ($remarkType === 'field')
                    <label class="bk-field"><span>Правильне значення, якщо відоме</span>
                        <textarea class="bk-input" rows="2" wire:model="remarkValue"></textarea></label>
                @endif
                <p class="bk-muted">
                    @if ($pendingRegion) ✓ Область на скані виділено.
                        <button type="button" class="bk-link" wire:click="$set('pendingRegion', null)">Прибрати</button>
                    @else За потреби натисніть «▭ Виділити» над сканом і обведіть фрагмент. @endif
                </p>
                <div class="bk-actions"><button type="button" class="bk-btn bk-btn--primary" wire:click="saveRemark">Зберегти зауваження</button></div>
            </div>
        @endif

        {{-- Поля запису --}}
        <div class="bk-card">
            @foreach ($groups as $group => $fields)
                <h4 class="bk-group">{{ $group }}</h4>
                @foreach ($fields as $key => $f)
                    @php
                        $fieldRemarks = $openRemarks->where('field', $key);
                        $hasRegion = isset($record->field_regions[$key]);
                    @endphp
                    <div @class(['bk-row', 'is-active' => $activeField === $key, 'has-error' => $fieldRemarks->count(), 'is-uncertain' => isset($uncertain[$key]), 'has-proposal' => $proposals->has($key)])
                         wire:key="f-{{ $key }}">
                        <label class="bk-row__label" for="f-{{ $key }}">
                            {{ $f[0] }}
                            @if (isset($uncertain[$key]))<span class="bk-badge bk-badge--warning" title="{{ $uncertain[$key] }}">нерозбірливо</span>@endif
                            @if ($fieldRemarks->count())<span class="bk-badge bk-badge--danger">помилка · {{ $fieldRemarks->count() }}</span>@endif
                            @if ($proposals->has($key))<span class="bk-badge bk-badge--primary">є пропозиція AI</span>@endif
                        </label>
                        <div class="bk-row__control">
                            @if (in_array($key, $long, true))
                                <textarea id="f-{{ $key }}" class="bk-input" rows="{{ $key === 'description' ? 5 : 2 }}" wire:model.blur="data.{{ $key }}"
                                          wire:focus="selectField('{{ $key }}')" @disabled(! $canEdit)></textarea>
                            @else
                                <input id="f-{{ $key }}" class="bk-input" type="text" wire:model.blur="data.{{ $key }}" wire:focus="selectField('{{ $key }}')" @disabled(! $canEdit)>
                            @endif
                            <div class="bk-row__tools">
                                @if ($hasRegion)<button type="button" class="bk-icon-btn" wire:click="selectField('{{ $key }}')" title="Показати на скані">◎</button>@endif
                                @if ($canEdit)<button type="button" class="bk-icon-btn bk-icon-btn--flag" wire:click="openRemark('field', '{{ $key }}')" title="Позначити помилку">⚑</button>@endif
                            </div>
                        </div>
                    </div>
                @endforeach
            @endforeach
            @if ($record->extra_fields)
                <h4 class="bk-group">Додаткові поля з книги</h4>
                <dl class="bk-extra">
                    @foreach ($record->extra_fields as $label => $value)<dt>{{ $label }}</dt><dd>{{ is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value }}</dd>@endforeach
                </dl>
            @endif
        </div>

        {{-- Структурні зауваження (ТЗ 8.2) --}}
        @if ($canEdit)
            <div class="bk-card">
                <h4 class="bk-group">Помилки структури записів</h4>
                <div class="bk-actions bk-actions--wrap">
                    @foreach (['missing_record', 'extra_record', 'wrong_merge', 'wrong_split', 'continuation', 'other'] as $t)
                        <button type="button" class="bk-btn" wire:click="openRemark('{{ $t }}')">{{ \App\Enums\RemarkType::from($t)->getLabel() }}</button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Зауваження --}}
        <div class="bk-card">
            <h4 class="bk-group">Зауваження <span class="bk-muted">· відкритих {{ $openRemarks->count() }} з {{ $remarks->count() }}</span></h4>
            @forelse ($remarks as $r)
                <div @class(['bk-remark', 'is-closed' => $r->status->value !== 'open']) wire:key="r-{{ $r->id }}">
                    <div class="bk-remark__head">
                        <span class="bk-badge bk-badge--{{ $r->status->getColor() }}">{{ $r->status->getLabel() }}</span>
                        <strong>{{ $r->fieldLabel() }}</strong>
                        <span class="bk-muted">{{ $r->user?->name }} · {{ $r->created_at->format('d.m.Y H:i') }}</span>
                        @if ($r->region)<button type="button" class="bk-link" wire:click="$set('highlight', @js($r->region))">показати на скані</button>@endif
                    </div>
                    @if ($r->comment)<p>{{ $r->comment }}</p>@endif
                    @if ($r->suggested_value !== null)<p class="bk-muted">Правильне значення: <strong>{{ $r->suggested_value }}</strong></p>@endif
                    @if ($canEdit)
                        <div class="bk-actions">
                            @if ($r->status->value === 'open')
                                @if ($r->field && $r->suggested_value !== null)<button type="button" class="bk-btn" wire:click="applyRemarkValue({{ $r->id }})">Підставити значення</button>@endif
                                <button type="button" class="bk-btn bk-btn--success" wire:click="setRemarkStatus({{ $r->id }}, 'resolved')">Закрити</button>
                                <button type="button" class="bk-btn" wire:click="setRemarkStatus({{ $r->id }}, 'dismissed')">Відхилити</button>
                            @else
                                <span class="bk-muted">{{ $r->resolver?->name }} · {{ $r->resolved_at?->format('d.m.Y H:i') }}</span>
                                <button type="button" class="bk-link" wire:click="setRemarkStatus({{ $r->id }}, 'open')">Відкрити знову</button>
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <p class="bk-muted">Зауважень немає.</p>
            @endforelse
        </div>

        @if ($canEdit)
            <div class="bk-footer">
                <button type="button" class="bk-btn" wire:click="saveFields">Зберегти правки</button>
                <button type="button" class="bk-btn" wire:click="saveRemarks">Зберегти зауваження</button>
                <button type="button" class="bk-btn bk-btn--primary" wire:click="confirm"
                        @if ($openRemarks->count() || $proposals->count()) title="Спершу закрийте зауваження та вирішіть пропозиції AI" @endif>
                    ✓ Підтвердити звірку
                </button>
                @if ($openRemarks->count() || $proposals->count())
                    <span class="bk-muted">Відкритих зауважень: {{ $openRemarks->count() }}, пропозицій без рішення: {{ $proposals->count() }}</span>
                @endif
            </div>
        @endif
    </section>
</div>
</x-filament-panels::page>
