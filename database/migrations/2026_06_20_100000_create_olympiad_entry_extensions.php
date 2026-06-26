<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Продление ввода результатов школьного этапа: после закрытия по сроку администратор
 * может продлить ввод на N часов (не более 48 ч от срока закрытия) дифференцированно —
 * для всех / конкретного АТЕ / МСУ / школы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olympiad_entry_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olympiad_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 10); // all | ate | msu | school
            $table->foreignId('ate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('msu_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('extended_until');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olympiad_entry_extensions');
    }
};
