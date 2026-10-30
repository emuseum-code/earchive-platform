<?php

namespace App\Models;

use App\Enums\ExportFormat;
use App\Enums\JobStatus;
use App\Models\Concerns\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Факт формування файлу обміну (ТЗ 11). Приймання реєстром — окрема подія в export_items. */
class Export extends Model
{
    use HasUid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'format' => ExportFormat::class,
            'status' => JobStatus::class,
            'filters' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExportItem::class);
    }
}
