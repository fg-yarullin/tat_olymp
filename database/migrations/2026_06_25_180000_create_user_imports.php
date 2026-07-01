<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фоновый (по частям) импорт пользователей с прогрессом. Файл разбирается при загрузке, строки
 * сохраняются здесь, затем фронтенд обрабатывает их чанками, обновляя прогресс. Так избегаем
 * таймаута на медленном bcrypt-хешировании и показываем прогресс-бар без очереди/воркера.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->json('allowed_roles');
            $table->json('header')->nullable();
            $table->json('rows');           // разобранные строки данных
            $table->json('errors')->nullable(); // накопленные проблемные строки
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status')->default('processing'); // processing | done
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_imports');
    }
};
