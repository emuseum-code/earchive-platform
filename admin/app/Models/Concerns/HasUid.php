<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/** Стабільний внутрішній ідентифікатор, що не змінюється при повторній синхронізації (ТЗ 4.1). */
trait HasUid
{
    protected static function bootHasUid(): void
    {
        static::creating(fn ($m) => $m->uid ??= (string) Str::uuid());
    }
}
