<?php

namespace App\Models;

use App\Enums\VersionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source' => VersionSource::class, 'data' => 'array', 'changes' => 'array'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
