<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Порог приглашения на МЭ: минимальный балл ШЭ по классам участия, который задаёт
 * координатор АТЕ. Своё значение для каждой пары (олимпиада МЭ, АТЕ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('municipal_invitation_thresholds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olympiad_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ate_id')->constrained()->cascadeOnDelete();
            $table->json('min_scores')->nullable(); // карта «класс участия → мин. балл ШЭ»
            $table->timestamps();
            $table->unique(['olympiad_id', 'ate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('municipal_invitation_thresholds');
    }
};
