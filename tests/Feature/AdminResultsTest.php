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

class AdminResultsTest extends TestCase
{
    use RefreshDatabase;

    private Subject $math;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->math = Subject::create(['name' => 'Математика', 'is_active' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function school(string $ateCode): School
    {
        $ate = Ate::firstOrCreate(['ate_code' => $ateCode], ['name' => 'АТЕ '.$ateCode, 'type' => 'isolated']);
        $msu = Msu::firstOrCreate(['msu_code' => $ateCode], ['name' => 'МСУ '.$ateCode, 'ate_id' => $ate->id]);

        return School::create([
            'oo_code' => 'OO'.(++$this->seq), 'short_name' => 'Школа '.$this->seq, 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => $ateCode, 'ate_id' => $ate->id, 'ate_code' => $ateCode,
        ]);
    }

    private function makeResult(School $school, string $fio, ?Subject $subject = null): HumanOlympiad
    {
        $subject ??= $this->math;
        $year = AcademicYear::firstOrCreate(['name' => '2025/2026'], ['status' => 'current']);
        $olympiad = Olympiad::firstOrCreate(
            ['academic_year_id' => $year->id, 'subject_id' => $subject->id, 'stage' => 'school', 'grades' => '1,2,3,4,5,6,7,8,9,10,11'],
            ['subject' => $subject->name, 'date_held' => '2025-11-15', 'status' => 'grading'],
        );
        $student = Student::create([
            'fio' => $fio, 'birth_date' => '2012-01-01', 'school_id' => $school->id, 'real_grade' => 7,
        ]);

        return HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $olympiad->id,
            'participation_grade' => 7, 'score' => 88, 'result_status' => 'winner',
        ]);
    }

    public function test_requires_subject_then_lists_results(): void
    {
        $school = $this->school('10');
        $this->makeResult($school, 'Иванов Иван');

        // Без предмета — пусто
        $this->actingAs($this->admin())->get(route('admin.results.index'))
            ->assertInertia(fn ($page) => $page->where('results', null));

        // С предметом — есть результат
        $this->actingAs($this->admin())->get(route('admin.results.index', ['subject_id' => $this->math->id]))
            ->assertInertia(fn ($page) => $page->where('results.total', 1)
                ->where('results.data.0.fio', 'Иванов Иван')
                ->where('results.data.0.score', 88));
    }

    public function test_filters_by_ate_and_school_and_participant(): void
    {
        $schoolA = $this->school('10');
        $schoolB = $this->school('20');
        $this->makeResult($schoolA, 'Алексеев Алексей');
        $this->makeResult($schoolB, 'Борисов Борис');

        $admin = $this->admin();
        $ateA = $schoolA->ate_id;

        // Фильтр по АТЕ А
        $admin && $this->actingAs($admin)
            ->get(route('admin.results.index', ['subject_id' => $this->math->id, 'ate_id' => $ateA]))
            ->assertInertia(fn ($page) => $page->where('results.total', 1)
                ->where('results.data.0.fio', 'Алексеев Алексей'));

        // Поиск по участнику
        $this->actingAs($admin)
            ->get(route('admin.results.index', ['subject_id' => $this->math->id, 'q' => 'Борис']))
            ->assertInertia(fn ($page) => $page->where('results.total', 1)
                ->where('results.data.0.fio', 'Борисов Борис'));
    }

    public function test_only_selected_subject_results_shown(): void
    {
        $school = $this->school('10');
        $physics = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $this->makeResult($school, 'Математик', $this->math);
        $this->makeResult($school, 'Физик', $physics);

        $this->actingAs($this->admin())
            ->get(route('admin.results.index', ['subject_id' => $this->math->id]))
            ->assertInertia(fn ($page) => $page->where('results.total', 1)
                ->where('results.data.0.fio', 'Математик'));
    }

    public function test_filters_by_stage(): void
    {
        $school = $this->school('10');
        $year = AcademicYear::firstOrCreate(['name' => '2025/2026'], ['status' => 'current']);

        // Школьный этап
        $this->makeResult($school, 'Школьник');

        // Муниципальный этап — отдельная олимпиада
        $municipal = Olympiad::create([
            'academic_year_id' => $year->id, 'subject_id' => $this->math->id, 'subject' => 'Математика',
            'stage' => 'municipal', 'grades' => '1,2,3,4,5,6,7,8,9,10,11',
            'date_held' => '2025-12-15', 'status' => 'grading',
        ]);
        $student = Student::create(['fio' => 'Муниципал', 'birth_date' => '2012-01-01', 'school_id' => $school->id, 'real_grade' => 7]);
        HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $municipal->id,
            'participation_grade' => 7, 'score' => 70, 'result_status' => 'participant',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.results.index', ['subject_id' => $this->math->id, 'stage' => 'municipal']))
            ->assertInertia(fn ($page) => $page->where('results.total', 1)
                ->where('results.data.0.fio', 'Муниципал'));
    }

    public function test_non_admin_forbidden(): void
    {
        $coord = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'is_active' => true]);

        $this->actingAs($coord)->get(route('admin.results.index'))->assertForbidden();
    }
}
