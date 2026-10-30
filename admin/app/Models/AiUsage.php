<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsage extends Model
{
    protected $table = 'ai_usage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['success' => 'boolean', 'cost_usd' => 'decimal:6'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ProcessingJob::class, 'processing_job_id');
    }
}
