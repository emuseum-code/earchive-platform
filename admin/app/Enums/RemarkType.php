<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Тип зауваження звірки (ТЗ 8.2). */
enum RemarkType: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Field = 'field';
    case MissingRecord = 'missing_record';
    case ExtraRecord = 'extra_record';
    case WrongMerge = 'wrong_merge';
    case WrongSplit = 'wrong_split';
    case Continuation = 'continuation';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Field => 'Помилка в полі',
            self::MissingRecord => 'Пропущений документ',
            self::ExtraRecord => 'Зайвий документ',
            self::WrongMerge => 'Помилкове об’єднання',
            self::WrongSplit => 'Помилковий поділ',
            self::Continuation => 'Продовження на іншій сторінці',
            self::Other => 'Інше',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Field => 'danger',
            self::MissingRecord => 'warning',
            self::ExtraRecord => 'warning',
            self::WrongMerge => 'warning',
            self::WrongSplit => 'warning',
            self::Continuation => 'info',
            self::Other => 'gray',
        };
    }
}
