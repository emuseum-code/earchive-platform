<?php

namespace App\Enums;

trait EnumHelpers
{
    /** [value => label] for selects and filters. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])->all();
    }

    /** Find a case by its Ukrainian label, ignoring case and extra spaces (CSV import). */
    public static function fromLabel(?string $label): ?self
    {
        $norm = fn ($s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $s)));
        foreach (self::cases() as $c) {
            if ($norm($c->getLabel()) === $norm($label) || $c->value === trim((string) $label)) {
                return $c;
            }
        }

        return null;
    }
}
