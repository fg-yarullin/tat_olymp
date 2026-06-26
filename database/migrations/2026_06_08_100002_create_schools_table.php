<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('oo_code')->unique();
            $table->string('short_name');
            $table->text('full_name');
            // Уровень образовательной программы: 1 — начальная, 2 — основная, 3 — средняя/полная
            $table->unsignedTinyInteger('education_level');
            $table->enum('territorial_sign', ['city', 'rural']);

            $table->foreignId('msu_id')->constrained('msus');
            $table->string('msu_code')->index(); // денормализация для отчётов

            $table->foreignId('ate_id')->constrained('ates');
            $table->string('ate_code')->index(); // денормализация для отчётов

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
