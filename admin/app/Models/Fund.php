<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Напрям обліку або фонд музею. */
class Fund extends Model
{
    protected $guarded = ['id'];

    public function museum(): BelongsTo
    {
        return $this->belongsTo(Museum::class);
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}
