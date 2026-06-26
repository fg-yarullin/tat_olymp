<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Достаточно порога «призёр»: победитель — это участник(и) с максимальным баллом
 * по предмету в рамках школы, статус «победитель» школьный оператор ставит вручную.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olympiad_status_thresholds', function (Blueprint $table) {
            $table->dropColumn('winner_from');
        });
    }

    public function down(): void
    {
        Schema::table('olympiad_status_thresholds', function (Blueprint $table) {
            $table->float('winner_from')->nullable()->after('prize_from');
        });
    }
};
