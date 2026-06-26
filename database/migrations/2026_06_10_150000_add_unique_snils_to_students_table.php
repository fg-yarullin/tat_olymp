<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * СНИЛС — естественный ключ ученика для идемпотентного импорта (upsert).
 * MySQL допускает несколько NULL в UNIQUE-индексе, поэтому ученики без СНИЛС
 * не конфликтуют (но через массовый импорт по СНИЛС они отклоняются).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->unique('snils', 'students_snils_unique');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_snils_unique');
        });
    }
};
