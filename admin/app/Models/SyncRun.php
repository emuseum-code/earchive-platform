<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Звіт синхронізації (ТЗ 4.2). */
class SyncRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'scheduled' => 'boolean',
            'with_checksum' => 'boolean',
            'report' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
