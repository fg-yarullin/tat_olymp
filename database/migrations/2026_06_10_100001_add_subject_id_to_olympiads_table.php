<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            // Денормализованную строку subject оставляем (отчёты/архив), добавляем нормализованную ссылку.
            $table->foreignId('subject_id')->nullable()->after('subject')
                ->constrained('subjects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('olympiads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_id');
        });
    }
};
