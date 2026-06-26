<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Роли РОЦ РТ: представитель и координатор по предмету. Координатору назначаются предметы
 * (pivot roc_coordinator_subject). Роль — MySQL ENUM, поэтому расширяем ALTER TABLE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','super_coordinator','roc_representative','roc_subject_coordinator','municipal_coordinator','commission_chair','school_operator') NOT NULL");

        Schema::create('roc_coordinator_subject', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roc_coordinator_subject');
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','super_coordinator','municipal_coordinator','commission_chair','school_operator') NOT NULL");
    }
};
