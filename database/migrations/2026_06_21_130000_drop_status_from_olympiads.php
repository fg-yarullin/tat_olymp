<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Удаляет поле `status` олимпиады. Состояние «опубликовано» определяется по `published_at`,
 * «открыт/закрыт ввод» — по срокам (results_deadline/final_results_deadline + продления).
 * Прежние значения planned/grading вели себя одинаково, appeal не использовался.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->enum('status', ['planned', 'grading', 'appeal', 'published'])
                ->default('planned')->after('date_held');
        });
    }
};
