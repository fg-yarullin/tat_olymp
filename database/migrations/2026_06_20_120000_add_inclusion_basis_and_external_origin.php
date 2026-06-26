<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Формирование состава МЭ: основание допуска участия (school_stage/prev_municipal/petition)
 * и пометка «из другого региона» у участника (привязан к школе-ходатаю).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('human_olympiad', function (Blueprint $table) {
            $table->string('inclusion_basis', 20)->nullable()->after('result_status');
        });
        Schema::table('students', function (Blueprint $table) {
            $table->boolean('from_other_region')->default(false)->after('status');
            $table->string('origin_region')->nullable()->after('from_other_region');
        });
    }

    public function down(): void
    {
        Schema::table('human_olympiad', function (Blueprint $table) {
            $table->dropColumn('inclusion_basis');
        });
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['from_other_region', 'origin_region']);
        });
    }
};
