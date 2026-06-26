<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Агрегированные исторические итоги (ТЗ 4.9.3): заполняются перед уничтожением ПДн
        Schema::create('historical_stats', function (Blueprint $table) {
            $table->id();
            $table->string('year_name', 9);
            $table->string('ate_code');
            $table->string('msu_code');
            $table->string('oo_code');
            $table->string('subject');
            $table->string('stage');
            $table->integer('total_participants');
            $table->integer('total_prizewinner_diplomas');
            $table->integer('total_winner_diplomas');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_stats');
    }
};
