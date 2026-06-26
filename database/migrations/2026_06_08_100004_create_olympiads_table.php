<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olympiads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained('academic_years');
            $table->string('subject');
            $table->enum('stage', ['school', 'municipal', 'regional']);
            $table->date('date_held');
            $table->enum('status', ['planned', 'grading', 'appeal', 'published'])->default('planned');
            // Момент публикации результатов — точка отсчёта 48-часового окна показа (ТЗ 4.6)
            $table->timestamp('published_at')->nullable();

            // Доп. требование: учитель-тренер по предмету и его место работы
            $table->string('trainer_name')->nullable();
            $table->string('trainer_workplace')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olympiads');
    }
};
