<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Статус розпізнавання (ТЗ 10). */
enum RecognitionStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case None = 'none';
    case Queued = 'queued';
    case Processing = 'processing';
    case Done = 'done';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'Не розпізнано',
            self::Queued => 'У черзі',
            self::Processing => 'Обробляється',
            self::Done => 'Розпізнано',
            self::Failed => 'Помилка',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Queued => 'info',
            self::Processing => 'warning',
            self::Done => 'success',
            self::Failed => 'danger',
        };
    }
}
