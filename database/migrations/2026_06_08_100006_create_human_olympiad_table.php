<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Таблица намеренно в единственном числе: «человеко-олимпиада» (участие ученика)
        Schema::create('human_olympiad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('olympiad_id')->constrained('olympiads');
            $table->unsignedTinyInteger('participation_grade');
            $table->string('barcode')->nullable()->unique();
            $table->float('score')->nullable();
            $table->enum('result_status', [
                'participant', 'appealed', 'prize_winner', 'winner', 'disqualified',
            ])->default('participant');
            $table->string('scan_path')->nullable(); // ссылка на файл в Storage
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('human_olympiad');
    }
};
