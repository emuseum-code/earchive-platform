<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Рішення щодо AI-пропозиції (ТЗ 8.4). */
enum ProposalDecision: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Edited = 'edited';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Очікує рішення',
            self::Accepted => 'Прийнято',
            self::Rejected => 'Відхилено',
            self::Edited => 'Виправлено вручну',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Accepted => 'success',
            self::Rejected => 'gray',
            self::Edited => 'info',
        };
    }
}
