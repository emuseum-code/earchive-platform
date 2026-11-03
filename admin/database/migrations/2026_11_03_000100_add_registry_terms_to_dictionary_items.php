<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Терміни тезаурусів РМФУ: англійська назва і позначка «має підпункти»; code = ID терміна в реєстрі. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dictionary_items', function (Blueprint $table) {
            $table->string('value_en')->nullable()->after('value');
            $table->boolean('is_group')->default(false)->after('parent_id')->comment('Термін має підпункти (не кінцевий)');
            $table->index(['dictionary_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('dictionary_items', function (Blueprint $table) {
            $table->dropIndex(['dictionary_id', 'code']);
            $table->dropColumn(['value_en', 'is_group']);
        });
    }
};
