<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Роль користувача (ТЗ 5). */
enum Role: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Admin = 'admin';
    case Operator = 'operator';
    case Verifier = 'verifier';
    case Viewer = 'viewer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Адміністратор',
            self::Operator => 'Оператор',
            self::Verifier => 'Верифікатор',
            self::Viewer => 'Переглядач',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Operator => 'warning',
            self::Verifier => 'info',
            self::Viewer => 'gray',
        };
    }
}
