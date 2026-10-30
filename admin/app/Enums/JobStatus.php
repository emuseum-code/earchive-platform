<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Стан завдання в черзі (ТЗ 6.3). */
enum JobStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Queued = 'queued';
    case Running = 'running';
    case Paused = 'paused';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Queued => 'У черзі',
            self::Running => 'Виконується',
            self::Paused => 'Призупинено',
            self::Done => 'Виконано',
            self::Failed => 'Помилка',
            self::Cancelled => 'Скасовано',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Queued => 'info',
            self::Running => 'warning',
            self::Paused => 'gray',
            self::Done => 'success',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
