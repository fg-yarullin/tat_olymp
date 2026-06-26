<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поля для протокола школьного этапа (на уровне участия): максимальный балл,
 * признак «призёр муниципального этапа прошлого года», ФИО учителя и место его работы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('human_olympiad', function (Blueprint $table) {
            $table->float('max_score')->nullable()->after('score');
            $table->boolean('prev_municipal_winner')->default(false)->after('result_status');
            $table->string('teacher_name')->nullable()->after('prev_municipal_winner');
            $table->string('teacher_workplace')->nullable()->after('teacher_name');
        });
    }

    public function down(): void
    {
        Schema::table('human_olympiad', function (Blueprint $table) {
            $table->dropColumn(['max_score', 'prev_municipal_winner', 'teacher_name', 'teacher_workplace']);
        });
    }
};
