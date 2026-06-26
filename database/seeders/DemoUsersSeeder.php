<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Демо-аккаунты для ручной проверки ролевых кабинетов (ТЗ 5).
 * АТЕ/школы берутся динамически из засеянного каталога — без хардкода кодов
 * (коды Казани в исходных данных нестабильны, см. catalog-seeding).
 * Все пароли — «password».
 */
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (Ate::count() === 0 || School::count() === 0) {
            $this->command?->warn('DemoUsersSeeder: каталог пуст, демо-аккаунты пропущены.');

            return;
        }

        // Казань = АТЕ с наибольшим числом МСУ (единственная, разбитая на районы).
        $kazanAteId = Msu::query()
            ->selectRaw('ate_id, COUNT(*) as msu_count')
            ->groupBy('ate_id')
            ->orderByDesc('msu_count')
            ->value('ate_id');
        $kazanAte = Ate::find($kazanAteId);

        // Муниципальный координатор — любая обычная (не казанская) АТЕ.
        $municipalAte = Ate::where('id', '!=', $kazanAteId)->orderBy('id')->first() ?? $kazanAte;

        // Школьный оператор — школа в АТЕ муниципального координатора.
        $school = School::where('ate_id', $municipalAte->id)->orderBy('id')->first()
            ?? School::orderBy('id')->first();

        $this->makeUser('super.kazan@tat-olymp.local', 'Супер-координатор (Казань)',
            UserRole::SuperCoordinator, ['ate_id' => $kazanAte?->id]);

        $this->makeUser('coord.ate@tat-olymp.local', "Координатор АТЕ ({$municipalAte->name})",
            UserRole::MunicipalCoordinator, ['ate_id' => $municipalAte->id]);

        $this->makeUser('school@tat-olymp.local', "Школьный оператор ({$school->short_name})",
            UserRole::SchoolOperator, ['school_id' => $school->id]);

        $this->command?->info('DemoUsersSeeder: демо-аккаунты созданы (пароль «password»).');
    }

    private function makeUser(string $email, string $name, UserRole $role, array $extra): void
    {
        User::updateOrCreate(['email' => $email], array_merge([
            'name' => $name,
            'password' => Hash::make('password'),
            'role' => $role,
            'email_verified_at' => now(),
            'is_active' => true,
        ], $extra));
    }
}
