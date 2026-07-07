<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\HumanOlympiad;
use App\Models\Msu;
use App\Models\Olympiad;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MunicipalTeacherBackfillTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

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
        return Student::create(['fio' => 'Уч '.(++$this->seq), 'birth_date' => '2010-01-01', 'school_id' => $school->id, 'real_grade' => $grade]);
    }

    public function test_backfill_fills_only_empty_fields_from_school_stage(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Труд (технология)', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $school = $this->school($ate);

        $she = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Труд (технология)', 'subject_id' => $subject->id,
            'stage' => 'school', 'grades' => '9', 'date_held' => '2025-11-01',
        ]);
        $mun = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Труд (технология)', 'subject_id' => $subject->id,
            'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01',
        ]);

        // Участник, приглашённый до фикса — поля пусты, хотя на ШЭ они заполнены.
        $empty = $this->student($school, 9);
        HumanOlympiad::create([
            'student_id' => $empty->id, 'olympiad_id' => $she->id, 'participation_grade' => 9,
            'teacher_name' => 'Иванов И.И.', 'teacher_workplace' => 'Школа №1',
            'profile' => 'Направление А', 'practice_types' => '1.1 Практика',
        ]);
        $emptyMun = HumanOlympiad::create(['student_id' => $empty->id, 'olympiad_id' => $mun->id, 'participation_grade' => 9]);

        // Участник с уже заполненным вручную местом работы — не должен перезаписаться данными ШЭ.
        $filled = $this->student($school, 9);
        HumanOlympiad::create([
            'student_id' => $filled->id, 'olympiad_id' => $she->id, 'participation_grade' => 9,
            'teacher_name' => 'Петров П.П.', 'teacher_workplace' => 'Школа №2',
        ]);
        $filledMun = HumanOlympiad::create([
            'student_id' => $filled->id, 'olympiad_id' => $mun->id, 'participation_grade' => 9,
            'teacher_name' => 'Свой тренер', 'teacher_workplace' => 'Своя школа',
        ]);

        $this->artisan('municipal:backfill-teacher-fields', ['--olympiad' => $mun->id])
            ->assertSuccessful();

        $freshEmpty = $emptyMun->fresh();
        $this->assertSame('Иванов И.И.', $freshEmpty->teacher_name);
        $this->assertSame('Школа №1', $freshEmpty->teacher_workplace);
        $this->assertSame('Направление А', $freshEmpty->profile);
        $this->assertSame('1.1 Практика', $freshEmpty->practice_types);

        $freshFilled = $filledMun->fresh();
        $this->assertSame('Свой тренер', $freshFilled->teacher_name);
        $this->assertSame('Своя школа', $freshFilled->teacher_workplace);
    }

    public function test_backfill_scoped_to_single_olympiad_does_not_touch_others(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $subject = Subject::create(['name' => 'Труд (технология)', 'is_active' => true]);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $school = $this->school($ate);

        $she = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Труд (технология)', 'subject_id' => $subject->id,
            'stage' => 'school', 'grades' => '9', 'date_held' => '2025-11-01',
        ]);
        $mun1 = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Труд (технология)', 'subject_id' => $subject->id,
            'stage' => 'municipal', 'grades' => '9', 'date_held' => '2025-12-01',
        ]);
        $mun2 = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Труд (технология)', 'subject_id' => $subject->id,
            'stage' => 'municipal', 'grades' => '10', 'date_held' => '2025-12-01',
        ]);

        $s1 = $this->student($school, 9);
        HumanOlympiad::create([
            'student_id' => $s1->id, 'olympiad_id' => $she->id, 'participation_grade' => 9,
            'profile' => 'Направление А',
        ]);
        $mun1Row = HumanOlympiad::create(['student_id' => $s1->id, 'olympiad_id' => $mun1->id, 'participation_grade' => 9]);
        $mun2Row = HumanOlympiad::create(['student_id' => $s1->id, 'olympiad_id' => $mun2->id, 'participation_grade' => 9]);

        $this->artisan('municipal:backfill-teacher-fields', ['--olympiad' => $mun1->id])->assertSuccessful();

        $this->assertSame('Направление А', $mun1Row->fresh()->profile);
        $this->assertNull($mun2Row->fresh()->profile);
    }
}
