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

class EntryExtensionTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function school(string $ateCode = '10'): School
    {
        $ate = Ate::firstOrCreate(['ate_code' => $ateCode], ['name' => "АТЕ {$ateCode}", 'type' => 'isolated']);
        $msu = Msu::firstOrCreate(['msu_code' => $ateCode], ['name' => 'МСУ', 'ate_id' => $ate->id]);

        return School::create([
            'oo_code' => 'OO'.(++$this->seq), 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => $ateCode, 'ate_id' => $ate->id, 'ate_code' => $ateCode,
        ]);
    }

    private function operator(School $school): User
    {
        return User::factory()->create(['role' => UserRole::SchoolOperator, 'school_id' => $school->id, 'is_active' => true]);
    }

    private function olympiad(?string $deadline): Olympiad
    {
        $year = AcademicYear::firstOrCreate(['name' => '2025/2026'], ['status' => 'current']);

        return Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Математика', 'stage' => 'school',
            'grades' => '7,8,9,10,11', 'date_held' => '2025-11-15', 'status' => 'grading',
            'results_deadline' => $deadline,
        ]);
    }

    private function postScore(User $operator, Olympiad $olympiad, Student $student)
    {
        return $this->actingAs($operator)->post(route('school.results.store', $olympiad), [
            'student_id' => $student->id, 'participation_grade' => 7, 'score' => '50',
        ]);
    }

    public function test_entry_auto_closes_after_deadline(): void
    {
        $school = $this->school();
        $student = Student::create(['fio' => 'Уч', 'birth_date' => '2012-01-01', 'school_id' => $school->id, 'real_grade' => 7]);
        $olympiad = $this->olympiad(now()->subHour());

        $this->postScore($this->operator($school), $olympiad, $student)->assertSessionHasErrors('score');
        $this->assertDatabaseMissing('human_olympiad', ['student_id' => $student->id]);
    }

    public function test_extension_for_all_reopens_entry(): void
    {
        $school = $this->school();
        $student = Student::create(['fio' => 'Уч', 'birth_date' => '2012-01-01', 'school_id' => $school->id, 'real_grade' => 7]);
        $olympiad = $this->olympiad(now()->subHour());
        $olympiad->entryExtensions()->create(['scope' => 'all', 'extended_until' => now()->addHours(2)]);

        $this->postScore($this->operator($school), $olympiad, $student)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('human_olympiad', ['student_id' => $student->id, 'score' => 50]);
    }

    public function test_extension_targets_only_matching_ate(): void
    {
        $school = $this->school('01');
        $otherAte = Ate::create(['ate_code' => '02', 'name' => 'АТЕ 02', 'type' => 'isolated']);
        $student = Student::create(['fio' => 'Уч', 'birth_date' => '2012-01-01', 'school_id' => $school->id, 'real_grade' => 7]);
        $olympiad = $this->olympiad(now()->subHour());
        // Продление для чужого АТЕ — не открывает.
        $olympiad->entryExtensions()->create(['scope' => 'ate', 'ate_id' => $otherAte->id, 'extended_until' => now()->addHours(2)]);

        $this->postScore($this->operator($school), $olympiad, $student)->assertSessionHasErrors('score');

        // Продление для своего АТЕ — открывает.
        $olympiad->entryExtensions()->create(['scope' => 'ate', 'ate_id' => $school->ate_id, 'extended_until' => now()->addHours(2)]);
        $this->postScore($this->operator($school), $olympiad, $student->fresh())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('human_olympiad', ['student_id' => $student->id]);
    }

    public function test_admin_extend_creates_capped_extension(): void
    {
        $school = $this->school();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $olympiad = $this->olympiad(now()->subHour());

        $this->actingAs($admin)->post(route('admin.olympiads.extend', $olympiad), [
            'scope' => 'all', 'hours' => 5,
        ])->assertSessionHas('success');

        $ext = $olympiad->entryExtensions()->first();
        $this->assertNotNull($ext);
        $this->assertSame('all', $ext->scope);
        $this->assertTrue($ext->extended_until->isFuture());
        // Потолок: не позже срока закрытия + 48 ч.
        $this->assertTrue($ext->extended_until->lte($olympiad->results_deadline->copy()->addHours(48)));
    }

    public function test_admin_extend_rejected_when_more_than_48h_passed(): void
    {
        $school = $this->school();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $olympiad = $this->olympiad(now()->subHours(50)); // потолок (срок+48ч) уже в прошлом

        $this->actingAs($admin)->post(route('admin.olympiads.extend', $olympiad), [
            'scope' => 'all', 'hours' => 5,
        ])->assertSessionHasErrors('extend');

        $this->assertSame(0, $olympiad->entryExtensions()->count());
    }
}
