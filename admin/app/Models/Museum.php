<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Museum extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public function territory(): BelongsTo
    {
        return $this->belongsTo(Territory::class);
    }

    public function funds(): HasMany
    {
        return $this->hasMany(Fund::class);
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function scopeVisibleTo(Builder $q, ?User $user): Builder
    {
        $ids = $user?->museumScope();

        return $ids === null && $user ? $q : $q->whereIn('museums.id', $ids ?? []);
    }
}
