<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Добавляет роль «председатель комиссии МЭ» в enum users.role.
 */
return new class extends Migration
{
    private const WITH = "'admin','super_coordinator','municipal_coordinator','commission_chair','school_operator'";
    private const WITHOUT = "'admin','super_coordinator','municipal_coordinator','school_operator'";

    public function up(): void
    {
        DB::statement('ALTER TABLE users MODIFY role ENUM('.self::WITH.') NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users MODIFY role ENUM('.self::WITHOUT.') NOT NULL');
    }
};
