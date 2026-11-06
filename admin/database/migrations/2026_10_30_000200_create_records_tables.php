<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Розпізнавання, єдина таблиця документів, версії, звірка та AI-перевірка (ТЗ 6–8). */
return new class extends Migration
{
    public function up(): void
    {
        // Фонові завдання: розпізнавання, AI-перевірка, адаптація, експорт, синхронізація (ТЗ 6.3)
        Schema::create('processing_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('type', 20)->index();
            $table->string('status', 20)->default('queued')->index();
            $table->nullableMorphs('target');
            $table->string('batch_key', 64)->nullable()->index()->comment('Одна команда запуску для справи/архіву');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('available_at')->nullable()->comment('Відкладений повтор після тимчасового збою');
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Облік використання Gemini AI і витрат (ТЗ 6.3)
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('processing_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation', 20);
            $table->string('model', 100);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedSmallInteger('images')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('success')->default(true);
            $table->timestamps();
            $table->index(['created_at', 'model']);
        });

        // Сирий результат розпізнавання однієї сторінки (ТЗ 6.2): нова версія при кожній повторній обробці
        Schema::create('recognition_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('processing_job_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recognition_template_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('template_version')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('model', 100);
            $table->json('model_params')->nullable();
            $table->longText('raw_response')->nullable();
            $table->json('parsed')->nullable();
            $table->boolean('schema_valid')->default(false);
            $table->json('validation_errors')->nullable();
            $table->unsignedSmallInteger('records_found')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('scan_checksum', 64)->nullable()->comment('Контрольна сума скану на момент обробки');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['scan_id', 'version']);
        });

        // Єдина таблиця: один рядок = один документ справи (ТЗ 7)
        Schema::create('records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('museum_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recognition_result_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sequence')->default(0)->comment('Порядок документа в справі');
            // Ідентифікація (внутрішній опис документів справи)
            $table->string('record_no', 50)->nullable()->index()->comment('№ документа у справі');
            $table->string('doc_index', 100)->nullable()->index()->comment('Індекс / реєстраційний номер документа');
            $table->string('doc_date', 100)->nullable()->comment('Дата документа, як у документі');
            $table->string('sheets', 50)->nullable()->comment('Аркуші справи, напр. 12–14 зв.');
            $table->string('archive_code', 150)->nullable()->index()->comment('Архівний шифр: ф. оп. спр. арк.');
            // Документ
            $table->string('doc_type', 150)->nullable()->comment('Вид документа');
            $table->text('title')->nullable()->comment('Заголовок');
            $table->text('description')->nullable()->comment('Короткий зміст (анотація)');
            $table->text('author')->nullable()->comment('Автор: установа або особа');
            $table->text('addressee')->nullable();
            $table->string('place')->nullable()->comment('Місце складання');
            $table->string('language', 100)->nullable();
            $table->string('authenticity', 100)->nullable()->comment('Оригінал, копія, чернетка');
            $table->string('reproduction', 100)->nullable()->comment('Рукопис, машинопис, друк');
            // Зміст
            $table->text('persons')->nullable()->comment('Згадані особи');
            $table->text('places')->nullable()->comment('Географічні назви');
            $table->text('organizations')->nullable()->comment('Установи й організації');
            $table->longText('full_text')->nullable()->comment('Повний текст (транскрипція)');
            // Стан та інше
            $table->string('condition')->nullable()->comment('Фізичний стан');
            $table->text('notes')->nullable()->comment('Резолюції, печатки, позначки, примітки');
            // Дані, що не лягли в основні поля, з назвами граф справи (ТЗ 6.2)
            $table->json('extra_fields')->nullable();
            // Нерозбірливі / неоднозначні поля: {field: "причина"}
            $table->json('uncertain_fields')->nullable();
            // Координати фрагментів на скані: {field: {scan_id, x, y, w, h}} у частках 0..1
            $table->json('field_regions')->nullable();
            // Незалежні статуси (ТЗ 10)
            $table->string('verification_status', 30)->default('unchecked')->index();
            $table->string('ai_check_status', 20)->default('none');
            $table->string('adaptation_status', 30)->default('none')->index();
            $table->string('export_status', 20)->default('not_ready')->index();
            $table->boolean('possible_duplicate')->default(false)->index();
            $table->boolean('has_manual_edits')->default(false);
            // Перевірка версії для одночасного редагування (ТЗ 5)
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('verified_version')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['book_id', 'sequence']);
        });

        // Зв’язок документа з джерельними сторінками: кілька документів на сторінці, продовження між сторінками (ТЗ 6.2)
        Schema::create('record_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->boolean('is_continuation')->default(false);
            $table->json('region')->nullable()->comment('Область документа на сторінці {x,y,w,h}');
            $table->timestamps();
            $table->unique(['record_id', 'scan_id']);
        });

        // Історія змін документа: кожна правка, розпізнавання, прийнята AI-пропозиція — окрема версія (ТЗ 7, 8.4)
        Schema::create('record_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('source', 20);
            $table->json('data')->comment('Повний знімок полів');
            $table->json('changes')->nullable()->comment('{field: [old, new]}');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_check_id')->nullable();
            $table->string('comment')->nullable();
            $table->timestamps();
            $table->unique(['record_id', 'version']);
        });

        // Зауваження звірки (ТЗ 8.2)
        Schema::create('review_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30)->default('field');
            $table->string('field', 50)->nullable();
            $table->json('region')->nullable()->comment('Виділена область скану {x,y,w,h} у частках');
            $table->text('comment')->nullable();
            $table->text('current_value')->nullable();
            $table->text('suggested_value')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        // Запуски «Перевірити AI» (ТЗ 8.3)
        Schema::create('ai_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('processing_job_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('record_version')->comment('Версія документа, яку перевіряли');
            $table->boolean('whole_record')->default(false)->comment('Без зауважень — перевірка всього документа');
            $table->json('remark_ids')->nullable();
            $table->json('scan_ids')->nullable();
            $table->json('request')->nullable();
            $table->longText('raw_response')->nullable();
            $table->string('model', 100)->nullable();
            $table->text('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_check_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('record_id')->constrained()->cascadeOnDelete();
            $table->string('field', 50);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('decision', 20)->default('pending');
            $table->text('final_value')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['ai_check_proposals', 'ai_checks', 'review_remarks', 'record_versions', 'record_pages', 'records', 'recognition_results', 'ai_usage', 'processing_jobs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
