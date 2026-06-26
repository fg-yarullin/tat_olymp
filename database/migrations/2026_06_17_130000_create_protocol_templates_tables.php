<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Конструктор протоколов (Вариант 3): админ собирает колонки протокола под этап и
 * предмет. Шаблон с subject_id = NULL — общий для этапа (фолбэк, если нет под предмет).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protocol_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('stage', ['school', 'municipal', 'regional']);
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->timestamps();

            $table->unique(['stage', 'subject_id']);
        });

        Schema::create('protocol_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('protocol_template_id')->constrained('protocol_templates')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('header');                 // заголовок колонки
            $table->string('group_header')->nullable(); // верхний уровень шапки (для В1…В6 и т.п.)
            $table->string('source_key');             // ключ источника значения (см. ProtocolSources)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('protocol_columns');
        Schema::dropIfExists('protocol_templates');
    }
};
