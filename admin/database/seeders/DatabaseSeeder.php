<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\FolderRule;
use App\Models\RecognitionTemplate;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('ADMIN_PASSWORD') ?: 'password';
        $users = [
            ['admin@archive.test', 'Олена Адміністраторка', Role::Admin, 'Адміністратор системи', false],
            ['operator@archive.test', 'Петро Оператор', Role::Operator, 'Оператор обробки', true],
            ['verifier@archive.test', 'Ірина Верифікаторка', Role::Verifier, 'Архівіст', false],
            ['viewer@archive.test', 'Андрій Переглядач', Role::Viewer, 'Науковий співробітник', false],
        ];
        foreach ($users as [$email, $name, $role, $position, $adapt]) {
            User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => $password, 'role' => $role, 'position' => $position, 'can_adapt_export' => $adapt, 'is_active' => true]);
        }

        if (FolderRule::count() === 0) {
            foreach ([[0, 'territory', 'Область / територія'], [1, 'museum', 'Архів'], [2, 'fund', 'Фонд'], [3, 'inventory', 'Опис']] as [$depth, $level, $c]) {
                FolderRule::create(['depth' => $depth, 'level' => $level, 'priority' => 100, 'comment' => $c]);
            }
            FolderRule::create(['pattern' => '^(_|tmp|temp|корзина)', 'level' => 'skip', 'priority' => 10, 'comment' => 'Службові папки']);
            FolderRule::create(['pattern' => '^(оп(ис)?\\.?\\s*№?\\s*\\d)', 'level' => 'inventory', 'priority' => 20, 'comment' => 'Папка «Опис 1», «оп. 2»']);
        }

        $labels = [
            'record_no' => '№ документа у справі (за внутрішнім описом, якщо є)',
            'doc_index' => 'Індекс або реєстраційний номер документа (№ 45, вих. № 12/3)',
            'doc_date' => 'Дата документа, як написано',
            'sheets' => 'Аркуші справи, на яких документ (за олівцевою нумерацією у правому верхньому куті)',
            'archive_code' => 'Архівний шифр, якщо є на аркуші',
            'doc_type' => 'Вид документа: лист, наказ, протокол, довідка, заява, акт, звіт, анкета…',
            'title' => 'Заголовок за архівними правилами: вид, автор, адресат, про що',
            'description' => 'Короткий зміст (2–3 речення українською)',
            'author' => 'Автор: установа та/або посадова особа, що підписала',
            'addressee' => 'Адресат',
            'place' => 'Місце складання',
            'language' => 'Мова документа',
            'authenticity' => 'Справжність: оригінал, копія, засвідчена копія, чернетка, витяг',
            'reproduction' => 'Спосіб відтворення: рукопис, машинопис, друк, бланк',
            'persons' => 'Згадані особи: прізвище й ініціали, через «; »',
            'places' => 'Географічні назви, через «; »',
            'organizations' => 'Установи й організації, через «; »',
            'full_text' => 'Повний текст документа дослівно (на цій сторінці)',
            'condition' => 'Фізичний стан: згасання тексту, надриви, плями, втрати',
            'notes' => 'Резолюції, печатки, штампи, підписи, позначки на полях',
        ];
        RecognitionTemplate::updateOrCreate(['name' => 'Архівний документ (універсальний)'], [
            'book_type' => 'mixed',
            'description' => 'Справа з різними документами: листи, накази, протоколи, довідки. Один елемент — один документ; документ на кількох аркушах склеюється.',
            'columns' => collect($labels)->values()->map(fn ($label, $i) => [
                'column_no' => $i + 1, 'key' => array_keys($labels)[$i], 'label' => $label, 'maps_to' => array_keys($labels)[$i],
            ])->all(),
            'prompt' => "На зображенні — сторінка (аркуш) архівної справи: машинописні, рукописні чи друковані документи XIX–XX ст.\n"
                ."Розпізнай кожен документ на сторінці й заповни поля опису та повний текст.\n"
                .'Мова може бути українська (зокрема правопис 1920-х), російська з дореформеною орфографією, польська, німецька, їдиш.',
        ]);

        Settings::set('storage.root', storage_path('demo-scans'));
        Settings::set('ai.demo_mode', true);

        $this->call(DemoSeeder::class);
    }
}
