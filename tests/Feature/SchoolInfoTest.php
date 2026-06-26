<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolInfoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function operatorWithSchool(): array
    {
        $ate = Ate::create(['ate_code' => '10', 'name' => 'Тестовая АТЕ', 'type' => 'isolated']);
        $msu = Msu::create(['msu_code' => '10', 'name' => 'Тестовое МСУ', 'ate_id' => $ate->id]);
        $school = School::create([
            'oo_code' => '100500', 'short_name' => 'Гимназия №1', 'full_name' => 'Полное наименование',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);
        $operator = User::factory()->create([
            'role' => UserRole::SchoolOperator, 'school_id' => $school->id, 'is_active' => true,
        ]);

        return [$operator, $school];
    }

    public function test_operator_sees_own_school_info(): void
    {
        [$operator] = $this->operatorWithSchool();

        $this->actingAs($operator)->get(route('school.info'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('School/Info')
                ->where('school.oo_code', '100500')
                ->where('school.short_name', 'Гимназия №1')
                ->where('school.ate', 'Тестовая АТЕ')
                ->where('school.msu', 'Тестовое МСУ'));
    }

    public function test_non_operator_forbidden(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin)->get(route('school.info'))->assertForbidden();
    }
}
