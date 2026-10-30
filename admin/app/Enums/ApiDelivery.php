<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Приймання реєстром через API (ТЗ 11). */
enum ApiDelivery: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case NotSent = 'not_sent';
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::NotSent => 'Не надіслано',
            self::Pending => 'Очікує відповіді',
            self::Accepted => 'Прийнято реєстром',
            self::Rejected => 'Відхилено реєстром',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotSent => 'gray',
            self::Pending => 'info',
            self::Accepted => 'success',
            self::Rejected => 'danger',
        };
    }
}
