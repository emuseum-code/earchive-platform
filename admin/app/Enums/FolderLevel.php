<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Рівень папки (ТЗ 4.1). */
enum FolderLevel: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Territory = 'territory';
    case Museum = 'museum';
    case Fund = 'fund';
    case Book = 'book';
    case Skip = 'skip';

    public function getLabel(): string
    {
        return match ($this) {
            self::Territory => 'Територія',
            self::Museum => 'Музей',
            self::Fund => 'Напрям обліку / фонд',
            self::Book => 'Книга',
            self::Skip => 'Пропустити',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Territory => 'gray',
            self::Museum => 'info',
            self::Fund => 'warning',
            self::Book => 'success',
            self::Skip => 'gray',
        };
    }
}
