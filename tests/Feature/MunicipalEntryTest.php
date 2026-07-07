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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MunicipalEntryTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function ateSchool(string $code = '01'): array
    {
        $ate = Ate::firstOrCreate(['ate_code' => $code], ['name' => "АТЕ {$code}", 'type' => 'isolated']);
        $msu = Msu::firstOrCreate(['msu_code' => $code], ['name' => 'МСУ', 'ate_id' => $ate->id]);
        $school = School::create([
            'oo_code' => 'OO'.(++$this->seq), 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => $code, 'ate_id' => $ate->id, 'ate_code' => $code,
        ]);

        return [$ate, $school];
    }

    private function municipal(?string $deadline, AcademicYear $year): Olympiad
    {
        $o = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal',
            'grades' => '9', 'date_held' => '2025-12-01', 'status' => 'grading', 'results_deadline' => $deadline,
        ]);
        $o->maxScores()->create(['grade' => 9, 'max_score' => 50]);

        return $o;
    }

    private function participant(School $school, Olympiad $o): HumanOlympiad
    {
        $s = Student::create(['fio' => 'Уч '.(++$this->seq), 'birth_date' => '2010-01-01', 'school_id' => $school->id, 'real_grade' => 9]);

        return HumanOlympiad::create(['student_id' => $s->id, 'olympiad_id' => $o->id, 'participation_grade' => 9, 'result_status' => 'participant']);
    }

    public function test_composition_and_entry_are_separate_pages(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal(now()->addDay(), $year);
        $participation = $this->participant($school, $olympiad);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)->get(route('municipal.results.show', $olympiad))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->component('Municipal/Results/Show')
                ->where('participants.total', 1)
                ->has('students')); // данные формирования состава

        $this->actingAs($coordinator)->get(route('municipal.results.entry', $olympiad))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->component('Municipal/Results/Entry')
                ->where('participants.total', 1)
                ->where('olympiad.entry_open', true));
    }

    public function test_entry_page_exposes_profile_and_practice_for_technology(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Труд (технология)', 'stage' => 'municipal',
            'grades' => '9', 'date_held' => '2025-12-01', 'status' => 'grading', 'results_deadline' => now()->addDay(),
        ]);
        $participation = $this->participant($school, $olympiad);
        $participation->update(['profile' => 'Направление А', 'practice_types' => '1.1 Практика']);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)->get(route('municipal.results.entry', $olympiad))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->where('olympiad.is_technology', true)
                ->where('participants.data.0.profile', 'Направление А')
                ->where('participants.data.0.practice_types', '1.1 Практика'));
    }

    public function test_composition_closes_when_primary_entry_closed(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal(now()->subHour(), $year); // первичный ввод закрыт по сроку
        $student = Student::create(['fio' => 'Уч', 'birth_date' => '2010-01-01', 'school_id' => $school->id, 'real_grade' => 9]);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        // Состав закрыт на странице.
        $this->actingAs($coordinator)->get(route('municipal.results.show', $olympiad))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->where('olympiad.compose_open', false));

        // Действия по составу отклоняются.
        $this->actingAs($coordinator)->post(route('municipal.results.compose', $olympiad), ['thresholds' => []])
            ->assertSessionHasErrors('compose');
        $this->actingAs($coordinator)->post(route('municipal.results.store', $olympiad), ['student_id' => $student->id, 'participation_grade' => 9])
            ->assertSessionHasErrors('student_id');
    }

    public function test_entry_page_hides_no_show_after_primary_closes(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        // Первичный ввод ещё открыт — видны оба (с баллом и без).
        $openOl = $this->municipal(now()->addDay(), $year);
        $scored = $this->participant($school, $openOl);
        $scored->update(['primary_score' => 30]);
        $noShow = $this->participant($school, $openOl);

        $this->actingAs($coordinator)->get(route('municipal.results.entry', $openOl))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->where('participants.total', 2));

        // Первичный ввод закрыт — неявившийся (без балла) скрыт.
        $closedOl = $this->municipal(now()->subHour(), $year);
        $scored2 = $this->participant($school, $closedOl);
        $scored2->update(['primary_score' => 25]);
        $this->participant($school, $closedOl); // без балла

        $this->actingAs($coordinator)->get(route('municipal.results.entry', $closedOl))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->where('participants.total', 1)
                ->where('participants.data.0.id', $scored2->id));
    }

    public function test_coordinator_enters_primary_score_and_final_recomputed(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal(now()->addDay(), $year);
        $participation = $this->participant($school, $olympiad);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.primary', $participation), ['primary_score' => '30,5'])
            ->assertSessionHasNoErrors();

        $fresh = $participation->fresh();
        $this->assertEqualsWithDelta(30.5, (float) $fresh->primary_score, 0.001);
        $this->assertEqualsWithDelta(30.5, (float) $fresh->final_score, 0.001);

        // Балл выше максимума (50) отклоняется.
        $this->actingAs($coordinator)
            ->post(route('municipal.results.primary', $participation), ['primary_score' => '60'])
            ->assertSessionHasErrors('primary_score');
    }

    public function test_primary_entry_auto_closes_and_extension_reopens(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal(now()->subHour(), $year); // срок прошёл
        $participation = $this->participant($school, $olympiad);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.primary', $participation), ['primary_score' => '30'])
            ->assertSessionHasErrors('primary_score');

        // Продление фазы primary для этого АТЕ открывает ввод.
        $olympiad->entryExtensions()->create(['phase' => 'primary', 'scope' => 'ate', 'ate_id' => $ate->id, 'extended_until' => now()->addHours(2)]);
        $this->actingAs($coordinator)
            ->post(route('municipal.results.primary', $participation), ['primary_score' => '30'])
            ->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(30, (float) $participation->fresh()->primary_score, 0.001);
    }

    public function test_appeal_entry_opens_after_primary_deadline_without_status_change(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        // Первичный срок прошёл, срок апелляций — в будущем, статус всё ещё grading.
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal', 'grades' => '9',
            'date_held' => '2025-12-01', 'status' => 'grading',
            'results_deadline' => now()->subHour(), 'final_results_deadline' => now()->addDay(),
        ]);
        $olympiad->maxScores()->create(['grade' => 9, 'max_score' => 50]);
        $participation = $this->participant($school, $olympiad);
        $participation->update(['primary_score' => 40]);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        // Первичный ввод закрыт.
        $this->actingAs($coordinator)
            ->post(route('municipal.results.primary', $participation), ['primary_score' => '42'])
            ->assertSessionHasErrors('primary_score');

        // Апелляционный ввод открыт: добавка пересчитывает итог.
        $this->actingAs($coordinator)
            ->post(route('municipal.results.appeal', $participation), ['appeal_addition' => '5'])
            ->assertSessionHasNoErrors();
        $fresh = $participation->fresh();
        $this->assertEqualsWithDelta(5, (float) $fresh->appeal_addition, 0.001);
        $this->assertEqualsWithDelta(45, (float) $fresh->final_score, 0.001);

        // Итог выше максимума (50) отклоняется.
        $this->actingAs($coordinator)
            ->post(route('municipal.results.appeal', $participation), ['appeal_addition' => '15'])
            ->assertSessionHasErrors('appeal_addition');
    }

    public function test_appeal_closed_while_primary_still_open(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal', 'grades' => '9',
            'date_held' => '2025-12-01', 'status' => 'grading',
            'results_deadline' => now()->addDay(), 'final_results_deadline' => now()->addDays(2),
        ]);
        $participation = $this->participant($school, $olympiad);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.appeal', $participation), ['appeal_addition' => '5'])
            ->assertSessionHasErrors('appeal_addition');
    }

    public function test_primary_entry_by_questions_sums_and_caps(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Астрономия', 'stage' => 'municipal', 'grades' => '9',
            'date_held' => '2025-12-01', 'status' => 'grading', 'results_deadline' => now()->addDay(), 'question_count' => 3,
        ]);
        $olympiad->maxScores()->create(['grade' => 9, 'max_score' => 30]);
        $participation = $this->participant($school, $olympiad);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        // Сумма 10+8+5 = 23 ≤ 30 — принимается, первичный балл = сумма.
        $this->actingAs($coordinator)
            ->post(route('municipal.results.primary', $participation), ['scores' => [1 => '10', 2 => '8', 3 => '5']])
            ->assertSessionHasNoErrors();
        $fresh = $participation->fresh();
        $this->assertEqualsWithDelta(23, (float) $fresh->primary_score, 0.001);
        $this->assertEqualsWithDelta(23, (float) $fresh->final_score, 0.001);
        $this->assertEqualsWithDelta(10, (float) $fresh->question_scores[1], 0.001);

        // Сумма 20+20 = 40 > 30 — отклоняется.
        $this->actingAs($coordinator)
            ->post(route('municipal.results.primary', $participation), ['scores' => [1 => '20', 2 => '20']])
            ->assertSessionHasErrors('scores');
    }

    public function test_admin_extends_municipal_primary_phase(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate] = $this->ateSchool();
        $olympiad = $this->municipal(now()->subHour(), $year);
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin)->post(route('admin.olympiads.extend', $olympiad), [
            'phase' => 'primary', 'scope' => 'ate', 'ate_id' => $ate->id, 'hours' => 5,
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('olympiad_entry_extensions', [
            'olympiad_id' => $olympiad->id, 'phase' => 'primary', 'scope' => 'ate', 'ate_id' => $ate->id,
        ]);
    }

    public function test_coordinator_exports_municipal_protocol_by_template(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool('01');
        $olympiad = $this->municipal(now()->addDay(), $year);
        $participation = $this->participant($school, $olympiad);
        $participation->update(['primary_score' => 30]); // итог авто = 30
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        // Без шаблона — выгрузка недоступна.
        $this->actingAs($coordinator)->get(route('municipal.results.protocol', $olympiad))
            ->assertRedirect();

        // С общим шаблоном МЭ выгрузка отдаёт XLSX.
        $template = \App\Models\ProtocolTemplate::create(['name' => 'МЭ общий', 'stage' => 'municipal', 'subject_id' => null]);
        $template->columns()->create(['position' => 1, 'header' => '№', 'source_key' => 'row_number']);
        $template->columns()->create(['position' => 2, 'header' => 'Итог', 'source_key' => 'ho.final_score']);

        $this->actingAs($coordinator)->get(route('municipal.results.protocol', $olympiad))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_clear_scores_selected_only_clears_primary_score(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal(now()->addDay(), $year);
        $p1 = $this->participant($school, $olympiad);
        $p1->update(['primary_score' => 30, 'appeal_addition' => 5, 'final_score' => 35]);
        $p2 = $this->participant($school, $olympiad);
        $p2->update(['primary_score' => 20]);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.clear-scores', $olympiad), ['mode' => 'selected', 'ids' => [$p1->id]])
            ->assertSessionHasNoErrors();

        $fresh1 = $p1->fresh();
        $this->assertNull($fresh1->primary_score);
        // Апелляция не трогается; итог пересчитан (0 + 5), а не «залип» на 35.
        $this->assertEqualsWithDelta(5, (float) $fresh1->appeal_addition, 0.001);
        $this->assertEqualsWithDelta(5, (float) $fresh1->final_score, 0.001);
        // Участие не удалено.
        $this->assertDatabaseHas('human_olympiad', ['id' => $p1->id]);

        // Второй участник не выбирался — балл остался.
        $this->assertEqualsWithDelta(20, (float) $p2->fresh()->primary_score, 0.001);
    }

    public function test_clear_scores_resets_final_score_to_null_when_no_appeal(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal(now()->addDay(), $year);
        $p = $this->participant($school, $olympiad);
        $p->update(['primary_score' => 30]);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.clear-scores', $olympiad), ['mode' => 'selected', 'ids' => [$p->id]])
            ->assertSessionHasNoErrors();

        $fresh = $p->fresh();
        $this->assertNull($fresh->primary_score);
        $this->assertNull($fresh->final_score);
    }

    public function test_clear_scores_filtered_by_school(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $schoolA] = $this->ateSchool('01');
        [, $schoolB] = $this->ateSchool('01');
        $olympiad = $this->municipal(now()->addDay(), $year);
        $a = $this->participant($schoolA, $olympiad);
        $a->update(['primary_score' => 10]);
        $b = $this->participant($schoolB, $olympiad);
        $b->update(['primary_score' => 20]);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.clear-scores', $olympiad), ['mode' => 'filtered', 'school' => $schoolA->id])
            ->assertSessionHasNoErrors();

        $this->assertNull($a->fresh()->primary_score);
        $this->assertEqualsWithDelta(20, (float) $b->fresh()->primary_score, 0.001);
    }

    public function test_clear_scores_all_scoped_to_own_ate(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ateA, $schoolA] = $this->ateSchool('01');
        [$ateB, $schoolB] = $this->ateSchool('02');
        $olympiad = $this->municipal(now()->addDay(), $year);
        $a = $this->participant($schoolA, $olympiad);
        $a->update(['primary_score' => 10]);
        $b = $this->participant($schoolB, $olympiad);
        $b->update(['primary_score' => 20]);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ateA->id, 'is_active' => true]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.clear-scores', $olympiad), ['mode' => 'all'])
            ->assertSessionHasNoErrors();

        $this->assertNull($a->fresh()->primary_score);
        $this->assertEqualsWithDelta(20, (float) $b->fresh()->primary_score, 0.001);
    }

    public function test_clear_scores_blocked_when_entry_closed(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal(now()->subHour(), $year);
        $p = $this->participant($school, $olympiad);
        $p->update(['primary_score' => 30]);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.clear-scores', $olympiad), ['mode' => 'selected', 'ids' => [$p->id]])
            ->assertSessionHasErrors('primary_score');

        $this->assertEqualsWithDelta(30, (float) $p->fresh()->primary_score, 0.001);
    }

    public function test_clear_scores_also_clears_question_scores(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Астрономия', 'stage' => 'municipal', 'grades' => '9',
            'date_held' => '2025-12-01', 'status' => 'grading', 'results_deadline' => now()->addDay(), 'question_count' => 3,
        ]);
        $p = $this->participant($school, $olympiad);
        $p->update(['primary_score' => 23, 'question_scores' => [1 => 10, 2 => 8, 3 => 5]]);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.clear-scores', $olympiad), ['mode' => 'selected', 'ids' => [$p->id]])
            ->assertSessionHasNoErrors();

        $fresh = $p->fresh();
        $this->assertNull($fresh->primary_score);
        $this->assertEmpty($fresh->question_scores ?? []);
    }

    private function csv(array $lines): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));

        return new UploadedFile($path, 'r.csv', 'text/csv', null, true);
    }

    /** Строка импорта первичных баллов МЭ (SCORE_TEMPLATE_HEADER: ...Балл, Статус). */
    private function scoreImportRow(int $id, string $score, string $status = ''): string
    {
        $row = array_fill(0, 8, '');
        $row[0] = (string) $id;
        $row[6] = $score;
        $row[7] = $status;

        return implode(';', $row);
    }

    /** Строка импорта апелляций МЭ (APPEAL_TEMPLATE_HEADER: ...Первичный балл, Апелляция, Итог, Статус). */
    private function appealImportRow(int $id, string $addition, string $status = ''): string
    {
        $row = array_fill(0, 10, '');
        $row[0] = (string) $id;
        $row[7] = $addition;
        $row[9] = $status;

        return implode(';', $row);
    }

    private function runScoreImport(User $coordinator, Olympiad $olympiad, UploadedFile $file): array
    {
        $this->actingAs($coordinator);
        $start = $this->post(route('municipal.results.import-scores', $olympiad), ['file' => $file])->json();
        $prog = ['done' => false];
        while (! $prog['done']) {
            $prog = $this->post(route('municipal.results.import-scores.chunk', $start['id']))->json();
        }

        return $prog;
    }

    private function runAppealImport(User $coordinator, Olympiad $olympiad, UploadedFile $file): array
    {
        $this->actingAs($coordinator);
        $start = $this->post(route('municipal.results.import-appeals', $olympiad), ['file' => $file])->json();
        $prog = ['done' => false];
        while (! $prog['done']) {
            $prog = $this->post(route('municipal.results.import-appeals.chunk', $start['id']))->json();
        }

        return $prog;
    }

    public function test_coordinator_sets_status_manually_with_primary_score(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal(now()->addDay(), $year);
        $participation = $this->participant($school, $olympiad);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.primary', $participation), ['primary_score' => '40', 'result_status' => 'prize_winner'])
            ->assertSessionHasNoErrors();

        $this->assertSame('prize_winner', $participation->fresh()->result_status);

        // Неизвестный статус отклоняется.
        $this->actingAs($coordinator)
            ->post(route('municipal.results.primary', $participation), ['primary_score' => '40', 'result_status' => 'bogus'])
            ->assertSessionHasErrors('result_status');
    }

    public function test_coordinator_sets_status_manually_with_appeal(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal', 'grades' => '9',
            'date_held' => '2025-12-01', 'status' => 'grading',
            'results_deadline' => now()->subHour(), 'final_results_deadline' => now()->addDay(),
        ]);
        $olympiad->maxScores()->create(['grade' => 9, 'max_score' => 50]);
        $participation = $this->participant($school, $olympiad);
        $participation->update(['primary_score' => 30, 'result_status' => 'participant']);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.appeal', $participation), ['appeal_addition' => '5', 'result_status' => 'winner'])
            ->assertSessionHasNoErrors();

        $fresh = $participation->fresh();
        $this->assertSame('winner', $fresh->result_status);
        $this->assertEqualsWithDelta(35, (float) $fresh->final_score, 0.001);
    }

    public function test_coordinator_imports_primary_scores_with_status(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal(now()->addDay(), $year);
        $p1 = $this->participant($school, $olympiad);
        $p2 = $this->participant($school, $olympiad);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $csv = $this->csv([
            'ID;ФИО;Школа;Класс;Класс участия;Макс. балл;Балл;Статус',
            $this->scoreImportRow($p1->id, '45', 'призер'),
            $this->scoreImportRow($p2->id, '20', ''),
        ]);

        $prog = $this->runScoreImport($coordinator, $olympiad, $csv);
        $this->assertSame(2, $prog['updated']);

        $this->assertSame('prize_winner', $p1->fresh()->result_status);
        $this->assertSame('participant', $p2->fresh()->result_status); // статус не указан — не меняется
    }

    public function test_score_import_rejects_unknown_status(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal(now()->addDay(), $year);
        $p = $this->participant($school, $olympiad);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $csv = $this->csv([
            'ID;ФИО;Школа;Класс;Класс участия;Макс. балл;Балл;Статус',
            $this->scoreImportRow($p->id, '45', 'непонятно'),
        ]);

        $prog = $this->runScoreImport($coordinator, $olympiad, $csv);
        $this->assertSame(1, $prog['failed']);
        $this->assertNull($p->fresh()->primary_score);
    }

    public function test_coordinator_imports_appeals_with_addition_and_status(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal', 'grades' => '9',
            'date_held' => '2025-12-01', 'status' => 'grading',
            'results_deadline' => now()->subHour(), 'final_results_deadline' => now()->addDay(),
        ]);
        $olympiad->maxScores()->create(['grade' => 9, 'max_score' => 50]);
        $p1 = $this->participant($school, $olympiad);
        $p1->update(['primary_score' => 30]);
        $p2 = $this->participant($school, $olympiad);
        $p2->update(['primary_score' => 20]);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $csv = $this->csv([
            'ID;ФИО;Школа;Класс;Класс участия;Макс. балл;Первичный балл;Апелляция;Итог;Статус',
            $this->appealImportRow($p1->id, '5', 'победитель'),
            $this->appealImportRow($p2->id, '0', 'участник'),
        ]);

        $prog = $this->runAppealImport($coordinator, $olympiad, $csv);
        $this->assertSame(2, $prog['updated']);

        $fresh1 = $p1->fresh();
        $this->assertEqualsWithDelta(5, (float) $fresh1->appeal_addition, 0.001);
        $this->assertEqualsWithDelta(35, (float) $fresh1->final_score, 0.001);
        $this->assertSame('winner', $fresh1->result_status);
        $this->assertSame('participant', $p2->fresh()->result_status);
    }

    public function test_appeal_import_updates_status_for_all_with_primary_score_even_without_addition(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal', 'grades' => '9',
            'date_held' => '2025-12-01', 'status' => 'grading',
            'results_deadline' => now()->subHour(), 'final_results_deadline' => now()->addDay(),
        ]);
        $olympiad->maxScores()->create(['grade' => 9, 'max_score' => 50]);
        // Никто не подавал апелляцию (колонка «Апелляция» пуста), но статус в шаблоне указан у всех.
        $p1 = $this->participant($school, $olympiad);
        $p1->update(['primary_score' => 45]);
        $p2 = $this->participant($school, $olympiad);
        $p2->update(['primary_score' => 20]);
        $noScore = $this->participant($school, $olympiad); // без первичного балла — статус не проставить
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $csv = $this->csv([
            'ID;ФИО;Школа;Класс;Класс участия;Макс. балл;Первичный балл;Апелляция;Итог;Статус',
            $this->appealImportRow($p1->id, '', 'победитель'),
            $this->appealImportRow($p2->id, '', 'участник'),
            $this->appealImportRow($noScore->id, '', 'призер'),
        ]);

        $prog = $this->runAppealImport($coordinator, $olympiad, $csv);
        $this->assertSame(2, $prog['updated']);
        $this->assertSame(1, $prog['failed']);

        $fresh1 = $p1->fresh();
        $this->assertSame('winner', $fresh1->result_status);
        $this->assertNull($fresh1->appeal_addition); // добавка не указывалась — не тронута
        $this->assertSame('participant', $p2->fresh()->result_status);
        $this->assertSame('participant', $noScore->fresh()->result_status); // не изменился
    }

    public function test_appeal_import_blocked_when_appeal_closed(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal(now()->addDay(), $year); // первичный ввод открыт, апелляция ещё не наступила
        $p = $this->participant($school, $olympiad);
        $p->update(['primary_score' => 30]);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $csv = $this->csv([
            'ID;ФИО;Школа;Класс;Класс участия;Макс. балл;Первичный балл;Апелляция;Итог;Статус',
            $this->appealImportRow($p->id, '5', ''),
        ]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.import-appeals', $olympiad), ['file' => $csv])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertNull($p->fresh()->appeal_addition);
    }
}
