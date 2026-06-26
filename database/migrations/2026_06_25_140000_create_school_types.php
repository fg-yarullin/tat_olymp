<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник «Типы ОО» (организационно-правовая форма школы) — 3-я цифра кода ОО (по аналогии с
 * кодами ГИА/РБД). Каждому типу соответствует цифра 0–9. Код школы собирается автоматически как
 * msu_code(2) + цифра типа(1) + порядковый(3); ручного ввода кода нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('digit')->unique(); // 3-я цифра кода ОО
            $table->string('name');
            $table->timestamps();
        });

        // Засев типами, встречающимися в данных. Цифра 4 — известна; остальные — заготовки.
        $names = [
            0 => 'Тип 0', 1 => 'Тип 1', 2 => 'Тип 2', 3 => 'Тип 3',
            4 => 'Государственная СОШ', 5 => 'Тип 5', 6 => 'Тип 6', 9 => 'Тип 9',
        ];
        foreach ($names as $digit => $name) {
            DB::table('school_types')->insert(['digit' => $digit, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('schools', function (Blueprint $table) {
            $table->foreignId('school_type_id')->nullable()->after('education_level')->constrained('school_types')->nullOnDelete();
        });

        // Бэкфилл: тип существующих школ — по 3-й цифре их oo_code.
        DB::statement('
            UPDATE schools s
            JOIN school_types t ON t.digit = CAST(SUBSTRING(s.oo_code, 3, 1) AS UNSIGNED)
            SET s.school_type_id = t.id
        ');
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropForeign(['school_type_id']);
            $table->dropColumn('school_type_id');
        });
        Schema::dropIfExists('school_types');
    }
};
