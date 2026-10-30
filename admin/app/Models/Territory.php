<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Territory extends Model
{
    protected $guarded = ['id'];

    public function museums(): HasMany
    {
        return $this->hasMany(Museum::class);
    }
}
