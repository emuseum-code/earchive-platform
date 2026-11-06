<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Рядок таблиці відповідностей поле документа → поле архівного опису (ТЗ 9). */
class AdaptationRule extends Model
{
    public const TRANSFORMS = [
        'copy' => 'Перенести як є',
        'translate' => 'Переклад українською (Gemini AI)',
        'translate_en' => 'Переклад англійською (Gemini AI)',
        'title' => 'Заголовок документа за архівними правилами',
        'date' => 'Нормалізувати дату (РРРР-ММ-ДД)',
        'year_from' => 'Рік «З»',
        'year_to' => 'Рік «До»',
        'sizes' => 'Розміри (H …; W …)',
        'dictionary' => 'Зіставити з довідником',
        'number' => 'Число',
        'money' => 'Грошова сума, грн',
        'regex' => 'Вибрати за регулярним виразом',
        'constant' => 'Стала',
        'ai' => 'Змістовий розбір Gemini AI',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source_fields' => 'array', 'params' => 'array', 'use_ai' => 'boolean', 'is_active' => 'boolean'];
    }

    public function schema(): BelongsTo
    {
        return $this->belongsTo(RegistrySchema::class, 'registry_schema_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if ($r->isDirty(['source_fields', 'transform', 'params', 'target_field', 'use_ai'])) {
                $r->version = $r->getOriginal('version') + 1;
            }
        });
    }
}
