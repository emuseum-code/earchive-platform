<?php

namespace App\Models;

use App\Enums\ProposalDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCheckProposal extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['decision' => 'pending'];

    protected function casts(): array
    {
        return ['decision' => ProposalDecision::class, 'decided_at' => 'datetime'];
    }

    public function aiCheck(): BelongsTo
    {
        return $this->belongsTo(AiCheck::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
