<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Статус експорту (ТЗ 10). */
enum ExportStatus: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case NotReady = 'not_ready';
    case Ready = 'ready';
    case Exported = 'exported';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::NotReady => 'Не готово',
            self::Ready => 'Готово',
            self::Exported => 'Експортовано',
            self::Failed => 'Помилка',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NotReady => 'gray',
            self::Ready => 'info',
            self::Exported => 'success',
            self::Failed => 'danger',
        };
    }
}
