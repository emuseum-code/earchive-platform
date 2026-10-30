<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Статус AI-перевірки (ТЗ 10). */
enum AiCheckStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case None = 'none';
    case Queued = 'queued';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'Не запущено',
            self::Queued => 'У черзі',
            self::Running => 'AI перевіряє…',
            self::Ready => 'Результат готовий',
            self::Failed => 'Помилка',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Queued => 'info',
            self::Running => 'warning',
            self::Ready => 'success',
            self::Failed => 'danger',
        };
    }
}
