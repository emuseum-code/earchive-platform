<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Тип справи за способом заповнення. */
enum BookType: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Printed = 'printed';
    case Handwritten = 'handwritten';
    case Mixed = 'mixed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Printed => 'Друкована',
            self::Handwritten => 'Рукописна',
            self::Mixed => 'Змішана',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Printed => 'info',
            self::Handwritten => 'warning',
            self::Mixed => 'gray',
        };
    }
}
