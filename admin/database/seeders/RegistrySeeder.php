<?php

namespace Database\Seeders;

use App\Models\AdaptationRule;
use App\Models\Dictionary;
use App\Models\RegistrySchema;
use Illuminate\Database\Seeder;

/**
 * Шаблон міграції даних РМФУ (42 поля з аркуша «Адаптація для РМФУ»), довідники
 * та таблиця відповідностей граф інвентарної книги полям реєстру.
 */
class RegistrySeeder extends Seeder
{
    /** key => [тип, обов’язкове для готовності, довідник] у порядку колонок шаблону */
    public const FIELDS = [
        'uid' => ['string', false, null],
        'name_uk' => ['text', true, null],
        'name_en' => ['text', false, null],
        'description_uk' => ['text', true, null],
        'description_en' => ['text', false, null],
        'author_person' => ['string', false, null],
        'author_org' => ['string', false, null],
        'classification' => ['dictionary', true, 'classification'],
        'num_inventory' => ['string', true, null],
        'num_inventory_date' => ['date', false, null],
        'num_primary_of' => ['string', false, null],
        'num_primary_of_date' => ['date', false, null],
        'num_special' => ['string', false, null],
        'num_special_date' => ['date', false, null],
        'num_primary_ndf' => ['string', false, null],
        'num_primary_ndf_date' => ['date', false, null],
        'created_place_uk' => ['string', false, null],
        'created_place_la' => ['string', false, null],
        'created_date' => ['date', false, null],
        'created_year_from' => ['year', false, null],
        'created_year_to' => ['year', false, null],
        'found_place_uk' => ['string', false, null],
        'found_place_la' => ['string', false, null],
        'found_date' => ['date', false, null],
        'found_year_from' => ['year', false, null],
        'found_year_to' => ['year', false, null],
        'used_place_uk' => ['string', false, null],
        'used_place_la' => ['string', false, null],
        'used_date' => ['string', false, null],
        'used_year_from' => ['year', false, null],
        'used_year_to' => ['year', false, null],
        'sizes' => ['string', true, null],
        'composition' => ['string', true, null],
        'condition' => ['string', true, null],
        'condition_date' => ['date', false, null],
        'acquisition_method' => ['dictionary', true, 'acquisition_method'],
        'acquisition_source' => ['text', false, null],
        'price_purchase' => ['money', false, null],
        'price_estimated' => ['money', false, null],
        'price_insurance' => ['money', false, null],
        'is_transportable' => ['bool', false, null],
        'topography_code' => ['string', false, null],
    ];

    public function run(array $headers = []): RegistrySchema
    {
        $dicts = [
            'condition' => ['Стан збереження', [
                ['Добрий (без пошкоджень)', ['хорошее', 'хороший', 'добрий', 'отличное']],
                ['Задовільний', ['удовлетворит', 'удовлетворительное', 'задовільний']],
                ['Потребує реставрації', ['требует реставрации', 'обложка оторвана']],
                ['Незадовільний', ['плохое', 'ветхое', 'поганий']],
            ]],
            // заповнюються з тезаурусів РМФУ командою books:thesauri (наприкінці run())
            'acquisition_method' => ['Спосіб надходження', []],
            'classification' => ['Класифікація', []],
            'material' => ['Матеріали', []],
            'technique' => ['Техніки виконання', []],
        ];
        $dictIds = [];
        foreach ($dicts as $code => [$name, $items]) {
            $d = Dictionary::updateOrCreate(['code' => $code], ['name' => $name, 'version' => '2026-10', 'description' => 'Демо-набір; замінюється довідниками реєстру від Замовника.']);
            foreach ($items as $i => [$value, $syn]) {
                $d->items()->updateOrCreate(['value' => $value], ['synonyms' => $syn, 'sort' => $i]);
            }
            $dictIds[$code] = $d->id;
        }

        $schema = RegistrySchema::updateOrCreate(['name' => 'Шаблон міграції даних РМФУ', 'version' => '2026-10'], [
            'is_active' => true,
            'description' => '42 поля з аркуша «Адаптація для РМФУ». Остаточна схема — за документацією реєстру від Замовника.',
        ]);
        $letters = fn (int $i) => ($i >= 26 ? chr(64 + intdiv($i, 26)) : '').chr(65 + $i % 26);
        $i = 0;
        foreach (self::FIELDS as $key => [$type, $required, $dict]) {
            $h = $headers[$i] ?? $key;
            preg_match('/\(([^)]*)\)\s*$/u', $h, $m);
            $schema->fields()->updateOrCreate(['key' => $key], [
                'label' => trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', ltrim($h, '*'))),
                'api_path' => $m[1] ?? null,
                'type' => $type,
                'required' => $required,
                'dictionary_id' => $dict ? $dictIds[$dict] : null,
                'sort' => $i,
                'column_letter' => $letters($i),
                'hint' => str_starts_with($h, '*') && ! $required ? 'У шаблоні позначено *; обов’язковість залежить від типу обліку предмета.' : null,
            ]);
            $i++;
        }

        // Таблиця відповідностей граф книги → поля РМФУ (за звітом про адаптацію)
        $rules = [
            ['uid', ['record_no'], 'regex', ['pattern' => '^(\d+[а-яa-z]?)'], false, null],
            ['name_uk', ['title', 'description'], 'title', null, true, 'Коротка інвентарна назва предмета українською: тип документа, назва, власник; без опису зовнішнього вигляду.'],
            ['name_en', ['title', 'description'], 'translate_en', null, true, 'Назва англійською.'],
            ['description_uk', ['description'], 'translate', null, true, 'Повний опис українською, дослівно за змістом, з датами.'],
            ['description_en', ['description'], 'translate_en', null, true, 'Короткий опис зовнішнього вигляду англійською.'],
            ['author_person', ['description'], 'ai', null, true, 'Автор — фізична особа, якщо зазначено.'],
            ['author_org', ['description'], 'ai', null, true, 'Установа-емітент документа (товариство, університет, курси…).'],
            ['classification', ['description', 'inventory_number', 'department'], 'dictionary', ['dictionary' => 'classification'], true, 'Шифр «Д» — документальний фонд; кілька значень через «; ».'],
            ['num_inventory', ['department', 'inventory_number'], 'regex', ['pattern' => '(Д[0-9]?-?[IVXІ]*-?\d+)'], false, null],
            ['num_inventory_date', ['record_date'], 'date', null, false, null],
            ['num_primary_of', ['department', 'inventory_number'], 'regex', ['pattern' => '(КП[0-9\-]*-?\d+)'], false, null],
            ['num_primary_of_date', ['acquisition_source'], 'date', null, false, null],
            ['created_place_uk', ['description'], 'ai', null, true, 'Місце створення предмета українською (країна, місто).'],
            ['created_place_la', ['description'], 'ai', null, true, 'Те саме латиницею.'],
            ['created_date', ['description'], 'ai', null, true, 'Точна дата видачі/створення РРРР-ММ-ДД, якщо є в описі.'],
            ['created_year_from', ['description'], 'ai', null, true, 'Рік створення «З».'],
            ['created_year_to', ['description'], 'ai', null, true, 'Рік створення «До».'],
            ['used_place_uk', ['description'], 'ai', null, true, 'Місце побутування з біографічних відомостей.'],
            ['used_place_la', ['description'], 'ai', null, true, 'Те саме латиницею.'],
            ['used_date', ['description'], 'ai', null, true, 'Період побутування «РРРР–РРРР».'],
            ['used_year_from', ['description'], 'ai', null, true, null],
            ['used_year_to', ['description'], 'ai', null, true, null],
            ['sizes', ['dimensions'], 'sizes', null, false, null],
            ['composition', ['material', 'description'], 'ai', ['dictionaries' => ['material', 'technique']], true, \App\Console\Commands\ImportThesauri::COMPOSITION],
            ['condition', ['condition'], 'dictionary', ['dictionary' => 'condition'], false, null],
            ['condition_date', ['record_date'], 'date', null, false, null],
            ['acquisition_method', ['acquisition_source'], 'dictionary', ['dictionary' => 'acquisition_method'], true, 'Спосіб надходження за довідником.'],
            ['acquisition_source', ['acquisition_source'], 'translate', null, true, 'Реквізити акта, дарувальник українською.'],
            ['price_purchase', ['price'], 'money', null, false, null],
            ['topography_code', ['location'], 'copy', null, false, null],
        ];
        foreach ($rules as $sort => [$target, $src, $transform, $params, $ai, $comment]) {
            AdaptationRule::updateOrCreate(['registry_schema_id' => $schema->id, 'target_field' => $target], [
                'source_fields' => $src, 'transform' => $transform, 'params' => $params, 'use_ai' => $ai, 'sort' => $sort, 'comment' => $comment,
            ]);
        }

        // класифікації, матеріали, техніки й способи надходження — з тезаурусів РМФУ
        \Illuminate\Support\Facades\Artisan::call('books:thesauri');

        return $schema;
    }
}
