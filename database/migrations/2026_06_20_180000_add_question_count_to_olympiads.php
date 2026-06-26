<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Количество заданий олимпиады (для ввода баллов по заданиям, напр. астрономия В1…В6).
 * 0 — баллы по заданиям не вводятся (обычный единый балл).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->unsignedTinyInteger('question_count')->default(0)->after('grades');
        });
    }

    public function down(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->dropColumn('question_count');
        });
    }
};
