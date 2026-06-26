<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фаза продления ввода: primary (первичные результаты, results_deadline) или
 * appeal (добавочные баллы по апелляциям, final_results_deadline). Для ШЭ — primary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olympiad_entry_extensions', function (Blueprint $table) {
            $table->string('phase', 10)->default('primary')->after('olympiad_id');
        });
    }

    public function down(): void
    {
        Schema::table('olympiad_entry_extensions', function (Blueprint $table) {
            $table->dropColumn('phase');
        });
    }
};
