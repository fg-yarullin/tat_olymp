<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пересмотр модели олимпиады: естественный ключ (год + предмет + этап).
 * Олимпиада уникальна в рамках сезона по предмету и этапу — это исключает дубликаты
 * и делает пакетный импорт идемпотентным (upsert по этому ключу).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->unique(['academic_year_id', 'subject_id', 'stage'], 'olympiads_year_subject_stage_unique');
        });
    }

    public function down(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->dropUnique('olympiads_year_subject_stage_unique');
        });
    }
};
