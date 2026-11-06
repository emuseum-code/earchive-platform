<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Формат експорту (ТЗ 11). */
enum ExportFormat: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Json = 'json';
    case Registry = 'registry';
    case Api = 'api';

    public function getLabel(): string
    {
        return match ($this) {
            self::Csv => 'CSV',
            self::Xlsx => 'XLSX',
            self::Json => 'JSON',
            self::Registry => 'Архівний опис ISAD(G) (XLSX)',
            self::Api => 'API реєстру',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Csv => 'gray',
            self::Xlsx => 'success',
            self::Json => 'info',
            self::Registry => 'primary',
            self::Api => 'warning',
        };
    }
}
