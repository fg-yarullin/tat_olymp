<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник олимпиады по технологии: направления и виды практик (источник —
 * olymp.odkzn.ru). Виды практик принадлежат направлению; справочник пополняется
 * администратором. Используется для выбора при ручном вводе результатов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tech_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tech_practices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tech_profile_id')->constrained()->cascadeOnDelete();
            $table->string('code')->nullable();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tech_profile_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tech_practices');
        Schema::dropIfExists('tech_profiles');
    }
};
