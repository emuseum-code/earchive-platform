<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordPage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['region' => 'array', 'is_continuation' => 'boolean'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
