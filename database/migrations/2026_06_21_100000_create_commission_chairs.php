<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Председатель предметной комиссии МЭ: назначение пользователя на муниципальные
 * олимпиады (many-to-many). Шифр участника хранится в human_olympiad.barcode —
 * добавляем уникальность шифра в пределах олимпиады.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_chair_olympiad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('olympiad_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'olympiad_id']);
        });

        Schema::table('human_olympiad', function (Blueprint $table) {
            $table->unique(['olympiad_id', 'barcode'], 'human_olympiad_olympiad_barcode_unique');
        });
    }

    public function down(): void
    {
        Schema::table('human_olympiad', function (Blueprint $table) {
            $table->dropUnique('human_olympiad_olympiad_barcode_unique');
        });
        Schema::dropIfExists('commission_chair_olympiad');
    }
};
