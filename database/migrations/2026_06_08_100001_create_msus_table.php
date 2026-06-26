<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('msus', function (Blueprint $table) {
            $table->id();
            $table->string('msu_code')->unique();
            $table->string('name');
            $table->foreignId('ate_id')->constrained('ates');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('msus');
    }
};
