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

class SchoolInvitationsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function school(): School
    {
        $ate = Ate::firstOrCreate(['ate_code' => '10'], ['name' => 'АТЕ', 'type' => 'isolated']);
        $msu = Msu::firstOrCreate(['msu_code' => '10'], ['name' => 'МСУ', 'ate_id' => $ate->id]);

        return School::create([
            'oo_code' => 'OO'.(++$this->seq), 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);
    }

    public function test_operator_sees_and_exports_own_invited_list(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $schoolA = $this->school();
        $schoolB = $this->school();
        $operator = User::factory()->create(['role' => UserRole::SchoolOperator, 'school_id' => $schoolA->id, 'is_active' => true]);

        $municipal = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01', 'status' => 'grading']);
        $mine = Student::create(['fio' => 'Свой Ученик', 'birth_date' => '2010-01-01', 'school_id' => $schoolA->id, 'real_grade' => 9, 'class_letter' => 'А']);
        $other = Student::create(['fio' => 'Чужой Ученик', 'birth_date' => '2010-01-01', 'school_id' => $schoolB->id, 'real_grade' => 9]);
        HumanOlympiad::create(['student_id' => $mine->id, 'olympiad_id' => $municipal->id, 'participation_grade' => 9, 'inclusion_basis' => 'school_stage']);
        HumanOlympiad::create(['student_id' => $other->id, 'olympiad_id' => $municipal->id, 'participation_grade' => 9, 'inclusion_basis' => 'school_stage']);

        // Список олимпиад с приглашёнными своей школы (только 1 приглашённый).
        $this->actingAs($operator)->get(route('school.invitations.index'))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->component('School/Invitations/Index')
                ->where('olympiads.0.invited', 1));

        // Детализация — только свой ученик.
        $this->actingAs($operator)->get(route('school.invitations.show', $municipal))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->where('participants', fn ($d) => count($d) === 1 && $d[0]['fio'] === 'Свой Ученик' && $d[0]['class'] === '9-А'));

        // Выгрузка XLSX.
        $this->actingAs($operator)->get(route('school.invitations.xlsx', $municipal))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_index_empty_when_no_invitations(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $school = $this->school();
        $operator = User::factory()->create(['role' => UserRole::SchoolOperator, 'school_id' => $school->id, 'is_active' => true]);

        $this->actingAs($operator)->get(route('school.invitations.index'))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->where('olympiads', []));
    }
}
