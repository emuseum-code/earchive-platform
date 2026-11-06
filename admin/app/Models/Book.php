<?php

namespace App\Models;

use App\Enums\BookType;
use App\Enums\FileStatus;
use App\Enums\RecognitionStatus;
use App\Enums\VerificationStatus;
use App\Models\Concerns\HasUid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Облікова справа архіву (ТЗ 4.1). */
class Book extends Model
{
    use HasUid, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['book_type' => BookType::class, 'synced_at' => 'datetime'];
    }

    /** Документи справи завжди в тому самому архіви й фонді, що й справа: після зміни в картці справи вони переносяться разом із нею. */
    protected static function booted(): void
    {
        static::updated(function (self $book) {
            if ($book->wasChanged(['fund_id', 'museum_id'])) {
                // без updated_at і версії документа: змінюється лише прив’язка, не дані звірки
                \Illuminate\Support\Facades\DB::table('records')->where('book_id', $book->id)
                    ->update(['fund_id' => $book->fund_id, 'museum_id' => $book->museum_id]);
            }
        });
    }

    public function museum(): BelongsTo
    {
        return $this->belongsTo(Museum::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RecognitionTemplate::class, 'recognition_template_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class)->orderBy('page_order');
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class)->orderBy('sequence');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function scopeVisibleTo(Builder $q, ?User $user): Builder
    {
        $ids = $user?->bookScope();

        return $ids === null && $user ? $q : $q->whereIn('books.id', $ids ?? []);
    }

    /** Перерахунок лічильників картки справи. */
    public function refreshCounters(): void
    {
        $scans = $this->scans()->reorder()->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when file_status <> 'present' then 1 else 0 end) as missing")
            ->selectRaw('sum(case when recognition_status = ? then 1 else 0 end) as recognized', [RecognitionStatus::Done->value])
            ->selectRaw('sum(case when recognition_status = ? then 1 else 0 end) as failed', [RecognitionStatus::Failed->value])
            ->first();
        $records = Record::where('book_id', $this->id)->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when verification_status = ? then 1 else 0 end) as verified', [VerificationStatus::Verified->value])
            ->selectRaw("sum(case when adaptation_status = 'adapted' then 1 else 0 end) as adapted")
            ->first();
        $this->forceFill([
            'scans_count' => (int) $scans->total,
            'scans_missing' => (int) $scans->missing,
            'scans_recognized' => (int) $scans->recognized,
            'scans_failed' => (int) $scans->failed,
            'records_count' => (int) $records->total,
            'records_verified' => (int) $records->verified,
            'records_adapted' => (int) $records->adapted,
        ])->saveQuietly();
    }

    public function recognitionProgress(): int
    {
        $present = $this->scans_count - $this->scans_missing;

        return $present > 0 ? (int) round($this->scans_recognized * 100 / $present) : 0;
    }

    public function verificationProgress(): int
    {
        return $this->records_count > 0 ? (int) round($this->records_verified * 100 / $this->records_count) : 0;
    }

    public function presentScans(): HasMany
    {
        return $this->scans()->where('file_status', FileStatus::Present->value);
    }
}
