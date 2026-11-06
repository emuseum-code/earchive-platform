<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\JobType;
use App\Models\Concerns\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Завдання фонової черги з паузою, скасуванням і повторами (ТЗ 6.3). */
class ProcessingJob extends Model
{
    use HasUid;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'queued', 'attempts' => 0, 'max_attempts' => 3, 'priority' => 100];

    protected function casts(): array
    {
        return [
            'type' => JobType::class,
            'status' => JobStatus::class,
            'payload' => 'array',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usage(): HasMany
    {
        return $this->hasMany(AiUsage::class);
    }

    public function targetLabel(): string
    {
        $t = $this->target;

        return match (true) {
            $t instanceof Scan => $t->book?->title.' · '.$t->filename,
            $t instanceof Record => 'Документ № '.($t->record_no ?: $t->id).' · '.$t->book?->title,
            $t instanceof Book => $t->title,
            $t instanceof Export => 'Експорт '.$t->format?->getLabel(),
            default => '—',
        };
    }
}
