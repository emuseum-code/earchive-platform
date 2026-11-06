<?php

namespace App\Models;

use App\Enums\BookType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Шаблон розпізнавання для типу справи (ТЗ 6.2). */
class RecognitionTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'book_type' => BookType::class,
            'columns' => 'array',
            'response_schema' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    protected static function booted(): void
    {
        // Будь-яка зміна промпту чи граф — нова версія шаблону; результати зберігають версію, з якою отримані
        static::updating(function (self $t) {
            if ($t->isDirty(['prompt', 'columns', 'response_schema'])) {
                $t->version = $t->getOriginal('version') + 1;
            }
        });
    }
}
