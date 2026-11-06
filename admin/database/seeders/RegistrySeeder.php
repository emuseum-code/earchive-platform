<?php

namespace Database\Seeders;

use App\Models\AdaptationRule;
use App\Models\Dictionary;
use App\Models\RegistrySchema;
use Illuminate\Database\Seeder;

/**
 * Схема архівного опису документа (описова стаття рівня «документ» за ISAD(G) і правилами
 * роботи архівних установ), довідники та таблиця відповідностей полів документа полям опису.
 */
class RegistrySeeder extends Seeder
{
    /** key => [назва, тип, обов’язкове для готовності, довідник] у порядку колонок експорту */
    public const FIELDS = [
        'reference_code' => ['Архівний шифр', 'string', true, null],
        'level' => ['Рівень опису', 'string', false, null],
        'title' => ['Заголовок', 'text', true, null],
        'title_en' => ['Заголовок англійською', 'text', false, null],
        'doc_type' => ['Вид документа', 'dictionary', true, 'doc_type'],
        'date' => ['Дата документа', 'date', false, null],
        'year_from' => ['Рік «З»', 'year', true, null],
        'year_to' => ['Рік «До»', 'year', false, null],
        'creator' => ['Автор (фондоутворювач, установа, особа)', 'text', true, null],
        'addressee' => ['Адресат', 'text', false, null],
        'place' => ['Місце складання', 'string', false, null],
        'scope_content' => ['Короткий зміст', 'text', true, null],
        'extent' => ['Аркуші', 'string', false, null],
        'language' => ['Мова', 'dictionary', true, 'language'],
        'authenticity' => ['Справжність', 'dictionary', false, 'authenticity'],
        'reproduction' => ['Спосіб відтворення', 'dictionary', false, 'reproduction'],
        'persons' => ['Особи', 'text', false, null],
        'places' => ['Географічні назви', 'text', false, null],
        'organizations' => ['Установи й організації', 'text', false, null],
        'physical_condition' => ['Фізичний стан', 'dictionary', false, 'condition'],
        'notes' => ['Примітки', 'text', false, null],
        'full_text' => ['Повний текст', 'text', false, null],
    ];

    public function run(): RegistrySchema
    {
        $dicts = [
            'doc_type' => ['Види документів', [
                ['Наказ', ['приказ']], ['Розпорядження', ['распоряжение']], ['Постанова', ['постановление']], ['Рішення', ['решение']],
                ['Протокол', []], ['Лист', ['письмо', 'отношение', 'відношення']], ['Доповідна записка', ['докладная записка']],
                ['Службова записка', []], ['Заява', ['заявление', 'прошение', 'прохання']], ['Довідка', ['справка']], ['Звіт', ['отчет', 'отчёт']],
                ['Акт', []], ['Анкета', []], ['Автобіографія', ['автобиография']], ['Характеристика', []], ['Посвідчення', ['удостоверение']],
                ['Список', ['перелік']], ['Відомість', ['ведомость']], ['Телеграма', ['телеграмма']], ['Інструкція', ['инструкция']],
                ['Кошторис', ['смета']], ['Договір', ['договор']], ['Метрична книга, запис', ['метрическая запись']], ['Інше', []],
            ]],
            'language' => ['Мови документів', [
                ['українська', ['укр', 'украинский']], ['російська', ['рос', 'русский']], ['польська', ['польский']], ['німецька', ['немецкий']],
                ['їдиш', ['идиш']], ['латинська', ['латынь']], ['французька', []], ['англійська', []],
            ]],
            'authenticity' => ['Справжність', [
                ['Оригінал', ['подлинник', 'справжній']], ['Копія', ['копия']], ['Засвідчена копія', ['заверенная копия']], ['Чернетка', ['черновик', 'проєкт']], ['Витяг', ['выписка']],
            ]],
            'reproduction' => ['Спосіб відтворення', [
                ['Рукопис', ['рукописный', 'рукописний']], ['Машинопис', ['машинописный', 'машинописний']], ['Друк', ['типографский', 'друкований']],
                ['Рукопис і машинопис', []], ['Бланк, заповнений від руки', []], ['Склографія', ['стеклограф']],
            ]],
            'condition' => ['Фізичний стан', [
                ['Добрий', ['хороший', 'добрий', 'задовільний без пошкоджень']], ['Задовільний', ['удовлетворительный']],
                ['Згасання тексту', ['угасание текста', 'згасання']], ['Пошкоджений', ['пошкоджено', 'надриви', 'розриви']], ['Потребує реставрації', []],
            ]],
        ];
        $dictIds = [];
        foreach ($dicts as $code => [$name, $items]) {
            $d = Dictionary::updateOrCreate(['code' => $code], ['name' => $name, 'version' => '2026-11', 'description' => 'Базовий набір; доповнюється в адмінці.']);
            foreach ($items as $i => [$value, $syn]) {
                $d->items()->updateOrCreate(['value' => $value], ['synonyms' => $syn, 'sort' => $i]);
            }
            $dictIds[$code] = $d->id;
        }

        $schema = RegistrySchema::updateOrCreate(['name' => 'Описова стаття документа (ISAD(G))', 'version' => '2026-11'], [
            'is_active' => true,
            'description' => 'Опис архівного документа: шифр, заголовок, дата, автор, зміст, мова, справжність, покажчики. Склад полів погоджується з архівом.',
        ]);
        $letters = fn (int $i) => ($i >= 26 ? chr(64 + intdiv($i, 26)) : '').chr(65 + $i % 26);
        $i = 0;
        foreach (self::FIELDS as $key => [$label, $type, $required, $dict]) {
            $schema->fields()->updateOrCreate(['key' => $key], [
                'label' => $label, 'api_path' => $key, 'type' => $type, 'required' => $required,
                'dictionary_id' => $dict ? $dictIds[$dict] : null, 'sort' => $i, 'column_letter' => $letters($i),
            ]);
            $i++;
        }

        // Таблиця відповідностей: поле документа → поле опису
        $rules = [
            ['reference_code', ['archive_code'], 'copy', null, false, null],
            ['level', [], 'constant', ['value' => 'Документ'], false, null],
            ['title', ['title'], 'copy', null, false, null],
            ['title_en', ['title'], 'translate_en', null, true, 'Заголовок англійською.'],
            ['doc_type', ['doc_type'], 'dictionary', ['dictionary' => 'doc_type'], false, null],
            ['date', ['doc_date'], 'date', null, false, null],
            ['year_from', ['doc_date'], 'year_from', null, false, null],
            ['year_to', ['doc_date'], 'year_to', null, false, null],
            ['creator', ['author'], 'copy', null, false, null],
            ['addressee', ['addressee'], 'copy', null, false, null],
            ['place', ['place'], 'copy', null, false, null],
            ['scope_content', ['description'], 'copy', null, false, null],
            ['extent', ['sheets'], 'copy', null, false, null],
            ['language', ['language'], 'dictionary', ['dictionary' => 'language'], false, null],
            ['authenticity', ['authenticity'], 'dictionary', ['dictionary' => 'authenticity'], false, null],
            ['reproduction', ['reproduction'], 'dictionary', ['dictionary' => 'reproduction'], false, null],
            ['persons', ['persons'], 'copy', null, false, null],
            ['places', ['places'], 'copy', null, false, null],
            ['organizations', ['organizations'], 'copy', null, false, null],
            ['physical_condition', ['condition'], 'dictionary', ['dictionary' => 'condition'], false, null],
            ['notes', ['notes'], 'copy', null, false, null],
            ['full_text', ['full_text'], 'copy', null, false, null],
        ];
        foreach ($rules as $sort => [$target, $src, $transform, $params, $ai, $comment]) {
            AdaptationRule::updateOrCreate(['registry_schema_id' => $schema->id, 'target_field' => $target], [
                'source_fields' => $src, 'transform' => $transform, 'params' => $params, 'use_ai' => $ai, 'sort' => $sort, 'comment' => $comment,
            ]);
        }

        return $schema;
    }
}
