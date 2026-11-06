<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Адаптація до архівного опису, довідники, експорт, налаштування та журнал (ТЗ 9, 11, 12). */
return new class extends Migration
{
    public function up(): void
    {
        // Цільова схема реєстру (архівний опис)
        Schema::create('registry_schemas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('version', 20);
            $table->boolean('is_active')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['name', 'version']);
        });

        Schema::create('registry_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registry_schema_id')->constrained()->cascadeOnDelete();
            $table->string('key', 80)->comment('Ключ поля в шаблоні (name_uk, numbers_inventory …)');
            $table->string('label');
            $table->string('api_path')->nullable()->comment('Шлях в API реєстру (name/uk, events/type1/date …)');
            $table->string('type', 20)->default('string')->comment('string, text, date, year, number, money, bool, dictionary');
            $table->boolean('required')->default(false);
            $table->foreignId('dictionary_id')->nullable();
            $table->string('group')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('column_letter', 3)->nullable();
            $table->text('hint')->nullable();
            $table->timestamps();
            $table->unique(['registry_schema_id', 'key']);
        });

        Schema::create('dictionaries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('version', 20)->default('1');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('dictionary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dictionary_id')->constrained()->cascadeOnDelete();
            $table->string('value');
            $table->string('code', 50)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('dictionary_items')->nullOnDelete();
            $table->json('synonyms')->nullable()->comment('Варіанти з справ: «хорошее», «удовлетворит.» …');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['dictionary_id', 'value']);
        });

        // Таблиця відповідностей поле справи → поле реєстру (ТЗ 9)
        Schema::create('adaptation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registry_schema_id')->constrained()->cascadeOnDelete();
            $table->string('target_field', 80);
            $table->json('source_fields')->comment('Поля документа справи');
            $table->string('transform', 30)->default('copy')
                ->comment('copy, translate, date, year_from, year_to, sizes, dictionary, number, money, regex, constant, ai');
            $table->json('params')->nullable();
            $table->boolean('use_ai')->default(false)->comment('Gemini AI для змістового розбору складеного опису');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        // Адаптоване представлення документа у форматі реєстру; джерельні та цільові значення окремо (ТЗ 9)
        Schema::create('adaptations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registry_schema_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('source_record_version')->comment('Версія джерельного документа');
            $table->unsignedInteger('rules_version')->default(1);
            $table->string('status', 30)->default('processing')->index();
            $table->boolean('is_current')->default(true)->index();
            $table->json('values')->comment('{target_field: value}');
            $table->json('sources')->nullable()->comment('{target_field: {fields, rule, raw}}');
            $table->json('issues')->nullable()->comment('[{field, level: error|warning|ambiguity, message}]');
            $table->unsignedSmallInteger('errors_count')->default(0);
            $table->unsignedSmallInteger('warnings_count')->default(0);
            $table->longText('ai_raw')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('format', 20);
            $table->string('status', 20)->default('queued');
            $table->json('filters')->nullable()->comment('Фільтр або вибрані документи');
            $table->foreignId('registry_schema_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('records_count')->default(0);
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Склад і версії експортованих документів; для API — зовнішні ідентифікатори та відповіді (ТЗ 11)
        Schema::create('export_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('export_id')->constrained()->cascadeOnDelete();
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('record_version');
            $table->foreignId('adaptation_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('adaptation_version')->nullable();
            $table->string('external_id')->nullable()->index();
            $table->string('api_status', 20)->default('not_sent');
            $table->json('api_response')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();
        });

        // Налаштування системи з версіями (сховище, Gemini AI, ліміти, бюджет)
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('setting_versions', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->unsignedInteger('version');
            $table->json('value')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Журнал дій користувачів, синхронізацій, AI, шаблонів, адаптацій, експортів (ТЗ 12)
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50)->index();
            $table->nullableMorphs('subject');
            $table->text('description')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        foreach (['activity_log', 'setting_versions', 'settings', 'export_items', 'exports', 'adaptations', 'adaptation_rules', 'dictionary_items', 'dictionaries', 'registry_fields', 'registry_schemas'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
