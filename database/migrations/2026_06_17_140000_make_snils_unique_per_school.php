<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * СНИЛС уникален в рамках одной ОО (school_id + snils), а не глобально: школы часто
 * вносят СНИЛС неаккуратно, и глобальная уникальность блокировала реальных учеников
 * из-за совпадения с «придуманным» СНИЛС в другой школе. NULL допускается многократно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_snils_unique');
            $table->unique(['school_id', 'snils'], 'students_school_snils_unique');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_school_snils_unique');
            $table->unique('snils', 'students_snils_unique');
        });
    }
};
