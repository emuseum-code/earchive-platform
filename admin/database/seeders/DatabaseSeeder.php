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
            ['admin@books.test', 'Олена Адміністраторка', Role::Admin, 'Адміністратор системи', false],
            ['operator@books.test', 'Петро Оператор', Role::Operator, 'Оператор обробки', true],
            ['verifier@books.test', 'Ірина Верифікаторка', Role::Verifier, 'Зберігач фондів', false],
            ['viewer@books.test', 'Андрій Переглядач', Role::Viewer, 'Науковий співробітник', false],
        ];
        foreach ($users as [$email, $name, $role, $position, $adapt]) {
            User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => $password, 'role' => $role, 'position' => $position, 'can_adapt_export' => $adapt, 'is_active' => true]);
        }

        if (FolderRule::count() === 0) {
            foreach ([[0, 'territory', 'Область / територія'], [1, 'museum', 'Музей'], [2, 'fund', 'Напрям обліку або фонд']] as [$depth, $level, $c]) {
                FolderRule::create(['depth' => $depth, 'level' => $level, 'priority' => 100, 'comment' => $c]);
            }
            FolderRule::create(['pattern' => '^(_|tmp|temp|корзина)', 'level' => 'skip', 'priority' => 10, 'comment' => 'Службові папки']);
        }

        RecognitionTemplate::updateOrCreate(['name' => 'Інвентарна книга, 12 граф'], [
            'book_type' => 'handwritten',
            'description' => 'Типова інвентарна книга радянського зразка (розворот: графи 1–4 ліворуч, 5–12 праворуч).',
            'columns' => collect([
                ['record_no', '№ по порядку'], ['record_date', 'Дата запису'],
                ['acquisition_source', 'Час, джерело і спосіб надходження, супровідні документи, номер акта'],
                ['description', 'Назва і короткий опис речі (автор, назва, дата, місце походження, написи, підписи та ін.)'],
                ['quantity', 'Кількість предметів'], ['material', 'Матеріал і техніка'], ['dimensions', 'Розмір, вага'],
                ['condition', 'Стан речі'], ['price', 'Вартість'], ['department', 'В який відділ або збірку надійшов'],
                ['inventory_number', '№ наукового інвентаря'], ['location', 'Примітка, місцезнаходження предмета'],
            ])->map(fn ($c, $i) => ['column_no' => $i + 1, 'key' => 'c'.($i + 1), 'label' => $c[1], 'maps_to' => $c[0]])->all(),
            'prompt' => "На зображенні — розворот рукописної інвентарної книги музею (таблиця з 12 граф).\n"
                ."Розпізнай кожен обліковий запис (рядок таблиці) і поверни значення граф c1–c12.\n"
                ."Текст переважно російською, рукописний, з дореформеними й радянськими скороченнями.",
        ]);

        Settings::set('storage.root', storage_path('demo-scans'));
        Settings::set('ai.demo_mode', true);

        $this->call(DemoSeeder::class);
    }
}
