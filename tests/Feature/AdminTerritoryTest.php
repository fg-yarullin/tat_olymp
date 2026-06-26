<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTerritoryTest extends TestCase
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

    private function ate(string $code = '10'): Ate
    {
        return Ate::firstOrCreate(['ate_code' => $code], ['name' => 'АТЕ '.$code, 'type' => 'isolated']);
    }

    private function msu(Ate $ate, string $code = '10'): Msu
    {
        return Msu::firstOrCreate(['msu_code' => $code], ['name' => 'МСУ '.$code, 'ate_id' => $ate->id]);
    }

    public function test_school_store_autogenerates_oo_code_from_msu_and_type(): void
    {
        $ate = $this->ate('77');
        $msu = $this->msu($ate, '77');
        $type = \App\Models\SchoolType::where('digit', 4)->firstOrFail(); // засеян миграцией

        $this->actingAs($this->admin())
            ->post(route('admin.territory.school.store'), [
                'short_name' => 'Гимназия', 'full_name' => 'Гимназия №1',
                'education_level' => 3, 'territorial_sign' => 'city', 'msu_id' => $msu->id,
                'school_type_id' => $type->id,
            ])
            ->assertSessionHas('success');

        // Код собран автоматически: msu(77) + тип(4) + порядковый(001).
        $this->assertDatabaseHas('schools', [
            'oo_code' => '774001', 'msu_id' => $msu->id, 'msu_code' => '77',
            'ate_id' => $ate->id, 'ate_code' => '77', 'school_type_id' => $type->id,
        ]);

        // Вторая школа того же МСУ и типа — следующий порядковый номер.
        $this->actingAs($this->admin())
            ->post(route('admin.territory.school.store'), [
                'short_name' => 'Лицей', 'full_name' => 'Лицей №2',
                'education_level' => 3, 'territorial_sign' => 'city', 'msu_id' => $msu->id,
                'school_type_id' => $type->id,
            ])->assertSessionHas('success');
        $this->assertDatabaseHas('schools', ['oo_code' => '774002']);

        // Школа ДРУГОГО типа в том же МСУ — порядковый продолжается по МСУ (003), цифра типа другая.
        $type1 = \App\Models\SchoolType::where('digit', 1)->firstOrFail();
        $this->actingAs($this->admin())
            ->post(route('admin.territory.school.store'), [
                'short_name' => 'Вечерняя', 'full_name' => 'Вечерняя №3',
                'education_level' => 3, 'territorial_sign' => 'city', 'msu_id' => $msu->id,
                'school_type_id' => $type1->id,
            ])->assertSessionHas('success');
        $this->assertDatabaseHas('schools', ['oo_code' => '771003']);
    }

    public function test_school_store_requires_type_and_ignores_manual_code(): void
    {
        $ate = $this->ate('77');
        $msu = $this->msu($ate, '77');

        // Без типа — ошибка валидации.
        $this->actingAs($this->admin())
            ->post(route('admin.territory.school.store'), [
                'short_name' => 'Ш', 'full_name' => 'Ш', 'education_level' => 3,
                'territorial_sign' => 'city', 'msu_id' => $msu->id,
            ])
            ->assertSessionHasErrors('school_type_id');

        // Ручной oo_code игнорируется (код всё равно генерируется).
        $type = \App\Models\SchoolType::where('digit', 1)->firstOrFail();
        $this->actingAs($this->admin())
            ->post(route('admin.territory.school.store'), [
                'oo_code' => '999999', 'short_name' => 'Ш', 'full_name' => 'Ш', 'education_level' => 3,
                'territorial_sign' => 'city', 'msu_id' => $msu->id, 'school_type_id' => $type->id,
            ])->assertSessionHas('success');
        $this->assertDatabaseMissing('schools', ['oo_code' => '999999']);
        $this->assertDatabaseHas('schools', ['oo_code' => '771001']);
    }

    public function test_school_type_crud_and_delete_guard(): void
    {
        $admin = $this->admin();

        // Создание типа.
        $this->actingAs($admin)->post(route('admin.territory.school-type.store'), ['digit' => 7, 'name' => 'Вечерняя школа'])
            ->assertSessionHas('success');
        $this->assertDatabaseHas('school_types', ['digit' => 7, 'name' => 'Вечерняя школа']);

        // Дубль цифры запрещён.
        $this->actingAs($admin)->post(route('admin.territory.school-type.store'), ['digit' => 7, 'name' => 'Другая'])
            ->assertSessionHasErrors('digit');

        // Нельзя удалить тип, если есть школы.
        $ate = $this->ate('80');
        $msu = $this->msu($ate, '80');
        $type = \App\Models\SchoolType::where('digit', 4)->firstOrFail();
        School::create(['oo_code' => '804001', 'short_name' => 'Ш', 'full_name' => 'Ш', 'education_level' => 3,
            'territorial_sign' => 'city', 'msu_id' => $msu->id, 'msu_code' => '80', 'ate_id' => $ate->id, 'ate_code' => '80',
            'school_type_id' => $type->id]);
        $this->actingAs($admin)->delete(route('admin.territory.school-type.destroy', $type))
            ->assertSessionHasErrors('school_type');
    }

    public function test_updating_ate_code_cascades_to_schools(): void
    {
        $ate = $this->ate('50');
        $msu = $this->msu($ate, '50');
        School::create([
            'oo_code' => '5001', 'short_name' => 'Ш', 'full_name' => 'Ш',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '50', 'ate_id' => $ate->id, 'ate_code' => '50',
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.territory.ate.update', $ate), [
                'ate_code' => '55', 'name' => $ate->name, 'type' => 'isolated',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('schools', ['oo_code' => '5001', 'ate_code' => '55']);
    }

    public function test_cannot_delete_ate_with_msus(): void
    {
        $ate = $this->ate('60');
        $this->msu($ate, '60');

        $this->actingAs($this->admin())
            ->delete(route('admin.territory.ate.destroy', $ate))
            ->assertSessionHasErrors('ate');

        $this->assertDatabaseHas('ates', ['id' => $ate->id]);
    }

    public function test_cannot_delete_school_with_students(): void
    {
        $ate = $this->ate('90');
        $msu = $this->msu($ate, '90');
        $school = School::create([
            'oo_code' => '9001', 'short_name' => 'Ш', 'full_name' => 'Ш',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '90', 'ate_id' => $ate->id, 'ate_code' => '90',
        ]);
        Student::create([
            'fio' => 'Уч', 'birth_date' => '2011-01-01', 'school_id' => $school->id, 'real_grade' => 9,
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.territory.school.destroy', $school))
            ->assertSessionHasErrors('school');
    }

    public function test_schools_filtered_by_ate(): void
    {
        $ateA = $this->ate('70');
        $ateB = $this->ate('71');
        $msuA = $this->msu($ateA, '70');
        $msuB = $this->msu($ateB, '71');
        School::create([
            'oo_code' => '7001', 'short_name' => 'Школа А', 'full_name' => 'А',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msuA->id, 'msu_code' => '70', 'ate_id' => $ateA->id, 'ate_code' => '70',
        ]);
        School::create([
            'oo_code' => '7101', 'short_name' => 'Школа Б', 'full_name' => 'Б',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msuB->id, 'msu_code' => '71', 'ate_id' => $ateB->id, 'ate_code' => '71',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.territory.index', ['school_ate' => $ateA->id]))
            ->assertInertia(fn ($page) => $page
                ->where('schools.data', fn ($rows) => count($rows) === 1 && $rows[0]['short_name'] === 'Школа А'));

        // Фильтр по МСУ.
        $this->actingAs($this->admin())
            ->get(route('admin.territory.index', ['school_msu' => $msuB->id]))
            ->assertInertia(fn ($page) => $page
                ->where('schools.data', fn ($rows) => count($rows) === 1 && $rows[0]['short_name'] === 'Школа Б'));
    }

    public function test_non_admin_forbidden(): void
    {
        $coord = User::factory()->create(['role' => UserRole::SuperCoordinator, 'is_active' => true]);

        $this->actingAs($coord)->get(route('admin.territory.index'))->assertForbidden();
    }
}
