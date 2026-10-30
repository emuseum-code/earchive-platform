<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettingVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
