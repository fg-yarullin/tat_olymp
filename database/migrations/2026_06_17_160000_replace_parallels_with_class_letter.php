<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Отказ от отдельной модели класс-параллелей: класс хранится прямо в ученике —
 * число (real_grade) + литера (class_letter: прописные буквы и/или цифры, либо пусто).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('class_letter', 10)->nullable()->after('real_grade');
        });

        // Переносим букву из привязанных параллелей.
        if (Schema::hasTable('class_parallels')) {
            DB::statement('
                UPDATE students s
                JOIN class_parallels cp ON cp.id = s.class_parallel_id
                SET s.class_letter = cp.letter
                WHERE s.class_parallel_id IS NOT NULL
            ');
        }

        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_parallel_id');
        });

        Schema::dropIfExists('class_parallels');
    }

    public function down(): void
    {
        Schema::create('class_parallels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->unsignedTinyInteger('grade');
            $table->string('letter', 5);
            $table->timestamps();
            $table->unique(['school_id', 'grade', 'letter']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('class_parallel_id')->nullable()->after('real_grade')
                ->constrained('class_parallels')->nullOnDelete();
            $table->dropColumn('class_letter');
        });
    }
};
