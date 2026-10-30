<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistryField extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }

    public function schema(): BelongsTo
    {
        return $this->belongsTo(RegistrySchema::class, 'registry_schema_id');
    }

    public function dictionary(): BelongsTo
    {
        return $this->belongsTo(Dictionary::class);
    }
}
