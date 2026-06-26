<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Максимальные баллы по классам выносим из JSON-поля olympiads.max_scores
 * в отдельную таблицу: администратор вносит их по мере поступления от организаторов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olympiad_max_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olympiad_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('grade');
            $table->float('max_score');
            $table->timestamps();
            $table->unique(['olympiad_id', 'grade']);
        });

        // Переносим существующие значения из JSON-карты.
        foreach (DB::table('olympiads')->whereNotNull('max_scores')->get(['id', 'max_scores']) as $o) {
            foreach ((array) json_decode($o->max_scores, true) as $grade => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                DB::table('olympiad_max_scores')->insert([
                    'olympiad_id' => $o->id,
                    'grade' => (int) $grade,
                    'max_score' => (float) $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('olympiads', function (Blueprint $table) {
            $table->dropColumn('max_scores');
        });
    }

    public function down(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->json('max_scores')->nullable()->after('status');
        });

        foreach (DB::table('olympiad_max_scores')->get() as $row) {
            $current = DB::table('olympiads')->where('id', $row->olympiad_id)->value('max_scores');
            $map = $current ? (array) json_decode($current, true) : [];
            $map[(int) $row->grade] = (float) $row->max_score;
            DB::table('olympiads')->where('id', $row->olympiad_id)->update(['max_scores' => json_encode($map)]);
        }

        Schema::dropIfExists('olympiad_max_scores');
    }
};
