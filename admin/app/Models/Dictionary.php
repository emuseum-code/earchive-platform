<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Довідник реєстру (класифікація, стан збереження, спосіб надходження, матеріали…). */
class Dictionary extends Model
{
    protected $guarded = ['id'];

    public function items(): HasMany
    {
        return $this->hasMany(DictionaryItem::class)->orderBy('sort')->orderBy('value');
    }

    /** Порівняння без різниці в регістрі, пробілах і видах апострофа. */
    public static function normalize(?string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', str_replace(['’', 'ʼ', '`'], "'", (string) $s)), ' .,;'));
    }

    /** Знайти значення довідника за точною назвою або синонімом з книги. */
    public static function match(string $code, ?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $norm = fn ($s) => self::normalize($s);
        $needle = $norm($raw);
        $items = once(fn () => DictionaryItem::whereHas('dictionary', fn ($q) => $q->where('code', $code))->get());
        foreach ($items as $item) {
            if ($norm($item->value) === $needle) {
                return $item->value;
            }
            foreach ($item->synonyms ?? [] as $syn) {
                if ($norm($syn) === $needle || str_starts_with($needle, $norm($syn))) {
                    return $item->value;
                }
            }
        }

        return null;
    }
}
