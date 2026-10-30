<?php

namespace App\Models;

use App\Enums\FolderLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Папка, яку не вдалося зіставити з рівнем дерева: чекає ручного уточнення (ТЗ 4.1). */
class UnmappedFolder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolution' => FolderLevel::class, 'resolved_at' => 'datetime'];
    }

    public function museum(): BelongsTo
    {
        return $this->belongsTo(Museum::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
