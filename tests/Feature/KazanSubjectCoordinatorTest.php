<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\HumanOlympiad;
use App\Models\Msu;
use App\Models\Olympiad;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class KazanSubjectCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function kazanAte(): Ate
    {
        return Ate::firstOrCreate(['ate_code' => '92'], ['name' => 'Казань', 'type' => 'isolated']);
    }

    private function municipal(AcademicYear $year, Subject $subject): Olympiad
    {
        return Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => $subject->name, 'subject_id' => $subject->id,
            'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01', 'status' => 'grading',
        ]);
    }

    public function test_super_creates_subject_coordinator_inheriting_kazan_ate(): void
    {
        $ate = $this->kazanAte();
        $phys = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $super = User::factory()->create(['role' => UserRole::SuperCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($super)->post(route('city.subject-coordinators.store'), [
            'name' => 'Отв', 'email' => 'otv@kzn.tt', 'password' => 'password123', 'is_active' => true,
            'subject_ids' => [$phys->id],
        ])->assertSessionHasNoErrors();

        $coord = User::where('email', 'otv@kzn.tt')->first();
        $this->assertSame(UserRole::KazanSubjectCoordinator, $coord->role);
        $this->assertSame($ate->id, $coord->ate_id); // АТЕ Казани унаследовано от супер-координатора
        $this->assertEquals([$phys->id], $coord->kazanSubjects()->pluck('subjects.id')->all());
    }

    public function test_subject_coordinator_sees_only_their_subjects_and_blocked_on_others(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $ate = $this->kazanAte();
        $phys = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $chem = Subject::create(['name' => 'Химия', 'is_active' => true]);
        $physOl = $this->municipal($year, $phys);
        $chemOl = $this->municipal($year, $chem);

        $coord = User::factory()->create(['role' => UserRole::KazanSubjectCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);
        $coord->kazanSubjects()->sync([$phys->id]);

        $this->actingAs($coord)->get(route('municipal.results.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('olympiads', 1));

        $this->actingAs($coord)->get(route('municipal.results.show', $physOl))->assertOk();
        $this->actingAs($coord)->get(route('municipal.results.show', $chemOl))->assertForbidden();
    }

    public function test_super_has_full_municipal_access_all_subjects(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $ate = $this->kazanAte();
        $phys = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $chem = Subject::create(['name' => 'Химия', 'is_active' => true]);
        $this->municipal($year, $phys);
        $chemOl = $this->municipal($year, $chem);
        $super = User::factory()->create(['role' => UserRole::SuperCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($super)->get(route('municipal.results.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('olympiads', 2));
        $this->actingAs($super)->get(route('municipal.results.show', $chemOl))->assertOk();
    }

    public function test_subject_coordinator_chair_limited_to_subjects(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $ate = $this->kazanAte();
        $phys = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $chem = Subject::create(['name' => 'Химия', 'is_active' => true]);
        $physOl = $this->municipal($year, $phys);
        $chemOl = $this->municipal($year, $chem);
        $coord = User::factory()->create(['role' => UserRole::KazanSubjectCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);
        $coord->kazanSubjects()->sync([$phys->id]);

        $this->actingAs($coord)->post(route('municipal.chairs.store'), [
            'name' => 'Пред', 'email' => 'pred@kzn.tt', 'password' => 'password123', 'is_active' => true,
            'olympiad_ids' => [$physOl->id],
        ])->assertSessionHasNoErrors();

        // Олимпиада чужого предмета — валидация отклоняет.
        $this->actingAs($coord)->post(route('municipal.chairs.store'), [
            'name' => 'Пред2', 'email' => 'pred2@kzn.tt', 'password' => 'password123', 'is_active' => true,
            'olympiad_ids' => [$chemOl->id],
        ])->assertSessionHasErrors('olympiad_ids.0');
    }

    private int $seq = 0;

    private function district(Ate $umbrella, string $code): Ate
    {
        return Ate::create(['ate_code' => $code, 'name' => "Район {$code}", 'type' => 'isolated', 'parent_ate_id' => $umbrella->id]);
    }

    private function participant(Olympiad $o, Ate $ate, int $grade = 9): HumanOlympiad
    {
        $msu = Msu::firstOrCreate(['msu_code' => $ate->ate_code], ['name' => 'МСУ', 'ate_id' => $ate->id]);
        $school = School::create(['oo_code' => 'OO'.(++$this->seq), 'short_name' => 'Ш'.$this->seq, 'full_name' => 'Ш'.$this->seq,
            'education_level' => 3, 'territorial_sign' => 'city', 'msu_id' => $msu->id, 'msu_code' => $ate->ate_code,
            'ate_id' => $ate->id, 'ate_code' => $ate->ate_code]);
        $student = Student::create(['fio' => 'Уч '.$this->seq, 'birth_date' => '2010-01-01', 'school_id' => $school->id, 'real_grade' => $grade]);

        return HumanOlympiad::create(['student_id' => $student->id, 'olympiad_id' => $o->id, 'participation_grade' => $grade, 'result_status' => 'participant']);
    }

    public function test_umbrella_scope_covers_all_districts(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $kazan = $this->kazanAte(); // зонтик
        $d1 = $this->district($kazan, '54');
        $d2 = $this->district($kazan, '55');
        $phys = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $ol = $this->municipal($year, $phys);

        // Участники в школах двух разных районов Казани.
        $this->participant($ol, $d1);
        $this->participant($ol, $d2);

        $super = User::factory()->create(['role' => UserRole::SuperCoordinator, 'ate_id' => $d1->id, 'is_active' => true]);
        $super->coordinatorAtes()->sync([$d1->id, $d2->id]); // набор АТЕ Казани

        // Состав МЭ видит участников ОБОИХ районов через набор АТЕ.
        $this->actingAs($super)->get(route('municipal.results.show', $ol))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('participants.data', 2));
    }

    public function test_admin_creates_super_coordinator_with_multiple_ates(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $a1 = Ate::create(['ate_code' => '54', 'name' => 'Авиастроительный-Ново-Савиновский', 'type' => 'isolated']);
        $a2 = Ate::create(['ate_code' => '55', 'name' => 'Вахитовский-Приволжский', 'type' => 'isolated']);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Супер', 'email' => 'super@kzn.tt', 'password' => 'password123', 'is_active' => true,
            'role' => 'super_coordinator', 'ate_ids' => [$a1->id, $a2->id],
        ])->assertSessionHasNoErrors();

        $super = User::where('email', 'super@kzn.tt')->first();
        $this->assertSame(UserRole::SuperCoordinator, $super->role);
        $this->assertSame($a1->id, $super->ate_id); // первый выбранный — «домашний»
        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], $super->coordinatorAtes()->pluck('ates.id')->all());
        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], $super->municipalAteScope());

        // Без набора АТЕ — ошибка валидации.
        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'X', 'email' => 'x@kzn.tt', 'password' => 'password123', 'is_active' => true,
            'role' => 'super_coordinator',
        ])->assertSessionHasErrors('ate_ids');
    }

    public function test_only_super_manages_subject_coordinators(): void
    {
        $ate = $this->kazanAte();
        $coord = User::factory()->create(['role' => UserRole::KazanSubjectCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);
        $this->actingAs($coord)->get(route('city.subject-coordinators.index'))->assertForbidden();

        $muni = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);
        $this->actingAs($muni)->get(route('city.subject-coordinators.index'))->assertForbidden();
    }
}
