<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пороги статусов (призёр/победитель) по классам участия — единый стандарт,
 * задаётся администратором в абсолютных баллах. Плюс режим, кто расставляет статусы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olympiad_status_thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olympiad_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('grade');
            $table->float('prize_from')->nullable();
            $table->float('winner_from')->nullable();
            $table->timestamps();
            $table->unique(['olympiad_id', 'grade']);
        });

        Schema::table('olympiads', function (Blueprint $table) {
            // operator (по умолчанию) — расставляет школьный оператор; admin — только администратор.
            $table->string('auto_status_mode', 20)->default('operator')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->dropColumn('auto_status_mode');
        });
        Schema::dropIfExists('olympiad_status_thresholds');
    }
};
