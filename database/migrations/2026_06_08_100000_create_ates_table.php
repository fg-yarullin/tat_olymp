<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ates', function (Blueprint $table) {
            $table->id();
            $table->string('ate_code')->unique()->index();
            $table->string('name');
            $table->enum('type', ['isolated', 'unified'])->default('isolated');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ates');
    }
};
