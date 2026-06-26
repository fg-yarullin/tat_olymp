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
use Illuminate\Http\Testing\File;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MunicipalInvitedImportTest extends TestCase
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

    private function student(School $school): Student
    {
        return Student::create(['fio' => 'Уч '.(++$this->seq), 'birth_date' => '2010-01-01', 'school_id' => $school->id, 'real_grade' => 9]);
    }

    /** @return array{0:Olympiad,1:Olympiad,2:Ate,3:School} [ШЭ, МЭ, АТЕ, школа] */
    private function scenario(): array
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $school = $this->school($ate);

        $she = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id,
            'stage' => 'school', 'grades' => '9', 'date_held' => '2025-11-01',
        ]);
        $mun = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id,
            'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01',
        ]);

        return [$she, $mun, $ate, $school];
    }

    private function sheWork(Olympiad $she, Student $s, float $score, string $status): HumanOlympiad
    {
        return HumanOlympiad::create([
            'student_id' => $s->id, 'olympiad_id' => $she->id, 'participation_grade' => 9,
            'score' => $score, 'result_status' => $status,
        ]);
    }

    public function test_coordinator_views_and_exports_school_stage_results(): void
    {
        [$she, $mun, $ate, $school] = $this->scenario();
        $winner = $this->student($school);
        $this->sheWork($she, $winner, 90, 'winner');
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)->get(route('municipal.results.school-stage', $mun))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Municipal/SchoolResults/Index')
                ->where('rows.total', 1)
                ->where('rows.data.0.student_id', $winner->id)
                ->where('rows.data.0.result_status', 'winner'));

        $this->actingAs($coordinator)->get(route('municipal.results.school-stage-export', $mun))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_import_invited_creates_municipal_participations(): void
    {
        [$she, $mun, $ate, $school] = $this->scenario();
        [, $foreignAteMun] = [null, $mun];
        $a = $this->student($school);
        $b = $this->student($school);
        $this->sheWork($she, $a, 90, 'winner');
        $this->sheWork($she, $b, 70, 'prize_winner');

        // Чужой АТЕ — должен быть пропущен.
        $ateB = Ate::create(['ate_code' => '02', 'name' => 'АТЕ B', 'type' => 'isolated']);
        $foreign = $this->student($this->school($ateB));

        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        // В файле оставлены только приглашённые (a и b), плюс чужой ученик (пропустится).
        $csv = "Олимпиада;Физика\nКод олимпиады (не изменять);{$mun->id}\n"
            ."ID;ФИО;Школа;Класс;Класс участия;Балл;Статус;Призёр\n"
            ."{$a->id};Уч;Школа;9;9;90;победитель;\n"
            ."{$b->id};Уч;Школа;9;9;70;призёр;\n"
            ."{$foreign->id};Чужой;Школа B;9;9;50;участник;\n";
        $file = File::createWithContent('priglashennye.csv', $csv);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.import-invited', $mun), ['file' => $file])
            ->assertSessionHas('success');

        $this->assertTrue(HumanOlympiad::where('olympiad_id', $mun->id)->where('student_id', $a->id)->where('participation_grade', 9)->exists());
        $this->assertTrue(HumanOlympiad::where('olympiad_id', $mun->id)->where('student_id', $b->id)->exists());
        $this->assertFalse(HumanOlympiad::where('olympiad_id', $mun->id)->where('student_id', $foreign->id)->exists());
    }

    public function test_import_rejected_when_file_from_other_olympiad(): void
    {
        [$she, $mun, $ate, $school] = $this->scenario();
        $a = $this->student($school);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $csv = "Олимпиада;Физика\nКод олимпиады (не изменять);999999\n"
            ."ID;ФИО;Школа;Класс;Класс участия\n{$a->id};Уч;Школа;9;9\n";
        $file = File::createWithContent('priglashennye.csv', $csv);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.import-invited', $mun), ['file' => $file])
            ->assertSessionHasErrors('file');

        $this->assertFalse(HumanOlympiad::where('olympiad_id', $mun->id)->exists());
    }
}
