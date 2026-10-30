<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Статус файлу скану (ТЗ 10). */
enum FileStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Present = 'present';
    case Missing = 'missing';
    case Corrupted = 'corrupted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Present => 'Є скан',
            self::Missing => 'Недоступний',
            self::Corrupted => 'Пошкоджений',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Present => 'success',
            self::Missing => 'danger',
            self::Corrupted => 'danger',
        };
    }
}
