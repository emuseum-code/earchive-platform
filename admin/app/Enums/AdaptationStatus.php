<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Статус адаптації до реєстру (ТЗ 10). */
enum AdaptationStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case None = 'none';
    case Processing = 'processing';
    case NeedsClarification = 'needs_clarification';
    case Adapted = 'adapted';
    case Stale = 'stale';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'Не адаптовано',
            self::Processing => 'Обробляється',
            self::NeedsClarification => 'Потребує уточнення',
            self::Adapted => 'Адаптовано',
            self::Stale => 'Потребує оновлення',
            self::Failed => 'Помилка',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Processing => 'info',
            self::NeedsClarification => 'warning',
            self::Adapted => 'success',
            self::Stale => 'warning',
            self::Failed => 'danger',
        };
    }
}
