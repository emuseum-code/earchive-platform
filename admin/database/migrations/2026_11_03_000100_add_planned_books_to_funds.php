<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Скільки справ у групі збереження за переліком архіву (для прогресу оцифрування). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->unsignedSmallInteger('planned_books')->nullable()->after('code')->comment('Справ за переліком');
        });
    }

    public function down(): void
    {
        Schema::table('funds', fn (Blueprint $table) => $table->dropColumn('planned_books'));
    }
};
