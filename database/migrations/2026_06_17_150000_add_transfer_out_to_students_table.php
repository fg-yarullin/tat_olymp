<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Выбытие учащегося в другую ОО: статус «departed» (выбыл) + куда выбыл
 * (населённый пункт и наименование ОО назначения — свободный текст) и дата выбытия.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE students MODIFY status ENUM('active','graduated','transferring','departed') NOT NULL DEFAULT 'active'");

        Schema::table('students', function (Blueprint $table) {
            $table->string('transfer_settlement')->nullable()->after('status');
            $table->string('transfer_school')->nullable()->after('transfer_settlement');
            $table->date('departed_at')->nullable()->after('transfer_school');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['transfer_settlement', 'transfer_school', 'departed_at']);
        });

        DB::statement("UPDATE students SET status = 'transferring' WHERE status = 'departed'");
        DB::statement("ALTER TABLE students MODIFY status ENUM('active','graduated','transferring') NOT NULL DEFAULT 'active'");
    }
};
