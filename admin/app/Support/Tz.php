<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Час зберігається в UTC, а користувачам показується за Києвом. */
class Tz
{
    public const ZONE = 'Europe/Kyiv';

    public static function fmt(?CarbonInterface $time, string $format = 'd.m.Y H:i'): string
    {
        return $time ? $time->copy()->setTimezone(self::ZONE)->format($format) : '';
    }
}
