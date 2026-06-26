<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\HumanOlympiad;
use App\Models\Olympiad;
use App\Models\ProtocolTemplate;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RocCabinetTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function olympiad(int $yearId, Subject $subject, string $stage): Olympiad
    {
        return Olympiad::create([
            'academic_year_id' => $yearId, 'subject' => $subject->name, 'subject_id' => $subject->id,
            'stage' => $stage, 'grades' => '7,8,9', 'date_held' => '2025-11-01', 'published_at' => now(),
        ]);
    }

    private function participant(Olympiad $o, Ate $ate, int $grade = 7, float $score = 50): HumanOlympiad
    {
        $msu = \App\Models\Msu::firstOrCreate(['msu_code' => $ate->ate_code], ['name' => 'МСУ', 'ate_id' => $ate->id]);
        $school = School::create(['oo_code' => 'OO'.(++$this->seq), 'short_name' => 'Школа '.$this->seq, 'full_name' => 'Школа '.$this->seq,
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => $ate->ate_code, 'ate_id' => $ate->id, 'ate_code' => $ate->ate_code]);
        $student = Student::create(['fio' => 'Ученик '.$this->seq, 'birth_date' => '2012-01-01', 'school_id' => $school->id, 'real_grade' => $grade]);
        $field = $o->stage === 'municipal' ? 'primary_score' : 'score';

        return HumanOlympiad::create(['student_id' => $student->id, 'olympiad_id' => $o->id, 'participation_grade' => $grade, 'result_status' => 'participant', $field => $score]);
    }

    public function test_representative_sees_both_stages_all_subjects(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $phys = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $chem = Subject::create(['name' => 'Химия', 'is_active' => true]);
        $this->olympiad($year->id, $phys, 'school');
        $this->olympiad($year->id, $chem, 'municipal');

        $rep = User::factory()->create(['role' => UserRole::RocRepresentative, 'is_active' => true]);

        $this->actingAs($rep)->get(route('roc.olympiads.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Roc/Olympiads/Index')->has('olympiads', 2));
    }

    public function test_coordinator_limited_to_assigned_subjects(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $phys = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $chem = Subject::create(['name' => 'Химия', 'is_active' => true]);
        $physOl = $this->olympiad($year->id, $phys, 'school');
        $chemOl = $this->olympiad($year->id, $chem, 'municipal');

        $coord = User::factory()->create(['role' => UserRole::RocSubjectCoordinator, 'is_active' => true]);
        $coord->rocSubjects()->sync([$phys->id]);

        $this->actingAs($coord)->get(route('roc.olympiads.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('olympiads', 1)->has('subjects', 1));

        $this->actingAs($coord)->get(route('roc.olympiads.show', $physOl))->assertOk();
        $this->actingAs($coord)->get(route('roc.olympiads.show', $chemOl))->assertForbidden();
    }

    public function test_show_filters_by_ate_and_grade(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $phys = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $ateA = Ate::create(['ate_code' => '01', 'name' => 'АТЕ А', 'type' => 'isolated']);
        $ateB = Ate::create(['ate_code' => '02', 'name' => 'АТЕ Б', 'type' => 'isolated']);
        $ol = $this->olympiad($year->id, $phys, 'school');
        $this->participant($ol, $ateA, 7);
        $this->participant($ol, $ateA, 8);
        $this->participant($ol, $ateB, 7);

        $rep = User::factory()->create(['role' => UserRole::RocRepresentative, 'is_active' => true]);

        // Фильтр по АТЕ А — два участника.
        $this->actingAs($rep)->get(route('roc.olympiads.show', ['olympiad' => $ol->id, 'ate' => $ateA->id]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('rows.data', 2));
        // Фильтр по АТЕ А + класс 7 — один.
        $this->actingAs($rep)->get(route('roc.olympiads.show', ['olympiad' => $ol->id, 'ate' => $ateA->id, 'grade' => 7]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('rows.data', 1));
    }

    public function test_export_protocol_downloads_xlsx(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $phys = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $ol = $this->olympiad($year->id, $phys, 'school');
        $this->participant($ol, $ate, 7);

        $template = ProtocolTemplate::create(['name' => 'ШЭ общий', 'stage' => 'school', 'subject_id' => null]);
        $template->columns()->create(['position' => 1, 'header' => '№', 'source_key' => 'row_number']);
        $template->columns()->create(['position' => 2, 'header' => 'Балл', 'source_key' => 'ho.score']);

        $rep = User::factory()->create(['role' => UserRole::RocRepresentative, 'is_active' => true]);

        $res = $this->actingAs($rep)->get(route('roc.olympiads.protocol', $ol));
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));
    }

    public function test_representative_creates_coordinator_with_subjects(): void
    {
        $phys = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $rep = User::factory()->create(['role' => UserRole::RocRepresentative, 'is_active' => true]);

        $this->actingAs($rep)->post(route('roc.coordinators.store'), [
            'name' => 'Координатор', 'email' => 'k@roc.tt', 'password' => 'password123', 'is_active' => true,
            'subject_ids' => [$phys->id],
        ])->assertSessionHasNoErrors();

        $u = User::where('email', 'k@roc.tt')->first();
        $this->assertSame(UserRole::RocSubjectCoordinator, $u->role);
        $this->assertEquals([$phys->id], $u->rocSubjects()->pluck('subjects.id')->all());
    }

    public function test_coordinator_cannot_manage_coordinators(): void
    {
        $coord = User::factory()->create(['role' => UserRole::RocSubjectCoordinator, 'is_active' => true]);
        $this->actingAs($coord)->get(route('roc.coordinators.index'))->assertForbidden();
    }

    public function test_other_roles_blocked_from_roc(): void
    {
        $op = User::factory()->create(['role' => UserRole::SchoolOperator, 'is_active' => true]);
        $this->actingAs($op)->get(route('roc.olympiads.index'))->assertForbidden();
    }
}
