<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Облік і синхронізація: дерево територія → архів → фонд → справа → скани (ТЗ 4). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('territories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('koatuu', 20)->nullable();
            $table->string('folder_path', 1024)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('museums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('edrpou', 12)->nullable();
            $table->string('rmfu_id')->nullable()->comment('Код архіву (зовнішній ідентифікатор)');
            $table->string('folder_path', 1024)->nullable()->unique();
            $table->string('contact_person')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('museum_id')->constrained()->cascadeOnDelete();
            $table->string('name')->comment('Фонд');
            $table->string('code', 50)->nullable();
            $table->string('folder_path', 1024)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('recognition_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('book_type', 20)->default('mixed');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            // графи справи: [{key, label, column_no, maps_to}] — те, що не мапиться, йде в extra_fields
            $table->json('columns');
            $table->longText('prompt');
            $table->json('response_schema')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique()->comment('Стабільний внутрішній ідентифікатор');
            $table->foreignId('museum_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recognition_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('book_type', 20)->default('mixed');
            $table->string('inventory_no', 50)->nullable()->comment('Номер опису');
            $table->string('book_number', 50)->nullable()->comment('Номер справи');
            $table->string('years', 50)->nullable()->comment('Роки ведення справи');
            $table->string('language', 30)->nullable();
            $table->string('folder_path', 1024)->nullable()->unique();
            $table->unsignedInteger('expected_pages')->nullable()->comment('Очікувана кількість сторінок, якщо відома');
            // лічильники для картки й огляду (оновлюються сервісом, щоб не рахувати 1,5 млн рядків на кожен запит)
            $table->unsignedInteger('scans_count')->default(0);
            $table->unsignedInteger('scans_missing')->default(0);
            $table->unsignedInteger('scans_recognized')->default(0);
            $table->unsignedInteger('scans_failed')->default(0);
            $table->unsignedInteger('records_count')->default(0);
            $table->unsignedInteger('records_verified')->default(0);
            $table->unsignedInteger('records_adapted')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['museum_id', 'fund_id']);
        });

        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1024)->unique()->comment('Шлях відносно кореневої директорії');
            $table->string('filename');
            $table->unsignedInteger('page_order')->default(0);
            $table->string('page_label', 50)->nullable()->comment('Аркуш / розворот з справи');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamp('file_mtime')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('file_status', 20)->default('present')->index();
            $table->string('recognition_status', 20)->default('none')->index();
            $table->boolean('needs_recheck')->default(false)->comment('Скан змінився після розпізнавання');
            $table->text('error')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('recognized_at')->nullable();
            $table->timestamps();
            $table->index(['book_id', 'page_order']);
        });

        // Правила зіставлення рівнів папок (ТЗ 4.1): глибина або шаблон назви → рівень дерева
        Schema::create('folder_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('depth')->nullable();
            $table->string('pattern')->nullable()->comment('Регулярний вираз для назви папки');
            $table->string('level', 20);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->string('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope_path', 1024)->default('')->comment('Область перевірки; порожньо — все сховище');
            $table->boolean('scheduled')->default(false);
            $table->boolean('with_checksum')->default(false);
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('folders_found')->default(0);
            $table->unsignedInteger('files_found')->default(0);
            $table->unsignedInteger('new_books')->default(0);
            $table->unsignedInteger('new_scans')->default(0);
            $table->unsignedInteger('changed_scans')->default(0);
            $table->unsignedInteger('missing_scans')->default(0);
            $table->unsignedInteger('restored_scans')->default(0);
            $table->unsignedInteger('corrupted_scans')->default(0);
            $table->unsignedInteger('unmapped_folders')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->json('report')->nullable()->comment('Списки нових/змінених/недоступних файлів і помилок');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('unmapped_folders', function (Blueprint $table) {
            $table->id();
            $table->string('path', 1024)->unique();
            $table->unsignedInteger('jpg_count')->default(0);
            $table->foreignId('sync_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('resolution', 20)->nullable()->comment('Обраний рівень після ручного уточнення');
            $table->foreignId('museum_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        // Межі доступу користувачів (ТЗ 5): архіви цілком або окремі справи
        Schema::create('museum_user', function (Blueprint $table) {
            $table->foreignId('museum_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['museum_id', 'user_id']);
        });

        Schema::create('book_user', function (Blueprint $table) {
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['book_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['book_user', 'museum_user', 'unmapped_folders', 'sync_runs', 'folder_rules', 'scans', 'books', 'recognition_templates', 'funds', 'museums', 'territories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
