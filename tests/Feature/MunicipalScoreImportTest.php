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

class MunicipalScoreImportTest extends TestCase
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

    private function municipal(AcademicYear $year, ?string $resultsDeadline = null): Olympiad
    {
        $o = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal',
            'grades' => '9', 'date_held' => '2025-12-01', 'status' => 'grading', 'results_deadline' => $resultsDeadline,
        ]);
        $o->maxScores()->create(['grade' => 9, 'max_score' => 50]);

        return $o;
    }

    private function participant(School $school, Olympiad $o): HumanOlympiad
    {
        $s = Student::create(['fio' => 'Уч '.(++$this->seq), 'birth_date' => '2010-01-01', 'school_id' => $school->id, 'real_grade' => 9]);

        return HumanOlympiad::create(['student_id' => $s->id, 'olympiad_id' => $o->id, 'participation_grade' => 9, 'result_status' => 'participant']);
    }

    private function csv(array $lines): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));

        return new UploadedFile($path, 'r.csv', 'text/csv', null, true);
    }

    /** Запускает импорт баллов МЭ (по частям) и доводит его до завершения; возвращает итог. */
    private function runImport(User $coordinator, Olympiad $olympiad, UploadedFile $file): array
    {
        $this->actingAs($coordinator);
        $start = $this->post(route('municipal.results.import-scores', $olympiad), ['file' => $file])->json();
        $prog = ['done' => false];
        while (! $prog['done']) {
            $prog = $this->post(route('municipal.results.import-scores.chunk', $start['id']))->json();
        }

        return $prog;
    }

    public function test_template_lists_participants_in_ate_scope(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal($year);
        $p = $this->participant($school, $olympiad);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $res = $this->actingAs($coordinator)->get(route('municipal.results.score-template', $olympiad));
        $res->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($tmp, $res->streamedContent());
        $text = collect(\PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet()->toArray())->flatten()->implode(' ');
        @unlink($tmp);

        $this->assertStringContainsString((string) $p->id, $text);
        $this->assertStringContainsString('Макс. балл', $text);
    }

    public function test_coordinator_imports_scores_by_participation_id(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal($year);
        $p1 = $this->participant($school, $olympiad);
        $p2 = $this->participant($school, $olympiad);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $csv = $this->csv([
            'ID;Балл',
            "{$p1->id};45",
            "{$p2->id};60", // выше максимума (50) — пропуск
        ]);

        $prog = $this->runImport($coordinator, $olympiad, $csv);
        $this->assertSame(1, $prog['updated']);
        $this->assertSame(1, $prog['failed']);

        $this->assertEqualsWithDelta(45, (float) $p1->fresh()->primary_score, 0.001);
        $this->assertNull($p2->fresh()->primary_score);
    }

    public function test_import_ignores_participation_outside_ate_scope(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ateA, $schoolA] = $this->ateSchool('01');
        [, $schoolB] = $this->ateSchool('02');
        $olympiad = $this->municipal($year);
        $mine = $this->participant($schoolA, $olympiad);
        $foreign = $this->participant($schoolB, $olympiad);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ateA->id, 'is_active' => true]);

        $csv = $this->csv(['ID;Балл', "{$mine->id};30", "{$foreign->id};30"]);

        $prog = $this->runImport($coordinator, $olympiad, $csv);
        $this->assertSame(1, $prog['updated']);
        $this->assertSame(1, $prog['failed']);
        $this->assertNull($foreign->fresh()->primary_score);
    }

    public function test_import_blocked_when_entry_closed(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal($year, now()->subHour()); // срок ввода истёк
        $p = $this->participant($school, $olympiad);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $csv = $this->csv(['ID;Балл', "{$p->id};30"]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.import-scores', $olympiad), ['file' => $csv])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertNull($p->fresh()->primary_score);
    }

    public function test_super_coordinator_imports_across_kazan_districts(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$d1, $s1] = $this->ateSchool('54');
        [$d2, $s2] = $this->ateSchool('55');
        $olympiad = $this->municipal($year);
        $p1 = $this->participant($s1, $olympiad);
        $p2 = $this->participant($s2, $olympiad);

        $super = User::factory()->create(['role' => UserRole::SuperCoordinator, 'ate_id' => $d1->id, 'is_active' => true]);
        $super->coordinatorAtes()->sync([$d1->id, $d2->id]);

        $csv = $this->csv(['ID;Балл', "{$p1->id};20", "{$p2->id};25"]);
        $prog = $this->runImport($super, $olympiad, $csv);

        $this->assertSame(2, $prog['updated']);
        $this->assertEqualsWithDelta(20, (float) $p1->fresh()->primary_score, 0.001);
        $this->assertEqualsWithDelta(25, (float) $p2->fresh()->primary_score, 0.001);
    }

    public function test_import_processes_over_multiple_chunks(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal($year);
        $olympiad->maxScores()->update(['max_score' => 100]);

        $lines = ['ID;Балл'];
        $ids = [];
        for ($k = 0; $k < 200; $k++) {
            $p = $this->participant($school, $olympiad);
            $ids[] = $p->id;
            $lines[] = "{$p->id};30";
        }
        $csv = $this->csv($lines);

        $this->actingAs($coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]));
        $start = $this->post(route('municipal.results.import-scores', $olympiad), ['file' => $csv])->json();
        $this->assertSame(200, $start['total']);

        $chunks = 0;
        $prog = ['done' => false];
        while (! $prog['done']) {
            $prog = $this->post(route('municipal.results.import-scores.chunk', $start['id']))->json();
            $chunks++;
        }

        $this->assertGreaterThanOrEqual(2, $chunks);
        $this->assertSame(200, $prog['updated']);
        $this->assertSame(200, HumanOlympiad::whereIn('id', $ids)->where('primary_score', 30)->count());
    }

    public function test_other_roles_forbidden(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [, $school] = $this->ateSchool();
        $olympiad = $this->municipal($year);
        $chair = User::factory()->create(['role' => UserRole::CommissionChair, 'is_active' => true]);

        $this->actingAs($chair)
            ->post(route('municipal.results.import-scores', $olympiad), ['file' => $this->csv(['ID;Балл'])])
            ->assertForbidden();
    }
}
