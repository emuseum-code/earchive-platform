<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Стан зауваження. */
enum RemarkStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Відкрите',
            self::Resolved => 'Закрите',
            self::Dismissed => 'Відхилене',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Resolved => 'success',
            self::Dismissed => 'gray',
        };
    }
}
