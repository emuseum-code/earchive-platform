<?php

namespace App\Models;

use App\Enums\AdaptationStatus;
use App\Enums\AiCheckStatus;
use App\Enums\ExportStatus;
use App\Enums\VerificationStatus;
use App\Enums\VersionSource;
use App\Exceptions\StaleRecordException;
use App\Models\Concerns\HasUid;
use App\Models\Concerns\ScopedByBook;
use App\Support\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/** Обліковий запис книги — рядок єдиної таблиці (ТЗ 7). */
class Record extends Model
{
    use HasUid, ScopedByBook, SoftDeletes;

    /** Основні поля єдиної таблиці: ключ => [назва, група]. Остаточний склад погоджується (ТЗ 16). */
    public const FIELDS = [
        'record_no' => ['№ запису', 'Ідентифікація'],
        'record_date' => ['Дата запису', 'Ідентифікація'],
        'account_number' => ['Обліковий номер (КП)', 'Ідентифікація'],
        'inventory_number' => ['Інвентарний номер', 'Ідентифікація'],
        'title' => ['Назва', 'Предмет'],
        'description' => ['Опис', 'Предмет'],
        'quantity' => ['Кількість', 'Предмет'],
        'material' => ['Матеріал', 'Предмет'],
        'technique' => ['Техніка', 'Предмет'],
        'dimensions' => ['Розміри, вага', 'Предмет'],
        'dating' => ['Датування', 'Предмет'],
        'author' => ['Автор', 'Предмет'],
        'acquisition_date' => ['Дата надходження', 'Надходження та інше'],
        'acquisition_source' => ['Джерело і спосіб надходження', 'Надходження та інше'],
        'provenance' => ['Походження', 'Надходження та інше'],
        'condition' => ['Стан', 'Надходження та інше'],
        'price' => ['Вартість', 'Надходження та інше'],
        'department' => ['Відділ або збірка', 'Надходження та інше'],
        'location' => ['Місцезнаходження', 'Надходження та інше'],
        'notes' => ['Примітки', 'Надходження та інше'],
    ];

    protected $guarded = ['id'];

    protected $attributes = [
        'verification_status' => 'unchecked',
        'ai_check_status' => 'none',
        'adaptation_status' => 'none',
        'export_status' => 'not_ready',
        'possible_duplicate' => false,
        'has_manual_edits' => false,
        'lock_version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'extra_fields' => 'array',
            'uncertain_fields' => 'array',
            'field_regions' => 'array',
            'verification_status' => VerificationStatus::class,
            'ai_check_status' => AiCheckStatus::class,
            'adaptation_status' => AdaptationStatus::class,
            'export_status' => ExportStatus::class,
            'possible_duplicate' => 'boolean',
            'has_manual_edits' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public static function label(string $field): string
    {
        return self::FIELDS[$field][0] ?? $field;
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function museum(): BelongsTo
    {
        return $this->belongsTo(Museum::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function recognitionResult(): BelongsTo
    {
        return $this->belongsTo(RecognitionResult::class);
    }

    public function scans(): BelongsToMany
    {
        return $this->belongsToMany(Scan::class, 'record_pages')
            ->withPivot(['position', 'is_continuation', 'region'])->withTimestamps()->orderBy('record_pages.position');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(RecordPage::class)->orderBy('position');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecordVersion::class)->orderByDesc('version');
    }

    public function remarks(): HasMany
    {
        return $this->hasMany(ReviewRemark::class)->latest();
    }

    public function openRemarks(): HasMany
    {
        return $this->hasMany(ReviewRemark::class)->where('status', 'open');
    }

    public function aiChecks(): HasMany
    {
        return $this->hasMany(AiCheck::class)->latest('id');
    }

    public function adaptations(): HasMany
    {
        return $this->hasMany(Adaptation::class)->latest('version');
    }

    public function currentAdaptation(): HasOne
    {
        return $this->hasOne(Adaptation::class)->where('is_current', true)->latestOfMany('version');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function snapshot(): array
    {
        return collect(self::FIELDS)->keys()->mapWithKeys(fn ($f) => [$f => $this->getAttribute($f)])
            ->put('extra_fields', $this->extra_fields)->all();
    }

    /**
     * Зберегти зміну полів новою версією з перевіркою версії запису (ТЗ 5, 7, 8.4).
     * Кидає StaleRecordException, якщо хтось інший уже зберіг зміни.
     */
    public function applyChanges(array $data, VersionSource $source, ?User $user, ?int $expectedLock = null, ?string $comment = null, ?int $aiCheckId = null): ?RecordVersion
    {
        return DB::transaction(function () use ($data, $source, $user, $expectedLock, $comment, $aiCheckId) {
            $fresh = static::whereKey($this->id)->lockForUpdate()->first();
            if ($expectedLock !== null && $fresh->lock_version !== $expectedLock) {
                throw new StaleRecordException($fresh);
            }
            $allowed = array_merge(array_keys(self::FIELDS), ['extra_fields']);
            $changes = [];
            foreach (array_intersect_key($data, array_flip($allowed)) as $field => $value) {
                $value = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
                if ($fresh->getAttribute($field) != $value) {
                    $changes[$field] = [$fresh->getAttribute($field), $value];
                    $fresh->setAttribute($field, $value);
                }
            }
            if (! $changes) {
                return null;
            }
            $wasVerified = $fresh->verification_status === VerificationStatus::Verified;
            $fresh->lock_version++;
            $fresh->updated_by = $user?->id;
            if ($source !== VersionSource::Recognition) {
                $fresh->has_manual_edits = true;
            }
            // Зміна підтверджених даних потребує повторної звірки та робить адаптацію неактуальною (ТЗ 8.4, 14)
            if ($wasVerified) {
                $fresh->verification_status = VerificationStatus::NeedsClarification;
            }
            if ($fresh->adaptation_status !== AdaptationStatus::None) {
                $fresh->adaptation_status = AdaptationStatus::Stale;
                $fresh->export_status = ExportStatus::NotReady;
                Adaptation::where('record_id', $fresh->id)->where('is_current', true)->update(['status' => AdaptationStatus::Stale->value]);
            }
            $fresh->save();
            $version = $fresh->versions()->create([
                'version' => $fresh->lock_version,
                'source' => $source->value,
                'data' => $fresh->snapshot(),
                'changes' => $changes,
                'user_id' => $user?->id,
                'ai_check_id' => $aiCheckId,
                'comment' => $comment,
            ]);
            Activity::log('record.updated', $fresh, $source->getLabel().': '.implode(', ', array_map([self::class, 'label'], array_keys($changes))), ['changes' => $changes], $user);
            $this->setRawAttributes($fresh->getAttributes(), true);

            return $version;
        });
    }

    /** «Підтвердити звірку»: лише працівник і лише після закриття всіх зауважень (ТЗ 8.4). */
    public function confirmVerification(User $user, ?int $expectedLock = null): void
    {
        DB::transaction(function () use ($user, $expectedLock) {
            $fresh = static::whereKey($this->id)->lockForUpdate()->first();
            if ($expectedLock !== null && $fresh->lock_version !== $expectedLock) {
                throw new StaleRecordException($fresh);
            }
            if ($fresh->openRemarks()->exists()) {
                throw new \DomainException('Спочатку закрийте всі зауваження до запису.');
            }
            if (AiCheckProposal::where('record_id', $fresh->id)->where('decision', 'pending')->exists()) {
                throw new \DomainException('Є AI-пропозиції без рішення: прийміть або відхиліть їх.');
            }
            $fresh->forceFill([
                'verification_status' => VerificationStatus::Verified,
                'verified_by' => $user->id,
                'verified_at' => now(),
                'verified_version' => $fresh->lock_version,
            ])->save();
            $fresh->versions()->updateOrCreate(['version' => $fresh->lock_version], [
                'source' => VersionSource::Verification->value, 'data' => $fresh->snapshot(), 'user_id' => $user->id,
                'comment' => 'Звірку підтверджено',
            ]);
            Activity::log('record.verified', $fresh, 'Підтверджено звірку, версія '.$fresh->lock_version, [], $user);
            $this->setRawAttributes($fresh->getAttributes(), true);
        });
        $this->book->refreshCounters();
    }

    /** Чи готовий запис до експорту в реєстр (ТЗ 9). */
    public function isExportReady(): bool
    {
        $a = $this->currentAdaptation;

        return $this->verification_status === VerificationStatus::Verified
            && $a && $a->status === AdaptationStatus::Adapted && $a->errors_count === 0
            && $a->source_record_version === $this->lock_version;
    }

    public function pageLabel(): string
    {
        return $this->scans->map(fn ($s) => $s->page_label ?: $s->filename)->implode(', ');
    }
}
