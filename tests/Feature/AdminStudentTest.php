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

class AdminStudentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function school(): School
    {
        $ate = Ate::firstOrCreate(['ate_code' => '10'], ['name' => 'АТЕ', 'type' => 'isolated']);
        $msu = Msu::firstOrCreate(['msu_code' => '10'], ['name' => 'МСУ', 'ate_id' => $ate->id]);

        return School::create([
            'oo_code' => '10001', 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);
    }

    public function test_admin_creates_student(): void
    {
        $school = $this->school();

        $this->actingAs($this->admin())
            ->post(route('admin.students.store'), [
                'fio' => 'Иванов Иван', 'birth_date' => '2012-05-01', 'snils' => '111-222-333 44',
                'school_id' => $school->id, 'real_grade' => 7, 'status' => 'active',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('students', ['fio' => 'Иванов Иван', 'school_id' => $school->id, 'real_grade' => 7]);
    }

    public function test_ovz_defaults_null_and_can_be_set(): void
    {
        $school = $this->school();
        $admin = $this->admin();

        // Без поля ОВЗ -> остаётся пустым (null)
        $this->actingAs($admin)->post(route('admin.students.store'), [
            'fio' => 'Без ОВЗ', 'birth_date' => '2012-05-01',
            'school_id' => $school->id, 'real_grade' => 7, 'status' => 'active',
        ]);
        $this->assertNull(\App\Models\Student::where('fio', 'Без ОВЗ')->value('ovz'));

        // С признаком ОВЗ
        $this->actingAs($admin)->post(route('admin.students.store'), [
            'fio' => 'С ОВЗ', 'birth_date' => '2012-05-01',
            'school_id' => $school->id, 'real_grade' => 7, 'status' => 'active', 'ovz' => true,
        ]);
        $this->assertTrue((bool) \App\Models\Student::where('fio', 'С ОВЗ')->value('ovz'));
    }

    public function test_gender_defaults_null_and_can_be_set(): void
    {
        $school = $this->school();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.students.store'), [
            'fio' => 'Без пола', 'birth_date' => '2012-05-01',
            'school_id' => $school->id, 'real_grade' => 7, 'status' => 'active',
        ]);
        $this->assertNull(\App\Models\Student::where('fio', 'Без пола')->value('gender'));

        $this->actingAs($admin)->post(route('admin.students.store'), [
            'fio' => 'Девочка', 'birth_date' => '2012-05-01', 'gender' => 'female',
            'school_id' => $school->id, 'real_grade' => 7, 'status' => 'active',
        ]);
        $this->assertSame('female', \App\Models\Student::where('fio', 'Девочка')->value('gender'));
    }

    public function test_grade_must_be_between_1_and_11(): void
    {
        $school = $this->school();

        $this->actingAs($this->admin())
            ->post(route('admin.students.store'), [
                'fio' => 'Тест', 'birth_date' => '2012-05-01',
                'school_id' => $school->id, 'real_grade' => 12, 'status' => 'active',
            ])
            ->assertSessionHasErrors('real_grade');
    }

    public function test_anonymized_student_cannot_be_edited(): void
    {
        $school = $this->school();
        $student = Student::create([
            'fio' => 'Удалённые данные', 'birth_date' => '1900-01-01', 'school_id' => $school->id,
            'real_grade' => 9, 'status' => 'active', 'anonymized_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->put(route('admin.students.update', $student), [
                'fio' => 'Восстановленное ФИО', 'birth_date' => '2010-01-01',
                'school_id' => $school->id, 'real_grade' => 9, 'status' => 'active',
            ])
            ->assertSessionHasErrors('student');

        $this->assertSame('Удалённые данные', $student->fresh()->fio);
    }

    public function test_cannot_delete_student_with_participations(): void
    {
        $school = $this->school();
        $student = Student::create([
            'fio' => 'Участник', 'birth_date' => '2011-01-01', 'school_id' => $school->id,
            'real_grade' => 9, 'status' => 'active',
        ]);
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Математика', 'stage' => 'school',
            'date_held' => '2025-11-15', 'status' => 'planned',
        ]);
        HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $olympiad->id,
            'participation_grade' => 9, 'result_status' => 'participant',
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.students.destroy', $student))
            ->assertSessionHasErrors('student');

        $this->assertDatabaseHas('students', ['id' => $student->id]);
    }

    public function test_filters_by_ate_school_and_grade(): void
    {
        $ateA = Ate::firstOrCreate(['ate_code' => 'A'], ['name' => 'Район А', 'type' => 'isolated']);
        $ateB = Ate::firstOrCreate(['ate_code' => 'B'], ['name' => 'Район Б', 'type' => 'isolated']);
        $msuA = Msu::firstOrCreate(['msu_code' => 'A'], ['name' => 'МСУ А', 'ate_id' => $ateA->id]);
        $msuB = Msu::firstOrCreate(['msu_code' => 'B'], ['name' => 'МСУ Б', 'ate_id' => $ateB->id]);
        $schoolA = $this->schoolIn($ateA, $msuA, 'AA1');
        $schoolB = $this->schoolIn($ateB, $msuB, 'BB1');

        $target = Student::create(['fio' => 'Нужный', 'birth_date' => '2012-01-01', 'school_id' => $schoolA->id, 'real_grade' => 7]);
        Student::create(['fio' => 'Другой класс', 'birth_date' => '2012-01-01', 'school_id' => $schoolA->id, 'real_grade' => 9]);
        Student::create(['fio' => 'Другой район', 'birth_date' => '2012-01-01', 'school_id' => $schoolB->id, 'real_grade' => 7]);

        // Фильтр по району А + класс 7 -> только «Нужный»
        $this->actingAs($this->admin())
            ->get(route('admin.students.index', ['ate_id' => $ateA->id, 'grade' => 7]))
            ->assertInertia(fn ($page) => $page
                ->where('students.data', fn ($rows) => count($rows) === 1 && $rows[0]['fio'] === 'Нужный'));

        // Фильтр по школе B -> только «Другой район»
        $this->actingAs($this->admin())
            ->get(route('admin.students.index', ['school_id' => $schoolB->id]))
            ->assertInertia(fn ($page) => $page
                ->where('students.data', fn ($rows) => count($rows) === 1 && $rows[0]['fio'] === 'Другой район'));
    }

    private function schoolIn(Ate $ate, Msu $msu, string $oo): School
    {
        return School::create([
            'oo_code' => $oo, 'short_name' => 'Школа '.$oo, 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => $msu->msu_code, 'ate_id' => $ate->id, 'ate_code' => $ate->ate_code,
        ]);
    }

    public function test_non_admin_forbidden(): void
    {
        $operator = User::factory()->create(['role' => UserRole::SchoolOperator, 'is_active' => true]);

        $this->actingAs($operator)->get(route('admin.students.index'))->assertForbidden();
    }
}
