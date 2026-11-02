<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            // content — звичайний розворот; cover — обкладинка (не розпізнається); last — остання сторінка із засвідчувальним написом
            $table->string('page_kind', 10)->default('content')->after('page_label');
        });
    }

    public function down(): void
    {
        Schema::table('scans', fn (Blueprint $table) => $table->dropColumn('page_kind'));
    }
};
