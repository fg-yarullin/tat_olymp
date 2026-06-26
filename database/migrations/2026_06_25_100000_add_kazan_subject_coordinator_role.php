<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Роль «Координатор Казани по предмету»: ответственный по предмету(ам) внутри АТЕ Казани с
 * правами муниципального координатора, но только по своим предметам. Назначаемые предметы —
 * pivot kazan_coordinator_subject.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','super_coordinator','kazan_subject_coordinator','roc_representative','roc_subject_coordinator','municipal_coordinator','commission_chair','school_operator') NOT NULL");

        Schema::create('kazan_coordinator_subject', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kazan_coordinator_subject');
        DB::statement("ALTER TABLE users MODIFY role ENUM('admin','super_coordinator','roc_representative','roc_subject_coordinator','municipal_coordinator','commission_chair','school_operator') NOT NULL");
    }
};
