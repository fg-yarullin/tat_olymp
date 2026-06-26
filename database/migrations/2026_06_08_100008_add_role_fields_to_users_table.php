<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ТЗ 2.8: роль и территориальная привязка учётной записи
            $table->enum('role', [
                'admin', 'super_coordinator', 'municipal_coordinator', 'school_operator',
            ])->after('password');

            // Для муниципального координатора АТЕ
            $table->foreignId('ate_id')->nullable()->after('role')
                ->constrained('ates')->nullOnDelete();

            // Для школьного оператора
            $table->foreignId('school_id')->nullable()->after('ate_id')
                ->constrained('schools')->nullOnDelete();

            // Деактивация аккаунта без удаления (middleware user.active)
            $table->boolean('is_active')->default(true)->after('school_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
            $table->dropConstrainedForeignId('school_id');
            $table->dropConstrainedForeignId('ate_id');
            $table->dropColumn('role');
        });
    }
};
