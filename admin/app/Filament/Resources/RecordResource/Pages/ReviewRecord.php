<?php

namespace App\Filament\Resources\RecordResource\Pages;

use App\Enums\AiCheckStatus;
use App\Enums\JobType;
use App\Enums\ProposalDecision;
use App\Enums\RemarkStatus;
use App\Enums\RemarkType;
use App\Enums\VerificationStatus;
use App\Enums\VersionSource;
use App\Exceptions\StaleRecordException;
use App\Filament\Resources\RecordResource;
use App\Models\AiCheck;
use App\Models\AiCheckProposal;
use App\Models\Record;
use App\Models\ReviewRemark;
use App\Services\AiCheckService;
use App\Services\QueueManager;
use App\Support\Activity;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * Робочий екран «Провести звірку» (ТЗ 8): ліворуч оригінальний скан, праворуч поля документа,
 * зауваження, кнопка «Перевірити AI», порівняння й прийняття пропозицій, підтвердження звірки.
 */
class ReviewRecord extends Page
{
    use InteractsWithRecord;

    protected static string $resource = RecordResource::class;

    protected string $view = 'filament.records.review';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** Поточні значення полів у правій панелі */
    public array $data = [];

    public int $lockVersion = 0;

    public int $viewerIdx = 0;

    public ?array $highlight = null;

    public ?array $pendingRegion = null;

    /** «Олівець»: виділений у полі текст, що не збігається зі сканом ({start, end, text}) */
    public ?array $pendingFragment = null;

    public ?string $activeField = null;

    /** Форма зауваження */
    public bool $remarkOpen = false;

    public string $remarkType = 'field';

    public ?string $remarkField = null;

    public ?string $remarkComment = null;

    public ?string $remarkValue = null;

    public array $editProposal = [];

    /** Навігація між документами: unchecked — лише неперевірені, all — усі документи справи */
    #[Url]
    public string $nav = 'unchecked';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(RecordResource::getEloquentQuery()->whereKey($this->record->id)->exists(), 403);
        $this->fillFromRecord();
    }

    protected function fillFromRecord(): void
    {
        $this->record->refresh();
        $this->data = collect(Record::FIELDS)->keys()->mapWithKeys(fn ($f) => [$f => $this->record->$f])->all();
        $this->lockVersion = $this->record->lock_version;
        unset($this->latestCheck, $this->scansList);
        $this->syncEditProposals();
    }

    protected function syncEditProposals(): void
    {
        foreach ($this->pendingProposals() as $p) {
            $this->editProposal[$p->id] ??= $p->new_value;
        }
    }

    public function getTitle(): string
    {
        return 'Звірка: документ № '.($this->record->record_no ?: $this->record->id);
    }

    public function getSubheading(): ?string
    {
        return $this->record->book?->title.' · '.$this->record->museum?->name;
    }

    public function getBreadcrumbs(): array
    {
        return [
            RecordResource::getUrl() => 'Єдина таблиця',
            RecordResource::getUrl('view', ['record' => $this->record]) => 'Документ № '.($this->record->record_no ?: $this->record->id),
            'Звірка',
        ];
    }

    public function canEdit(): bool
    {
        return (bool) auth()->user()?->canVerify();
    }

    protected function guard(): void
    {
        abort_unless($this->canEdit(), 403, 'Звірка доступна верифікатору.');
    }

    /** Сторінки для переглядача: спершу сторінки документа, далі решта справи для контексту. */
    #[Computed]
    public function scansList(): array
    {
        $own = $this->record->scans()->get();
        $open = $this->record->remarks()->where('status', 'open')->orderBy('id')->get();
        $num = $open->pluck('id')->flip();
        $regions = $open->whereNotNull('region');

        return $own->map(fn ($s) => [
            'id' => $s->id, 'url' => route('scans.image', $s->id), 'thumb' => route('scans.thumb', $s->id), 'filename' => $s->filename,
            'label' => ($s->page_label ?: $s->filename).($s->pivot->is_continuation ? ' · продовження' : ''), 'page_order' => $s->page_order,
            'regions' => $regions->filter(fn ($r) => ($r->region['scan_id'] ?? $r->scan_id) === $s->id)
                ->map(fn ($r) => $r->region + ['n' => $num[$r->id] + 1, 'title' => ($num[$r->id] + 1).'. '.$r->fieldLabel().': '.$r->comment])->values()->all(),
        ])->values()->all();
    }

    #[Computed]
    public function latestCheck(): ?AiCheck
    {
        return $this->record->aiChecks()->with('proposals')->first();
    }

    public function pendingProposals()
    {
        return AiCheckProposal::where('record_id', $this->record->id)->where('decision', ProposalDecision::Pending->value)->with('aiCheck')->get()->keyBy('field');
    }

    public function remarks()
    {
        return $this->record->remarks()->with(['user', 'resolver', 'scan'])->get();
    }

    public function aiRunning(): bool
    {
        return in_array($this->latestCheck?->status, [AiCheckStatus::Queued, AiCheckStatus::Running], true);
    }

    public function isDirty(): bool
    {
        foreach ($this->data as $f => $v) {
            if ((string) $v !== (string) $this->record->$f) {
                return true;
            }
        }

        return false;
    }

    /** Вибір поля: підсвічення фрагмента на скані, якщо координати відомі (ТЗ 8.1). */
    public function selectField(string $field): void
    {
        $this->activeField = $field;
        $region = $this->record->field_regions[$field] ?? null;
        if ($region) {
            $region['scan_id'] ??= $this->record->scans()->value('scans.id');
        }
        $this->highlight = $region;
        $this->dispatch('bk-center');
    }

    /** «Показати на скані» для зауваження: підсвітити й поставити область у центр переглядача. */
    public function showRegion(array $region): void
    {
        $this->highlight = $region;
        $this->dispatch('bk-center');
    }

    public function openRemark(string $type = 'field', ?string $field = null): void
    {
        $this->guard();
        $this->remarkOpen = true;
        $this->remarkType = $type;
        $this->remarkField = $field ?? ($type === 'field' ? $this->activeField : null);
        $this->remarkComment = null;
        $this->remarkValue = null;
        $this->pendingRegion = null;
        $this->pendingFragment = null;
        if ($field) {
            $this->selectField($field);
        }
        // Олівець: одразу вмикаємо виділення області на скані
        $this->dispatch('bk-pencil');
    }

    public function updatedRemarkField(): void
    {
        $this->pendingFragment = null;
    }

    /** Текст, виділений мишею в полі під час позначення помилки. */
    public function setFragment(string $field, int $start, int $end): void
    {
        $this->guard();
        $value = (string) ($this->data[$field] ?? '');
        if (! $this->remarkOpen || $this->remarkField !== $field || $end <= $start || $start < 0) {
            return;
        }
        $text = mb_substr($value, $start, $end - $start);
        if (trim($text) === '') {
            return;
        }
        $this->pendingFragment = ['start' => $start, 'end' => $end, 'text' => mb_substr($text, 0, 500)];
    }

    /** «Зберегти й перевірити AI»: зауваження одразу йде на повторну AI-перевірку. */
    public function saveRemarkAndCheck(): void
    {
        if ($this->saveRemark()) {
            $this->runAiCheck();
        }
    }

    /** «Позначити помилку»: поле, область скану, коментар, правильне значення (ТЗ 8.2). */
    public function saveRemark(): bool
    {
        $this->guard();
        if (blank($this->remarkComment) && blank($this->remarkValue) && $this->pendingFragment) {
            $this->remarkComment = 'Не збігається зі сканом: «'.$this->pendingFragment['text'].'»';
        }
        if ($this->remarkType !== 'field' || ($this->pendingFragment && $this->remarkField === null)) {
            $this->pendingFragment = null;
        }
        $this->validate([
            'remarkType' => 'required|in:'.implode(',', array_column(RemarkType::cases(), 'value')),
            'remarkField' => 'required_if:remarkType,field|nullable|in:'.implode(',', array_keys(Record::FIELDS)),
            'remarkComment' => 'required_without:remarkValue|nullable|string|max:2000',
            'remarkValue' => 'nullable|string|max:5000',
        ], [
            'remarkField.required_if' => 'Оберіть поле з помилкою.',
            'remarkComment.required_without' => 'Додайте коментар або правильне значення.',
        ]);
        $remark = ReviewRemark::create([
            'record_id' => $this->record->id,
            'book_id' => $this->record->book_id,
            'scan_id' => $this->pendingRegion['scan_id'] ?? ($this->scansList[$this->viewerIdx]['id'] ?? null),
            'type' => $this->remarkType,
            'field' => $this->remarkType === 'field' ? $this->remarkField : null,
            'region' => $this->pendingRegion,
            'fragment' => $this->pendingFragment,
            'comment' => $this->remarkComment,
            'current_value' => $this->remarkField ? $this->record->{$this->remarkField} : null,
            'suggested_value' => $this->remarkValue,
            'user_id' => auth()->id(),
        ]);
        if ($this->record->verification_status !== VerificationStatus::NeedsClarification) {
            $this->record->update(['verification_status' => VerificationStatus::NeedsClarification]);
        }
        Activity::log('remark.created', $this->record, $remark->fieldLabel().': '.$remark->comment);
        $this->remarkOpen = false;
        $this->pendingRegion = null;
        $this->pendingFragment = null;
        unset($this->scansList);
        Notification::make()->success()->title('Зауваження збережено')->send();

        return true;
    }

    public function setRemarkStatus(int $id, string $status): void
    {
        $this->guard();
        $remark = ReviewRemark::where('record_id', $this->record->id)->findOrFail($id);
        $remark->update(['status' => $status, 'resolved_by' => $status === 'open' ? null : auth()->id(), 'resolved_at' => $status === 'open' ? null : now()]);
        Activity::log('remark.resolved', $this->record, $remark->fieldLabel().': '.RemarkStatus::from($status)->getLabel());
        unset($this->scansList);
    }

    /** Застосувати правильне значення із зауваження до поля. */
    public function applyRemarkValue(int $id): void
    {
        $this->guard();
        $remark = ReviewRemark::where('record_id', $this->record->id)->findOrFail($id);
        if ($remark->field && $remark->suggested_value !== null) {
            $this->data[$remark->field] = $remark->suggested_value;
            $this->activeField = $remark->field;
        }
    }

    public function saveFields(bool $quiet = false): bool
    {
        $this->guard();
        if (! $this->isDirty()) {
            if (! $quiet) {
                Notification::make()->info()->title('Змін немає, усе вже збережено')->send();
            }

            return true;
        }
        try {
            $this->record->applyChanges($this->data, VersionSource::Manual, auth()->user(), $this->lockVersion, 'Правка під час звірки');
        } catch (StaleRecordException $e) {
            Notification::make()->danger()->title('Конфлікт редагування')->body($e->getMessage())->persistent()->send();

            return false;
        }
        $this->fillFromRecord();
        if (! $quiet) {
            Notification::make()->success()->title('Зміни збережено, версія '.$this->lockVersion)->send();
        }

        return true;
    }

    /** «Зберегти зауваження»: зберігає правки й зауваження, щоб продовжити пізніше. */
    public function saveRemarks(): void
    {
        $this->guard();
        if (! $this->saveFields(true)) {
            return;
        }
        if ($this->record->openRemarks()->exists() && $this->record->verification_status !== VerificationStatus::NeedsClarification) {
            $this->record->update(['verification_status' => VerificationStatus::NeedsClarification]);
        }
        Notification::make()->success()->title('Зауваження й правки збережено')->body('Можна продовжити пізніше.')->send();
    }

    /** «Перевірити AI» (ТЗ 8.3): незбережені правки спершу зберігаються, повторне натискання блокується. */
    public function runAiCheck(): void
    {
        $this->guard();
        if ($this->aiRunning()) {
            return;
        }
        if (! $this->saveFields(true)) {
            return;
        }
        $check = AiCheck::create([
            'record_id' => $this->record->id, 'user_id' => auth()->id(), 'status' => AiCheckStatus::Queued,
            'record_version' => $this->record->lock_version,
        ]);
        $this->record->update(['ai_check_status' => AiCheckStatus::Queued]);
        Activity::log('ai_check.started', $this->record, 'Відкритих зауважень: '.$this->record->openRemarks()->count());
        $job = app(QueueManager::class)->enqueue(JobType::AiCheck, $check, auth()->user(), priority: 10);
        $check->update(['processing_job_id' => $job->id]);
        unset($this->latestCheck);
    }

    public function pollAi(): void
    {
        unset($this->latestCheck);
        if (! $this->aiRunning()) {
            $this->record->refresh();
            $this->lockVersion = $this->record->lock_version;
            $this->syncEditProposals();
            $c = $this->latestCheck;
            if ($c?->status === AiCheckStatus::Failed) {
                Notification::make()->danger()->title('AI-перевірка не вдалася')->body(($c->error ?: 'Невідома помилка').' Зауваження збережено, можна повторити.')->send();
            } elseif ($c?->status === AiCheckStatus::Ready) {
                $n = $c->proposals()->where('decision', 'pending')->count();
                Notification::make()->success()->title($n ? "AI запропонував змін: {$n}" : 'AI не знайшов розбіжностей')->send();
            }
        }
    }

    public function decide(int $proposalId, string $decision): void
    {
        $this->guard();
        $p = AiCheckProposal::where('record_id', $this->record->id)->findOrFail($proposalId);
        if ($p->decision !== ProposalDecision::Pending) {
            return;
        }
        $value = $decision === ProposalDecision::Edited->value ? ($this->editProposal[$proposalId] ?? $p->new_value) : null;
        try {
            if ($this->isDirty() && ! $this->saveFields(true)) {
                return;
            }
            app(AiCheckService::class)->decide($p, ProposalDecision::from($decision), auth()->user(), $value, $this->lockVersion);
        } catch (StaleRecordException $e) {
            Notification::make()->danger()->title('Конфлікт редагування')->body($e->getMessage())->persistent()->send();

            return;
        }
        $this->fillFromRecord();
    }

    public function decideAll(string $decision): void
    {
        foreach ($this->pendingProposals() as $p) {
            $this->decide($p->id, $decision);
        }
    }

    /** «Підтвердити звірку»: лише працівник, лише після закриття всіх зауважень (ТЗ 8.4). */
    public function confirm(): void
    {
        $this->guard();
        if (! $this->saveFields(true)) {
            return;
        }
        try {
            $this->record->confirmVerification(auth()->user(), $this->lockVersion);
        } catch (StaleRecordException|\DomainException $e) {
            Notification::make()->warning()->title('Звірку не підтверджено')->body($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title('Звірку підтверджено: «Перевірено»')->send();
        if ($next = $this->neighbour(1)) {
            $this->redirect(RecordResource::getUrl('review', ['record' => $next, 'nav' => $this->nav]), navigate: true);

            return;
        }
        $this->fillFromRecord();
    }

    public function neighbour(int $dir): ?Record
    {
        $q = Record::where('book_id', $this->record->book_id)
            ->when($this->nav === 'unchecked', fn ($q) => $q->where('verification_status', '!=', VerificationStatus::Verified->value))
            ->where('sequence', $dir > 0 ? '>' : '<', $this->record->sequence)
            ->orderBy('sequence', $dir > 0 ? 'asc' : 'desc');

        return $q->first();
    }

    public function go(int $dir): void
    {
        if ($this->canEdit() && $this->isDirty() && ! $this->saveFields(true)) {
            return;
        }
        if ($r = $this->neighbour($dir)) {
            $this->redirect(RecordResource::getUrl('review', ['record' => $r, 'nav' => $this->nav]), navigate: true);
        } else {
            Notification::make()->info()->title($dir > 0 ? 'Це останній документ' : 'Це перший документ')
                ->body($this->nav === 'unchecked' ? 'Серед неперевірених документів справи.' : null)->send();
        }
    }
}
