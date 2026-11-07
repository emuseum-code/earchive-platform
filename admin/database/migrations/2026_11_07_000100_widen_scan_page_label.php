<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Підписи сторінок еАрхіву на кшталт «арк. 5-8, 15, 17-18 зв., 21-22 зв., 24-24 зв, с. 10» довші за 50 символів
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->string('page_label', 255)->nullable()->comment('Аркуш / розворот з справи')->change();
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->string('page_label', 50)->nullable()->comment('Аркуш / розворот з справи')->change();
        });
    }
};
