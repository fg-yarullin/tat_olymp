<?php

namespace Tests\Feature;

use App\Models\Ate;
use App\Models\AcademicYear;
use App\Models\HumanOlympiad;
use App\Models\Msu;
use App\Models\Olympiad;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GuestShowcaseTest extends TestCase
{
    use RefreshDatabase;

    private int $schoolSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Inertia рендерит app.blade.php с @vite — в тестах ассеты не собраны
        $this->withoutVite();
        // Замораживаем время внутри дневного окна показа (12:00 по Europe/Moscow == 09:00 UTC)
        Carbon::setTestNow(Carbon::parse('2026-06-09 09:00:00', 'UTC'));
    }

    private function makeStudent(string $fio, string $birth = '2012-03-01'): Student
    {
        $ate = Ate::firstOrCreate(['ate_code' => '10'], ['name' => 'АТЕ', 'type' => 'isolated']);
        $msu = Msu::firstOrCreate(['msu_code' => '10'], ['name' => 'МСУ', 'ate_id' => $ate->id]);
        $school = School::create([
            'oo_code' => '1000'.(++$this->schoolSeq), 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);

        return Student::create([
            'fio' => $fio, 'birth_date' => $birth, 'school_id' => $school->id, 'real_grade' => 7,
        ]);
    }

    private function makePublishedWork(Student $student, Carbon $publishedAt, string $status = 'participant'): HumanOlympiad
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Математика', 'stage' => 'school',
            'date_held' => '2025-11-15', 'published_at' => $publishedAt,
        ]);

        return HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $olympiad->id,
            'participation_grade' => 7, 'score' => 88.5, 'result_status' => $status,
        ]);
    }

    public function test_login_succeeds_and_sets_guest_session(): void
    {
        $this->makeStudent('Петров Иван Сергеевич');

        $response = $this->post('/showcase', [
            'fio' => 'Петров Иван Сергеевич',
            'birth_date' => '2012-03-01',
        ]);

        $response->assertRedirect(route('guest.works'));
        $this->assertNotNull(session('guest_student_id'));
    }

    public function test_login_fails_with_wrong_data(): void
    {
        $this->makeStudent('Петров Иван Сергеевич');

        $response = $this->post('/showcase', [
            'fio' => 'Петров Иван Сергеевич',
            'birth_date' => '2000-01-01',
        ]);

        $response->assertSessionHasErrors('fio');
        $this->assertNull(session('guest_student_id'));
    }

    public function test_guest_can_view_own_published_work_within_window(): void
    {
        $student = $this->makeStudent('Петров Иван Сергеевич');
        $work = $this->makePublishedWork($student, now()->subHours(2));

        $response = $this->withSession(['guest_student_id' => $student->id])
            ->get(route('guest.work.view', $work));

        $response->assertOk();
    }

    public function test_guest_cannot_view_other_students_work(): void
    {
        $owner = $this->makeStudent('Петров Иван Сергеевич');
        $intruder = $this->makeStudent('Сидоров Пётр Петрович', '2011-05-05');
        $work = $this->makePublishedWork($owner, now()->subHours(2));

        $response = $this->withSession(['guest_student_id' => $intruder->id])
            ->get(route('guest.work.view', $work));

        $response->assertForbidden();
    }

    public function test_view_blocked_after_48_hours(): void
    {
        $student = $this->makeStudent('Петров Иван Сергеевич');
        $work = $this->makePublishedWork($student, now()->subHours(49));

        $response = $this->withSession(['guest_student_id' => $student->id])
            ->get(route('guest.work.view', $work));

        $response->assertForbidden();
    }

    public function test_unauthenticated_guest_redirected_to_login(): void
    {
        $student = $this->makeStudent('Петров Иван Сергеевич');
        $work = $this->makePublishedWork($student, now()->subHours(2));

        $this->get(route('guest.work.view', $work))
            ->assertRedirect(route('guest.login'));
    }

    public function test_appeal_transitions_status_to_appealed(): void
    {
        $student = $this->makeStudent('Петров Иван Сергеевич');
        $work = $this->makePublishedWork($student, now()->subHours(2), 'participant');

        $this->withSession(['guest_student_id' => $student->id])
            ->post(route('guest.appeal.submit', $work))
            ->assertRedirect();

        $this->assertSame('appealed', $work->fresh()->result_status);
    }
}
