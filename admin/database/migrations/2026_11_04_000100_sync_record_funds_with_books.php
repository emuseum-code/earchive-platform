<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Одноразове вирівнювання: записи, розпізнані до того, як книгу перенесли в інший фонд чи музей,
 * отримують фонд і музей своєї книги (далі це робить Book::booted()).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('books')->select('id', 'fund_id', 'museum_id')->orderBy('id')->chunk(200, function ($books) {
            foreach ($books as $b) {
                DB::table('records')->where('book_id', $b->id)->update(['fund_id' => $b->fund_id, 'museum_id' => $b->museum_id]);
            }
        });
    }

    public function down(): void
    {
        // нічого: попередня (помилкова) прив’язка не відновлюється
    }
};
