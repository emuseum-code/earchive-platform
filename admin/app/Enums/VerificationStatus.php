<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Статус звірки (ТЗ 10). */
enum VerificationStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Unchecked = 'unchecked';
    case NeedsClarification = 'needs_clarification';
    case Verified = 'verified';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unchecked => 'Не перевірено',
            self::NeedsClarification => 'Потребує уточнення',
            self::Verified => 'Перевірено',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unchecked => 'gray',
            self::NeedsClarification => 'warning',
            self::Verified => 'success',
        };
    }
}
