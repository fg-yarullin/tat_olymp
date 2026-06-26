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
use Tests\TestCase;

class StatusAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makeSchool(): School
    {
        $ate = Ate::firstOrCreate(['ate_code' => '10'], ['name' => 'АТЕ', 'type' => 'isolated']);
        $msu = Msu::firstOrCreate(['msu_code' => '10'], ['name' => 'МСУ', 'ate_id' => $ate->id]);

        return School::create([
            'oo_code' => 'OO'.(++$this->seq), 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);
    }

    private function olympiad(string $mode = 'operator'): Olympiad
    {
        $year = AcademicYear::firstOrCreate(['name' => '2025/2026'], ['status' => 'current']);
        $o = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Математика', 'stage' => 'school',
            'grades' => '7', 'date_held' => '2025-11-15', 'status' => 'grading', 'auto_status_mode' => $mode,
        ]);
        $o->maxScores()->create(['grade' => 7, 'max_score' => 100]);
        $o->statusThresholds()->create(['grade' => 7, 'prize_from' => 50]);

        return $o;
    }

    private function participant(School $school, Olympiad $o, ?float $score, string $status = 'participant'): HumanOlympiad
    {
        $s = Student::create(['fio' => 'Уч '.(++$this->seq), 'birth_date' => '2011-01-01', 'school_id' => $school->id, 'real_grade' => 7]);

        return HumanOlympiad::create([
            'student_id' => $s->id, 'olympiad_id' => $o->id, 'participation_grade' => 7,
            'score' => $score, 'result_status' => $status,
        ]);
    }

    public function test_operator_auto_status_assigns_prize_and_keeps_manual_winner(): void
    {
        $school = $this->makeSchool();
        $olympiad = $this->olympiad('operator');
        $operator = User::factory()->create(['role' => UserRole::SchoolOperator, 'school_id' => $school->id, 'is_active' => true]);

        $manualWinner = $this->participant($school, $olympiad, 95, 'winner'); // выставлен вручную — не трогаем
        $prize = $this->participant($school, $olympiad, 60);
        $plain = $this->participant($school, $olympiad, 40);
        $noScore = $this->participant($school, $olympiad, null, 'prize_winner'); // без балла — не трогаем

        $this->actingAs($operator)->post(route('school.results.auto-status', $olympiad))
            ->assertSessionHas('success');

        $this->assertSame('winner', $manualWinner->fresh()->result_status); // ручной победитель сохранён
        $this->assertSame('prize_winner', $prize->fresh()->result_status);
        $this->assertSame('participant', $plain->fresh()->result_status);
        $this->assertSame('prize_winner', $noScore->fresh()->result_status); // пропущен (нет балла)
    }

    public function test_operator_cannot_run_when_mode_is_admin(): void
    {
        $school = $this->makeSchool();
        $olympiad = $this->olympiad('admin');
        $operator = User::factory()->create(['role' => UserRole::SchoolOperator, 'school_id' => $school->id, 'is_active' => true]);
        $p = $this->participant($school, $olympiad, 80);

        $this->actingAs($operator)->post(route('school.results.auto-status', $olympiad))
            ->assertSessionHasErrors('auto_status');
        $this->assertSame('participant', $p->fresh()->result_status); // не изменилось
    }

    public function test_admin_auto_status_applies_across_schools(): void
    {
        $s1 = $this->makeSchool();
        $s2 = $this->makeSchool();
        $olympiad = $this->olympiad('admin');
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $a = $this->participant($s1, $olympiad, 90);
        $b = $this->participant($s2, $olympiad, 40);

        $this->actingAs($admin)->post(route('admin.olympiads.auto-status', $olympiad))
            ->assertSessionHas('success');

        $this->assertSame('prize_winner', $a->fresh()->result_status);
        $this->assertSame('participant', $b->fresh()->result_status);
    }

    public function test_threshold_requires_max_score(): void
    {
        $year = AcademicYear::firstOrCreate(['name' => '2025/2026'], ['status' => 'current']);
        $subject = \App\Models\Subject::create(['name' => 'Физика', 'is_active' => true]);
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        // Порог для класса без макс. балла — ошибка валидации.
        $this->actingAs($admin)->post(route('admin.olympiads.store'), [
            'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'stage' => 'school',
            'grades' => [7], 'status' => 'planned', 'date_held' => '2025-11-15',
            'thresholds' => [7 => ['prize_from' => 50]],
        ])->assertSessionHasErrors('thresholds');
    }
}
