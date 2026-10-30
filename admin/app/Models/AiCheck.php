<?php

namespace App\Models;

use App\Enums\AiCheckStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Запуск «Перевірити AI» для запису (ТЗ 8.3). */
class AiCheck extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AiCheckStatus::class,
            'whole_record' => 'boolean',
            'remark_ids' => 'array',
            'scan_ids' => 'array',
            'request' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(AiCheckProposal::class);
    }
}
