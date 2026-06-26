<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Уровень (региональный/республиканский) — свойство конкретной олимпиады, а не предмета.
 * Переносим его с subjects на olympiads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('level');
        });

        Schema::table('olympiads', function (Blueprint $table) {
            $table->enum('level', ['regional', 'republican'])->default('regional')->after('stage');
        });

        // Татарские язык/литература — республиканского уровня (по денормализованному названию).
        DB::table('olympiads')
            ->whereIn('subject', ['Татарский язык', 'Татарская литература'])
            ->update(['level' => 'republican']);
    }

    public function down(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->dropColumn('level');
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->enum('level', ['regional', 'republican'])->default('regional')->after('name');
        });
    }
};
