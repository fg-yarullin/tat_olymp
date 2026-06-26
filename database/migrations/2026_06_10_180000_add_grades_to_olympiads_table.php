<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Классы участия в олимпиаде. Позволяет делить один предмет на варианты по классам
 * (напр. Математика 4–6 и 7–11 в одном этапе) — поэтому ключ уникальности расширяется
 * до (год + предмет + этап + классы). Хранится канонической строкой «4,5,6».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->string('grades', 40)->default('1,2,3,4,5,6,7,8,9,10,11')->after('stage');
        });

        // Сначала добавляем новый уникальный индекс (тоже начинается с academic_year_id,
        // поэтому покрывает внешний ключ), затем удаляем старый — иначе MySQL не даст
        // удалить индекс, на который опирается FK.
        Schema::table('olympiads', function (Blueprint $table) {
            $table->unique(
                ['academic_year_id', 'subject_id', 'stage', 'grades'],
                'olympiads_year_subject_stage_grades_unique',
            );
        });

        Schema::table('olympiads', function (Blueprint $table) {
            $table->dropUnique('olympiads_year_subject_stage_unique');
        });
    }

    public function down(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->unique(
                ['academic_year_id', 'subject_id', 'stage'],
                'olympiads_year_subject_stage_unique',
            );
        });
        Schema::table('olympiads', function (Blueprint $table) {
            $table->dropUnique('olympiads_year_subject_stage_grades_unique');
            $table->dropColumn('grades');
        });
    }
};
