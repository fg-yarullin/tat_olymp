<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            // Отметка обезличивания ПДн по ФЗ-152 (ТЗ 4.9.3) — для идемпотентной очистки
            $table->timestamp('anonymized_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('anonymized_at');
        });
    }
};
