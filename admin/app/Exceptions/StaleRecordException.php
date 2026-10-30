<?php

namespace App\Exceptions;

use App\Models\Record;

/** Запис змінив інший працівник після того, як його відкрили (ТЗ 5: перевірка версії). */
class StaleRecordException extends \RuntimeException
{
    public function __construct(public Record $fresh)
    {
        $by = $fresh->updated_by ? \App\Models\User::find($fresh->updated_by)?->name : null;
        parent::__construct('Запис уже змінено'.($by ? ' користувачем '.$by : '').' (версія '.$fresh->lock_version.'). Перегляньте актуальні дані й повторіть правку.');
    }
}
