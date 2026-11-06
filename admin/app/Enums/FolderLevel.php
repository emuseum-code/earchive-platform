<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Рівень папки: територія → архів → фонд → опис → справа. */
enum FolderLevel: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Territory = 'territory';
    case Museum = 'museum';
    case Fund = 'fund';
    case Inventory = 'inventory';
    case Book = 'book';
    case Skip = 'skip';

    public function getLabel(): string
    {
        return match ($this) {
            self::Territory => 'Територія',
            self::Museum => 'Архів',
            self::Fund => 'Фонд',
            self::Inventory => 'Опис',
            self::Book => 'Справа',
            self::Skip => 'Пропустити',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Territory => 'gray',
            self::Museum => 'info',
            self::Fund => 'warning',
            self::Inventory => 'primary',
            self::Book => 'success',
            self::Skip => 'gray',
        };
    }
}
