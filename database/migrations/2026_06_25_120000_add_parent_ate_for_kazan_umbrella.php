<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Зонтичный» АТЕ для Казани: районные АТЕ Казани (коды 54,55,56,60) получают родителя —
 * АТЕ «г. Казань» (код 61). Роли Казани (супер-координатор, ответственные по предметам)
 * скоупятся по своему АТЕ + его дочерним, охватывая все районы. Деление на районы сохраняется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ates', function (Blueprint $table) {
            $table->foreignId('parent_ate_id')->nullable()->after('type')->constrained('ates')->nullOnDelete();
        });

        // Привязка районов Казани к зонтичному АТЕ (по стабильным кодам; no-op, если каталог иной).
        $umbrella = DB::table('ates')->where('ate_code', '61')->value('id');
        if ($umbrella) {
            DB::table('ates')->whereIn('ate_code', ['54', '55', '56', '60'])->update(['parent_ate_id' => $umbrella]);
        }
    }

    public function down(): void
    {
        Schema::table('ates', function (Blueprint $table) {
            $table->dropForeign(['parent_ate_id']);
            $table->dropColumn('parent_ate_id');
        });
    }
};
