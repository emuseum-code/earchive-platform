<?php

namespace App\Models;

use App\Enums\FolderLevel;
use Illuminate\Database\Eloquent\Model;

class FolderRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['level' => FolderLevel::class, 'is_active' => 'boolean'];
    }
}
