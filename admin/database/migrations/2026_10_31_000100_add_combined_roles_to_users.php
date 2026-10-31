<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ТЗ 18.5: суміщення ролей явно призначає адміністратор (оператор + звірка, верифікатор + обробка)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_verify')->default(false)->after('can_adapt_export');
            $table->boolean('can_operate')->default(false)->after('can_verify');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['can_verify', 'can_operate']);
        });
    }
};
