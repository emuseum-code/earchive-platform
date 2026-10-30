<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Тип фонового завдання. */
enum JobType: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Sync = 'sync';
    case Recognize = 'recognize';
    case AiCheck = 'ai_check';
    case Adapt = 'adapt';
    case Export = 'export';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sync => 'Синхронізація',
            self::Recognize => 'Розпізнавання',
            self::AiCheck => 'AI-перевірка',
            self::Adapt => 'Адаптація',
            self::Export => 'Експорт',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sync => 'gray',
            self::Recognize => 'info',
            self::AiCheck => 'primary',
            self::Adapt => 'warning',
            self::Export => 'success',
        };
    }
}
