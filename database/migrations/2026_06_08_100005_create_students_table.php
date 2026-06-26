<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('fio');
            $table->date('birth_date');
            $table->string('snils', 14)->nullable();
            $table->foreignId('school_id')->constrained('schools');
            $table->unsignedTinyInteger('real_grade'); // 1-11
            $table->enum('status', ['active', 'graduated', 'transferring'])->default('active');
            $table->timestamps();

            // Критический составной индекс для гостевого входа по ФИО + дате рождения (ТЗ 4.6)
            $table->index(['fio', 'birth_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
