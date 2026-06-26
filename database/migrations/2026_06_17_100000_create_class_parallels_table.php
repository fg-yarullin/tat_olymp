<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Класс-параллели конкретной ОО (напр. 7-А, 7-Б). Уникальны в рамках школы по
 * (класс + буква). Ученик может быть привязан к параллели (nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_parallels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools')->cascadeOnDelete();
            $table->unsignedTinyInteger('grade'); // класс обучения 1–11
            $table->string('letter', 5);           // буква параллели: А, Б, ...
            $table->timestamps();

            $table->unique(['school_id', 'grade', 'letter']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('class_parallel_id')->nullable()->after('real_grade')
                ->constrained('class_parallels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_parallel_id');
        });

        Schema::dropIfExists('class_parallels');
    }
};
