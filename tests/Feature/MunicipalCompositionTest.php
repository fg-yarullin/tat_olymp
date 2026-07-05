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
use Tests\TestCase;

class MunicipalCompositionTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function school(Ate $ate): School
    {
        $msu = Msu::firstOrCreate(['msu_code' => $ate->ate_code], ['name' => 'МСУ', 'ate_id' => $ate->id]);

        return School::create([
            'oo_code' => 'OO'.(++$this->seq), 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => $ate->ate_code, 'ate_id' => $ate->id, 'ate_code' => $ate->ate_code,
        ]);
    }

    private function student(School $school, int $grade = 9): Student
    {
        return Student::create([
            'fio' => 'Ученик '.(++$this->seq), 'birth_date' => '2010-01-01',
            'school_id' => $school->id, 'real_grade' => $grade,
        ]);
    }

    public function test_compose_pulls_school_stage_winners_of_own_ate_only(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);

        $ateA = Ate::create(['ate_code' => '01', 'name' => 'АТЕ A', 'type' => 'isolated']);
        $ateB = Ate::create(['ate_code' => '02', 'name' => 'АТЕ B', 'type' => 'isolated']);
        $schoolA = $this->school($ateA);
        $schoolB = $this->school($ateB);

        $school = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id,
            'stage' => 'school', 'grades' => '7,8,9,10,11', 'date_held' => '2025-11-01', 'published_at' => now(),
        ]);
        $municipal = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id,
            'stage' => 'municipal', 'grades' => '7,8,9,10,11', 'date_held' => '2025-12-01', 'status' => 'planned',
        ]);

        // Призёр ШЭ из АТЕ A — должен попасть; участник — нет; призёр из АТЕ B — нет.
        $winnerA = $this->student($schoolA, 9);
        $plainA = $this->student($schoolA, 9);
        $winnerB = $this->student($schoolB, 9);
        HumanOlympiad::create(['student_id' => $winnerA->id, 'olympiad_id' => $school->id, 'participation_grade' => 9, 'result_status' => 'prize_winner']);
        HumanOlympiad::create(['student_id' => $plainA->id, 'olympiad_id' => $school->id, 'participation_grade' => 9, 'result_status' => 'participant']);
        HumanOlympiad::create(['student_id' => $winnerB->id, 'olympiad_id' => $school->id, 'participation_grade' => 9, 'result_status' => 'winner']);

        $coordinator = User::factory()->create([
            'role' => UserRole::MunicipalCoordinator, 'ate_id' => $ateA->id, 'is_active' => true,
        ]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.compose', $municipal))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('human_olympiad', [
            'student_id' => $winnerA->id, 'olympiad_id' => $municipal->id, 'participation_grade' => 9,
        ]);
        $this->assertDatabaseMissing('human_olympiad', ['student_id' => $plainA->id, 'olympiad_id' => $municipal->id]);
        $this->assertDatabaseMissing('human_olympiad', ['student_id' => $winnerB->id, 'olympiad_id' => $municipal->id]);

        // Повторный вызов не создаёт дублей.
        $this->actingAs($coordinator)->post(route('municipal.results.compose', $municipal));
        $this->assertSame(1, HumanOlympiad::where('olympiad_id', $municipal->id)->count());
    }

    public function test_compose_top_n_invites_highest_scorers_per_group(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $school = $this->school($ate);

        $she = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id,
            'stage' => 'school', 'grades' => '7,8,9,10,11', 'date_held' => '2025-11-01', 'published_at' => now(),
        ]);
        $mun = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id,
            'stage' => 'municipal', 'grades' => '7,8,9,10,11', 'date_held' => '2025-12-01',
        ]);

        // Группа 7-8: баллы 90,80,70,60 → при N=2 приглашаются двое лучших (90 и 80).
        $top1 = $this->student($school, 7);
        $top2 = $this->student($school, 8);
        $low1 = $this->student($school, 7);
        $low2 = $this->student($school, 8);
        foreach ([[$top1, 7, 90], [$top2, 8, 80], [$low1, 7, 70], [$low2, 8, 60]] as [$st, $g, $sc]) {
            HumanOlympiad::create(['student_id' => $st->id, 'olympiad_id' => $she->id, 'participation_grade' => $g, 'score' => $sc, 'result_status' => 'participant']);
        }

        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.compose-top-n', $mun), ['groups' => [['classes' => [7, 8], 'n' => 2]]])
            ->assertSessionHasNoErrors();

        $this->assertTrue(HumanOlympiad::where('olympiad_id', $mun->id)->where('student_id', $top1->id)->exists());
        $this->assertTrue(HumanOlympiad::where('olympiad_id', $mun->id)->where('student_id', $top2->id)->exists());
        $this->assertFalse(HumanOlympiad::where('olympiad_id', $mun->id)->where('student_id', $low1->id)->exists());
        $this->assertFalse(HumanOlympiad::where('olympiad_id', $mun->id)->where('student_id', $low2->id)->exists());
        $this->assertSame(2, HumanOlympiad::where('olympiad_id', $mun->id)->count());
    }

    public function test_compose_top_n_per_school_invites_best_from_each_school(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $schoolA = $this->school($ate);
        $schoolB = $this->school($ate);

        $she = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id,
            'stage' => 'school', 'grades' => '9', 'date_held' => '2025-11-01', 'published_at' => now(),
        ]);
        $mun = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id,
            'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01',
        ]);

        // Школа A: баллы 90,80,70 → топ-1 = 90. Школа B: баллы 60,50 → топ-1 = 60.
        $a1 = $this->student($schoolA, 9);
        $a2 = $this->student($schoolA, 9);
        $a3 = $this->student($schoolA, 9);
        $b1 = $this->student($schoolB, 9);
        $b2 = $this->student($schoolB, 9);
        foreach ([[$a1, 90], [$a2, 80], [$a3, 70], [$b1, 60], [$b2, 50]] as [$st, $sc]) {
            HumanOlympiad::create(['student_id' => $st->id, 'olympiad_id' => $she->id, 'participation_grade' => 9, 'score' => $sc, 'result_status' => 'participant']);
        }

        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.compose-top-n-school', $mun), ['groups' => [['classes' => [9], 'n' => 1]]])
            ->assertSessionHasNoErrors();

        // По одному лучшему из каждой школы: a1 (90) и b1 (60).
        $this->assertTrue(HumanOlympiad::where('olympiad_id', $mun->id)->where('student_id', $a1->id)->exists());
        $this->assertTrue(HumanOlympiad::where('olympiad_id', $mun->id)->where('student_id', $b1->id)->exists());
        $this->assertFalse(HumanOlympiad::where('olympiad_id', $mun->id)->where('student_id', $a2->id)->exists());
        $this->assertFalse(HumanOlympiad::where('olympiad_id', $mun->id)->where('student_id', $b2->id)->exists());
        $this->assertSame(2, HumanOlympiad::where('olympiad_id', $mun->id)->count());
    }

    public function test_compose_sets_inclusion_basis_by_source_stage(): void
    {
        // Годы создаём в хронологическом порядке (как в реальности): прошлый, затем текущий.
        $prevYear = AcademicYear::create(['name' => '2024/2025', 'status' => 'archive']);
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $school = $this->school($ate);

        $sheOl = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id, 'stage' => 'school', 'grades' => '9', 'date_held' => '2025-11-01', 'published_at' => now()]);
        $prevMun = Olympiad::create(['academic_year_id' => $prevYear->id, 'subject' => 'Физика', 'subject_id' => $subject->id, 'stage' => 'municipal', 'grades' => '9', 'date_held' => '2024-12-01', 'published_at' => now()]);
        $municipal = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id, 'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01', 'status' => 'planned']);

        $fromShe = $this->student($school, 9);
        $fromPrev = $this->student($school, 9);
        HumanOlympiad::create(['student_id' => $fromShe->id, 'olympiad_id' => $sheOl->id, 'participation_grade' => 9, 'result_status' => 'winner']);
        HumanOlympiad::create(['student_id' => $fromPrev->id, 'olympiad_id' => $prevMun->id, 'participation_grade' => 9, 'result_status' => 'prize_winner']);

        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);
        $this->actingAs($coordinator)->post(route('municipal.results.compose', $municipal))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('human_olympiad', ['student_id' => $fromShe->id, 'olympiad_id' => $municipal->id, 'inclusion_basis' => 'school_stage']);
        $this->assertDatabaseHas('human_olympiad', ['student_id' => $fromPrev->id, 'olympiad_id' => $municipal->id, 'inclusion_basis' => 'prev_municipal']);
    }

    public function test_compose_applies_threshold_and_prioritizes_prev_municipal_flag(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $school = $this->school($ate);

        $sheOl = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id, 'stage' => 'school', 'grades' => '9', 'date_held' => '2025-11-01', 'published_at' => now()]);
        $municipal = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id, 'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01', 'status' => 'planned']);

        $flagged = $this->student($school, 9);   // флаг призёра МЭ прошлого года, низкий балл ШЭ
        $highWinner = $this->student($school, 9); // победитель ШЭ, балл ≥ порога
        $lowWinner = $this->student($school, 9);  // призёр ШЭ, балл < порога — не приглашаем
        HumanOlympiad::create(['student_id' => $flagged->id, 'olympiad_id' => $sheOl->id, 'participation_grade' => 9, 'result_status' => 'participant', 'prev_municipal_winner' => true, 'score' => 10]);
        HumanOlympiad::create(['student_id' => $highWinner->id, 'olympiad_id' => $sheOl->id, 'participation_grade' => 9, 'result_status' => 'winner', 'score' => 60]);
        HumanOlympiad::create(['student_id' => $lowWinner->id, 'olympiad_id' => $sheOl->id, 'participation_grade' => 9, 'result_status' => 'prize_winner', 'score' => 40]);

        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);
        $this->actingAs($coordinator)
            ->post(route('municipal.results.compose', $municipal), ['thresholds' => [9 => 50]])
            ->assertSessionHasNoErrors();

        // Призёр МЭ прошлого года по флагу — включён, несмотря на низкий балл ШЭ, с приоритетным основанием.
        $this->assertDatabaseHas('human_olympiad', ['student_id' => $flagged->id, 'olympiad_id' => $municipal->id, 'inclusion_basis' => 'prev_municipal']);
        // Победитель ШЭ выше порога — включён.
        $this->assertDatabaseHas('human_olympiad', ['student_id' => $highWinner->id, 'olympiad_id' => $municipal->id, 'inclusion_basis' => 'school_stage']);
        // Призёр ШЭ ниже порога — не включён.
        $this->assertDatabaseMissing('human_olympiad', ['student_id' => $lowWinner->id, 'olympiad_id' => $municipal->id]);

        // Порог сохранён для (олимпиада, АТЕ).
        $this->assertDatabaseHas('municipal_invitation_thresholds', ['olympiad_id' => $municipal->id, 'ate_id' => $ate->id]);
    }

    public function test_external_participant_attached_to_petitioning_school(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Химия', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $school = $this->school($ate);
        $municipal = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Химия', 'subject_id' => $subject->id, 'stage' => 'municipal', 'grades' => '9,10,11', 'date_held' => '2025-12-01', 'status' => 'planned']);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)->post(route('municipal.results.external', $municipal), [
            'school_id' => $school->id, 'fio' => 'Гостев Иван', 'birth_date' => '2009-05-01',
            'gender' => 'male', 'real_grade' => 9, 'origin_region' => 'Республика Башкортостан', 'participation_grade' => 9,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('students', [
            'fio' => 'Гостев Иван', 'school_id' => $school->id, 'from_other_region' => true, 'origin_region' => 'Республика Башкортостан',
        ]);
        $student = Student::where('fio', 'Гостев Иван')->first();
        $this->assertDatabaseHas('human_olympiad', [
            'student_id' => $student->id, 'olympiad_id' => $municipal->id, 'inclusion_basis' => 'petition',
        ]);
    }

    public function test_invited_list_xlsx_download(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Биология', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $school = $this->school($ate);
        $municipal = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Биология', 'subject_id' => $subject->id, 'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01', 'status' => 'planned']);
        $student = $this->student($school, 9);
        HumanOlympiad::create(['student_id' => $student->id, 'olympiad_id' => $municipal->id, 'participation_grade' => 9, 'result_status' => 'participant', 'inclusion_basis' => 'school_stage']);

        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $response = $this->actingAs($coordinator)->get(route('municipal.results.invited', $municipal));
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_manual_add_rejects_student_from_other_ate(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Химия', 'is_active' => true]);
        $ateA = Ate::create(['ate_code' => '01', 'name' => 'АТЕ A', 'type' => 'isolated']);
        $ateB = Ate::create(['ate_code' => '02', 'name' => 'АТЕ B', 'type' => 'isolated']);
        $schoolB = $this->school($ateB);
        $foreign = $this->student($schoolB, 9);

        $municipal = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Химия', 'subject_id' => $subject->id,
            'stage' => 'municipal', 'grades' => '7,8,9,10,11', 'date_held' => '2025-12-01', 'status' => 'planned',
        ]);
        $coordinator = User::factory()->create([
            'role' => UserRole::MunicipalCoordinator, 'ate_id' => $ateA->id, 'is_active' => true,
        ]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.store', $municipal), ['student_id' => $foreign->id, 'participation_grade' => 9])
            ->assertSessionHasErrors('student_id');
    }

    public function test_bulk_destroy_selected_ids(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $school = $this->school($ate);
        $municipal = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id, 'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01', 'status' => 'planned']);
        $s1 = $this->student($school, 9);
        $s2 = $this->student($school, 9);
        $s3 = $this->student($school, 9);
        $h1 = HumanOlympiad::create(['student_id' => $s1->id, 'olympiad_id' => $municipal->id, 'participation_grade' => 9]);
        $h2 = HumanOlympiad::create(['student_id' => $s2->id, 'olympiad_id' => $municipal->id, 'participation_grade' => 9]);
        HumanOlympiad::create(['student_id' => $s3->id, 'olympiad_id' => $municipal->id, 'participation_grade' => 9]);

        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);
        $this->actingAs($coordinator)
            ->post(route('municipal.results.bulk-destroy', $municipal), ['mode' => 'selected', 'ids' => [$h1->id, $h2->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, HumanOlympiad::where('olympiad_id', $municipal->id)->count());
        $this->assertDatabaseMissing('human_olympiad', ['id' => $h1->id]);
    }

    public function test_bulk_destroy_filtered_by_school(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $schoolA = $this->school($ate);
        $schoolB = $this->school($ate);
        $municipal = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id, 'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01', 'status' => 'planned']);
        $a = $this->student($schoolA, 9);
        $b = $this->student($schoolB, 9);
        HumanOlympiad::create(['student_id' => $a->id, 'olympiad_id' => $municipal->id, 'participation_grade' => 9]);
        HumanOlympiad::create(['student_id' => $b->id, 'olympiad_id' => $municipal->id, 'participation_grade' => 9]);

        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);
        $this->actingAs($coordinator)
            ->post(route('municipal.results.bulk-destroy', $municipal), ['mode' => 'filtered', 'school' => $schoolA->id])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('human_olympiad', ['student_id' => $a->id]);
        $this->assertDatabaseHas('human_olympiad', ['student_id' => $b->id]);
    }

    public function test_bulk_destroy_all_scoped_to_own_ate(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $ateA = Ate::create(['ate_code' => '01', 'name' => 'АТЕ A', 'type' => 'isolated']);
        $ateB = Ate::create(['ate_code' => '02', 'name' => 'АТЕ B', 'type' => 'isolated']);
        $schoolA = $this->school($ateA);
        $schoolB = $this->school($ateB);
        $municipal = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id, 'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01', 'status' => 'planned']);
        $a = $this->student($schoolA, 9);
        $b = $this->student($schoolB, 9);
        HumanOlympiad::create(['student_id' => $a->id, 'olympiad_id' => $municipal->id, 'participation_grade' => 9]);
        HumanOlympiad::create(['student_id' => $b->id, 'olympiad_id' => $municipal->id, 'participation_grade' => 9]);

        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ateA->id, 'is_active' => true]);
        $this->actingAs($coordinator)
            ->post(route('municipal.results.bulk-destroy', $municipal), ['mode' => 'all'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('human_olympiad', ['student_id' => $a->id]);
        $this->assertDatabaseHas('human_olympiad', ['student_id' => $b->id]);
    }

    public function test_bulk_destroy_redirects_to_previous_page_when_current_page_empties(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $school = $this->school($ate);
        $municipal = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id, 'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01', 'status' => 'planned']);
        $ids = [];
        for ($i = 0; $i < 26; $i++) {
            $student = $this->student($school, 9);
            $ids[] = HumanOlympiad::create(['student_id' => $student->id, 'olympiad_id' => $municipal->id, 'participation_grade' => 9])->id;
        }
        $lastId = end($ids);

        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);
        $this->actingAs($coordinator)
            ->post(route('municipal.results.bulk-destroy', $municipal), ['mode' => 'selected', 'ids' => [$lastId], 'page' => 2])
            ->assertRedirect(route('municipal.results.show', $municipal));

        $this->assertSame(25, HumanOlympiad::where('olympiad_id', $municipal->id)->count());
    }

    public function test_bulk_destroy_blocked_when_composition_closed(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $school = $this->school($ate);
        $municipal = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id, 'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01', 'published_at' => now()]);
        $student = $this->student($school, 9);
        $h = HumanOlympiad::create(['student_id' => $student->id, 'olympiad_id' => $municipal->id, 'participation_grade' => 9]);

        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);
        $this->actingAs($coordinator)
            ->post(route('municipal.results.bulk-destroy', $municipal), ['mode' => 'selected', 'ids' => [$h->id]])
            ->assertSessionHasErrors('participation');

        $this->assertDatabaseHas('human_olympiad', ['id' => $h->id]);
    }
}
