<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(CatalogSeeder::class);
        $this->call(SubjectSeeder::class);
        $this->call(ProtocolTemplateSeeder::class);
        $this->call(TechReferenceSeeder::class);

        // Первичный администратор: самостоятельная регистрация запрещена (ТЗ 3),
        // поэтому стартовый аккаунт создаётся здесь. Дальше иерархия создаётся им.
        User::updateOrCreate(
            ['email' => 'admin@tat-olymp.local'],
            [
                'name' => 'Администратор',
                'password' => Hash::make('password'),
                'role' => UserRole::Admin,
                'email_verified_at' => now(),
                'is_active' => true,
            ],
        );

        // Демо-аккаунты координаторов/оператора для проверки ролевых кабинетов.
        $this->call(DemoUsersSeeder::class);

        // Демо школьного этапа: текущий год, олимпиады, учащиеся из CSV.
        $this->call(DemoSchoolStageSeeder::class);
    }
}
