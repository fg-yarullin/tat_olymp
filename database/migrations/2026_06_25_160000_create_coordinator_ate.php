<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Набор АТЕ координатора (мультивыбор). Используется супер-координатором Казани, который ведёт
 * сразу несколько АТЕ (все районы Казани). Заменяет «зонтик» через parent_ate_id: скоуп берётся
 * из этого pivot, а при его отсутствии — из users.ate_id (обычный координатор одного АТЕ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coordinator_ate', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ate_id')->constrained('ates')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'ate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coordinator_ate');
    }
};
