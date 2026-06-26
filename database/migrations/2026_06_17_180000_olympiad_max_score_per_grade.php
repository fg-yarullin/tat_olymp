<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Максимальный балл может отличаться по классам (олимпиады обычно для 3–11 классов),
 * поэтому храним карту «класс → макс. балл» в JSON вместо одного значения.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->json('max_scores')->nullable()->after('max_score');
        });

        // Переносим единое значение в карту по всем классам олимпиады.
        foreach (DB::table('olympiads')->whereNotNull('max_score')->get(['id', 'grades', 'max_score']) as $o) {
            $map = [];
            foreach (array_filter(explode(',', (string) $o->grades)) as $g) {
                $map[(int) $g] = (float) $o->max_score;
            }
            DB::table('olympiads')->where('id', $o->id)->update(['max_scores' => json_encode($map)]);
        }

        Schema::table('olympiads', function (Blueprint $table) {
            $table->dropColumn('max_score');
        });
    }

    public function down(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->float('max_score')->nullable()->after('status');
            $table->dropColumn('max_scores');
        });
    }
};
