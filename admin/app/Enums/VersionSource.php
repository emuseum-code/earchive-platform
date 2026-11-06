<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Джерело версії документа. */
enum VersionSource: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Recognition = 'recognition';
    case Manual = 'manual';
    case AiAccepted = 'ai_accepted';
    case Verification = 'verification';
    case Import = 'import';

    public function getLabel(): string
    {
        return match ($this) {
            self::Recognition => 'Розпізнавання Gemini AI',
            self::Manual => 'Ручне редагування',
            self::AiAccepted => 'Прийняті AI-пропозиції',
            self::Verification => 'Підтвердження звірки',
            self::Import => 'Імпорт',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Recognition => 'info',
            self::Manual => 'gray',
            self::AiAccepted => 'primary',
            self::Verification => 'success',
            self::Import => 'gray',
        };
    }
}
