<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            // Уровень предмета: региональный (по умолчанию) или республиканский.
            $table->enum('level', ['regional', 'republican'])->default('regional')->after('name');
        });

        // Татарский язык и литература — республиканского уровня.
        DB::table('subjects')
            ->whereIn('name', ['Татарский язык', 'Татарская литература'])
            ->update(['level' => 'republican']);
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
