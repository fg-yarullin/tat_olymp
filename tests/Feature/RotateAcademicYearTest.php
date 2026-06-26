<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RotateAcademicYearTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchool(): School
    {
        $ate = Ate::firstOrCreate(['ate_code' => '10'], ['name' => 'АТЕ', 'type' => 'isolated']);
        $msu = Msu::firstOrCreate(['msu_code' => '10'], ['name' => 'МСУ', 'ate_id' => $ate->id]);

        return School::create([
            'oo_code' => '10001', 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);
    }

    private function makeStudent(School $school, int $grade, string $status = 'active'): Student
    {
        return Student::create([
            'fio' => "Ученик $grade $status", 'birth_date' => '2012-03-01',
            'school_id' => $school->id, 'real_grade' => $grade, 'status' => $status,
        ]);
    }

    public function test_rotation_archives_year_graduates_and_promotes(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $school = $this->makeSchool();
        $graduate = $this->makeStudent($school, 11);
        $promoted = $this->makeStudent($school, 7);
        $alreadyGraduated = $this->makeStudent($school, 11, 'graduated');

        $this->artisan('season:rotate', ['--force' => true])->assertSuccessful();

        // Год: новый «Текущий», прежний в архиве
        $this->assertSame('archive', AcademicYear::where('name', '2025/2026')->value('status'));
        $this->assertSame('current', AcademicYear::where('name', '2026/2027')->value('status'));

        // 11 класс выпускается, его класс НЕ инкрементируется
        $graduate->refresh();
        $this->assertSame('graduated', $graduate->status);
        $this->assertSame(11, $graduate->real_grade);

        // 1–10 класс: класс +1
        $this->assertSame(8, $promoted->refresh()->real_grade);

        // Уже выпустившийся не трогается
        $this->assertSame('graduated', $alreadyGraduated->refresh()->status);
    }

    public function test_rotation_accepts_explicit_name(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);

        $this->artisan('season:rotate', ['name' => '2030/2031', '--force' => true])
            ->assertSuccessful();

        $this->assertSame('current', AcademicYear::where('name', '2030/2031')->value('status'));
    }

    public function test_rotation_rejects_duplicate_year(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);

        $this->artisan('season:rotate', ['name' => '2025/2026', '--force' => true])
            ->assertFailed();
    }
}
