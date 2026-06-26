<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\School;
use App\Models\SchoolType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MunicipalSchoolTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function ate(string $code, ?int $parent = null): Ate
    {
        return Ate::create(['ate_code' => $code, 'name' => "АТЕ {$code}", 'type' => 'isolated', 'parent_ate_id' => $parent]);
    }

    private function msu(Ate $ate, string $code): Msu
    {
        return Msu::create(['msu_code' => $code, 'name' => "МСУ {$code}", 'ate_id' => $ate->id]);
    }

    private function school(Ate $ate, Msu $msu, string $oo): School
    {
        return School::create(['oo_code' => $oo, 'short_name' => 'Ш'.(++$this->seq), 'full_name' => 'Ш'.$this->seq,
            'education_level' => 3, 'territorial_sign' => 'city', 'msu_id' => $msu->id, 'msu_code' => $msu->msu_code,
            'ate_id' => $ate->id, 'ate_code' => $ate->ate_code]);
    }

    public function test_coordinator_sees_only_own_ate_and_can_add(): void
    {
        $ateA = $this->ate('70');
        $msuA = $this->msu($ateA, '70');
        $this->school($ateA, $msuA, '700001');
        $ateB = $this->ate('71');
        $msuB = $this->msu($ateB, '71');
        $this->school($ateB, $msuB, '710001');

        $coord = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ateA->id, 'is_active' => true]);
        $type = SchoolType::where('digit', 4)->firstOrFail();

        // Видит только свою АТЕ, без фильтра по АТЕ (один АТЕ).
        $this->actingAs($coord)->get(route('municipal.schools.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Municipal/Schools/Index')
                ->has('schools.data', 1)->where('multiAte', false));

        // Добавление в своём АТЕ — код автогенерируется (704002, т.к. в МСУ уже есть 700001 → max seq 001 → 002).
        $this->actingAs($coord)->post(route('municipal.schools.store'), [
            'short_name' => 'Новая', 'full_name' => 'Новая школа', 'education_level' => 3,
            'territorial_sign' => 'city', 'msu_id' => $msuA->id, 'school_type_id' => $type->id,
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('schools', ['oo_code' => '704002', 'ate_id' => $ateA->id, 'msu_id' => $msuA->id]);

        // Нельзя добавить с МСУ чужого АТЕ.
        $this->actingAs($coord)->post(route('municipal.schools.store'), [
            'short_name' => 'X', 'full_name' => 'X', 'education_level' => 3,
            'territorial_sign' => 'city', 'msu_id' => $msuB->id, 'school_type_id' => $type->id,
        ])->assertSessionHasErrors('msu_id');
    }

    public function test_coordinator_cannot_edit_foreign_school(): void
    {
        $ateA = $this->ate('70');
        $msuA = $this->msu($ateA, '70');
        $ateB = $this->ate('71');
        $msuB = $this->msu($ateB, '71');
        $foreign = $this->school($ateB, $msuB, '710001');

        $coord = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ateA->id, 'is_active' => true]);

        $this->actingAs($coord)->put(route('municipal.schools.update', $foreign), [
            'short_name' => 'Хак', 'full_name' => 'Хак', 'education_level' => 3,
            'territorial_sign' => 'city', 'msu_id' => $msuA->id, 'school_type_id' => SchoolType::first()->id,
        ])->assertForbidden();
    }

    public function test_super_coordinator_sees_all_kazan_districts_with_ate_filter(): void
    {
        $kazan = $this->ate('61');
        $d1 = $this->ate('54', $kazan->id);
        $d2 = $this->ate('55', $kazan->id);
        $m1 = $this->msu($d1, '54');
        $m2 = $this->msu($d2, '55');
        $this->school($d1, $m1, '540001');
        $this->school($d2, $m2, '550001');

        $super = User::factory()->create(['role' => UserRole::SuperCoordinator, 'ate_id' => $d1->id, 'is_active' => true]);
        $super->coordinatorAtes()->sync([$d1->id, $d2->id]); // набор АТЕ Казани

        $this->actingAs($super)->get(route('municipal.schools.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('schools.data', 2)->where('multiAte', true)->has('ateList', 2));

        // Фильтр по району.
        $this->actingAs($super)->get(route('municipal.schools.index', ['school_ate' => $d1->id]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('schools.data', 1));
    }

    public function test_other_roles_forbidden(): void
    {
        $op = User::factory()->create(['role' => UserRole::SchoolOperator, 'is_active' => true]);
        $this->actingAs($op)->get(route('municipal.schools.index'))->assertForbidden();
    }
}
