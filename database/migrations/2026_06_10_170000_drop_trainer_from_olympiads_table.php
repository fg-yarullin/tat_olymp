<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Поля наставника в олимпиаде не нужны — удаляем. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->dropColumn(['trainer_name', 'trainer_workplace']);
        });
    }

    public function down(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->string('trainer_name')->nullable();
            $table->string('trainer_workplace')->nullable();
        });
    }
};
