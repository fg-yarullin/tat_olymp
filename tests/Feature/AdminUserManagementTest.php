<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function ate(): Ate
    {
        return Ate::firstOrCreate(['ate_code' => '10'], ['name' => 'АТЕ', 'type' => 'isolated']);
    }

    private function school(): School
    {
        $ate = $this->ate();
        $msu = Msu::firstOrCreate(['msu_code' => '10'], ['name' => 'МСУ', 'ate_id' => $ate->id]);

        return School::create([
            'oo_code' => '10001', 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);
    }

    public function test_users_filtered_by_ate(): void
    {
        $ateA = Ate::firstOrCreate(['ate_code' => 'A'], ['name' => 'Район А', 'type' => 'isolated']);
        $ateB = Ate::firstOrCreate(['ate_code' => 'B'], ['name' => 'Район Б', 'type' => 'isolated']);
        $msuA = \App\Models\Msu::firstOrCreate(['msu_code' => 'A'], ['name' => 'МСУ А', 'ate_id' => $ateA->id]);
        $schoolA = School::create([
            'oo_code' => 'AA1', 'short_name' => 'Школа А', 'full_name' => 'А',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msuA->id, 'msu_code' => 'A', 'ate_id' => $ateA->id, 'ate_code' => 'A',
        ]);

        // Координатор района А (по ate_id)
        User::factory()->create(['name' => 'Коорд А', 'role' => UserRole::MunicipalCoordinator, 'ate_id' => $ateA->id, 'is_active' => true]);
        // Оператор школы в районе А (по school.ate_id)
        User::factory()->create(['name' => 'Оператор А', 'role' => UserRole::SchoolOperator, 'school_id' => $schoolA->id, 'is_active' => true]);
        // Координатор района Б — не должен попасть
        User::factory()->create(['name' => 'Коорд Б', 'role' => UserRole::MunicipalCoordinator, 'ate_id' => $ateB->id, 'is_active' => true]);

        $this->actingAs($this->admin())
            ->get(route('admin.users.index', ['ate_id' => $ateA->id]))
            ->assertInertia(fn ($page) => $page->where('users.data', function ($rows) {
                $names = collect($rows)->pluck('name')->all();

                return in_array('Коорд А', $names, true)
                    && in_array('Оператор А', $names, true)
                    && ! in_array('Коорд Б', $names, true);
            }));
    }

    public function test_non_admin_is_forbidden(): void
    {
        $operator = User::factory()->create(['role' => UserRole::SchoolOperator, 'is_active' => true]);

        $this->actingAs($operator)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_admin_creates_coordinator_with_ate(): void
    {
        $ate = $this->ate();

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Координатор', 'email' => 'coord@x.local', 'password' => 'password123',
                'role' => UserRole::MunicipalCoordinator->value, 'is_active' => true, 'ate_id' => $ate->id,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'email' => 'coord@x.local', 'role' => 'municipal_coordinator', 'ate_id' => $ate->id, 'school_id' => null,
        ]);
    }

    public function test_operator_requires_school_and_clears_ate(): void
    {
        $ate = $this->ate();

        // Без school_id оператор не создаётся
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Оператор', 'email' => 'op@x.local', 'password' => 'password123',
                'role' => UserRole::SchoolOperator->value, 'is_active' => true,
            ])
            ->assertSessionHasErrors('school_id');

        // С school_id создаётся, ate_id обнуляется даже если передан
        $school = $this->school();
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Оператор', 'email' => 'op@x.local', 'password' => 'password123',
                'role' => UserRole::SchoolOperator->value, 'is_active' => true,
                'school_id' => $school->id, 'ate_id' => $ate->id,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'email' => 'op@x.local', 'role' => 'school_operator', 'school_id' => $school->id, 'ate_id' => null,
        ]);
    }

    public function test_admin_updates_user_without_changing_password(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin, 'is_active' => true, 'name' => 'Старое',
        ]);
        $originalHash = $user->password;

        $this->actingAs($this->admin())
            ->put(route('admin.users.update', $user), [
                'name' => 'Новое', 'email' => $user->email, 'password' => '',
                'role' => UserRole::Admin->value, 'is_active' => true,
            ])
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('Новое', $user->name);
        $this->assertSame($originalHash, $user->password); // пароль не тронут
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name, 'email' => $admin->email, 'password' => '',
                'role' => UserRole::Admin->value, 'is_active' => false,
            ])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
