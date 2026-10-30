<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Обмеження вибірки книгами, доступними користувачу (ТЗ 5; права перевіряються сервером). */
trait ScopedByBook
{
    public function scopeVisibleTo(Builder $q, ?User $user): Builder
    {
        if (! $user) {
            return $q->whereRaw('1 = 0');
        }
        $ids = $user->bookScope();

        return $ids === null ? $q : $q->whereIn($this->getTable().'.'.$this->bookScopeColumn(), $ids);
    }

    protected function bookScopeColumn(): string
    {
        return 'book_id';
    }
}
