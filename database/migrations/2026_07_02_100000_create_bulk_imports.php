<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Общая инфраструктура фонового (по частям) импорта с прогресс-баром — по образцу
 * user_imports, но переиспользуемая для разных доменов (учащиеся, результаты ШЭ/МЭ и т.д.).
 * Файл разбирается целиком при загрузке, строки сохраняются здесь, затем фронтенд обрабатывает
 * их чанками. Так избегаем таймаута на массовых загрузках и показываем честный прогресс без
 * очереди/воркера. `type` — домен импорта, `context` — его параметры (school_id, olympiad_id,
 * смещение строк файла и т.п.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('label');
            $table->json('context')->nullable();
            $table->json('header')->nullable();
            $table->json('rows');
            $table->json('errors')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0); // намеренно без данных (не ошибка)
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status')->default('processing'); // processing | done
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_imports');
    }
};
