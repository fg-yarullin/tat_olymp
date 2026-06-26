<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Максимальный балл — свойство олимпиады (определяется после проведения, редактирует
 * только администратор), а не отдельного участия. Переносим max_score на olympiads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->float('max_score')->nullable()->after('status');
        });

        DB::statement('
            UPDATE olympiads o
            SET max_score = (SELECT MAX(h.max_score) FROM human_olympiad h WHERE h.olympiad_id = o.id)
        ');

        Schema::table('human_olympiad', function (Blueprint $table) {
            $table->dropColumn('max_score');
        });
    }

    public function down(): void
    {
        Schema::table('human_olympiad', function (Blueprint $table) {
            $table->float('max_score')->nullable()->after('score');
        });

        Schema::table('olympiads', function (Blueprint $table) {
            $table->dropColumn('max_score');
        });
    }
};
