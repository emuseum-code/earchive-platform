<?php

namespace App\Models;

use App\Enums\AdaptationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Представлення запису у форматі реєстру; джерельні дані лишаються в Record (ТЗ 9). */
class Adaptation extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['lock_version' => 1, 'is_current' => true, 'errors_count' => 0, 'warnings_count' => 0];

    protected function casts(): array
    {
        return [
            'status' => AdaptationStatus::class,
            'is_current' => 'boolean',
            'values' => 'array',
            'sources' => 'array',
            'issues' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function schema(): BelongsTo
    {
        return $this->belongsTo(RegistrySchema::class, 'registry_schema_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isStale(): bool
    {
        return $this->status === AdaptationStatus::Stale || $this->source_record_version !== $this->record?->lock_version;
    }
}
