<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сроки внесения результатов в систему.
 *  results_deadline       — школьный этап: крайний срок внесения результатов;
 *                           муниципальный этап: срок внесения первичных результатов.
 *  final_results_deadline — муниципальный этап: крайний срок внесения итоговых
 *                           результатов после рассмотрения апелляций.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->timestamp('results_deadline')->nullable()->after('date_held');
            $table->timestamp('final_results_deadline')->nullable()->after('results_deadline');
        });
    }

    public function down(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->dropColumn(['results_deadline', 'final_results_deadline']);
        });
    }
};
