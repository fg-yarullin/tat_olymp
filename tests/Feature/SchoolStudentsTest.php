<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolStudentsTest extends TestCase
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

    private function operator(School $school): User
    {
        return User::factory()->create([
            'role' => UserRole::SchoolOperator, 'school_id' => $school->id, 'is_active' => true,
        ]);
    }

    private function student(School $school, string $fio, int $grade): Student
    {
        return Student::create([
            'fio' => $fio, 'birth_date' => '2012-01-01', 'school_id' => $school->id, 'real_grade' => $grade,
        ]);
    }

    public function test_lists_with_grade_and_letter_filter(): void
    {
        $school = $this->makeSchool();
        $other = $this->makeSchool();
        Student::create(['fio' => 'Седьмой А', 'birth_date' => '2012-01-01', 'school_id' => $school->id, 'real_grade' => 7, 'class_letter' => 'А']);
        Student::create(['fio' => 'Седьмой Б', 'birth_date' => '2012-01-01', 'school_id' => $school->id, 'real_grade' => 7, 'class_letter' => 'Б']);
        $this->student($other, 'Чужой', 7);

        // Фильтр класс 7 + литера А
        $this->actingAs($this->operator($school))
            ->get(route('school.students.index', ['grade' => 7, 'letter' => 'А']))
            ->assertInertia(fn ($page) => $page
                ->where('students.total', 1)
                ->where('students.data.0.fio', 'Седьмой А'));
    }

    public function test_operator_creates_student_with_class_letter(): void
    {
        $school = $this->makeSchool();

        $this->actingAs($this->operator($school))
            ->post(route('school.students.store'), [
                'fio' => 'Новиков Новик', 'birth_date' => '2012-05-01', 'real_grade' => 7,
                'class_letter' => 'а', 'snils' => '111-111-111 11',
            ])
            ->assertSessionHas('success');

        // Литера приводится к прописной
        $this->assertDatabaseHas('students', [
            'fio' => 'Новиков Новик', 'school_id' => $school->id, 'real_grade' => 7,
            'class_letter' => 'А', 'status' => 'active',
        ]);
    }

    public function test_create_without_letter(): void
    {
        $school = $this->makeSchool();

        $this->actingAs($this->operator($school))
            ->post(route('school.students.store'), [
                'fio' => 'Без литеры', 'birth_date' => '2012-05-01', 'real_grade' => 7,
            ])
            ->assertSessionHas('success');

        $student = Student::where('fio', 'Без литеры')->first();
        $this->assertNull($student->class_letter);
        $this->assertSame('7', $student->className());
    }

    public function test_snils_unique_within_school_on_create(): void
    {
        $school = $this->makeSchool();
        Student::create(['fio' => 'Первый', 'birth_date' => '2012-01-01', 'school_id' => $school->id, 'real_grade' => 7, 'snils' => '12345678900']);

        $this->actingAs($this->operator($school))
            ->post(route('school.students.store'), [
                'fio' => 'Второй', 'birth_date' => '2012-02-02', 'real_grade' => 7, 'snils' => '12345678900',
            ])
            ->assertSessionHasErrors('snils');
    }

    public function test_import_creates_students_with_letters(): void
    {
        $school = $this->makeSchool();

        $lines = [
            'ФИО;Дата рождения;СНИЛС;Пол;ОВЗ;Класс;Литера',
            'Иванов Иван;15.03.2012;111-111-111 11;м;;7;а',
            'Петрова Анна;01.09.2011;;ж;1;8;',
        ];
        $path = tempnam(sys_get_temp_dir(), 'st').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));
        $file = new \Illuminate\Http\UploadedFile($path, 's.csv', 'text/csv', null, true);

        // Импорт — по частям: старт возвращает JSON, затем чанки до завершения.
        $this->actingAs($this->operator($school));
        $start = $this->post(route('school.students.import'), ['file' => $file])->json();
        $prog = ['done' => false];
        while (! $prog['done']) {
            $prog = $this->post(route('school.students.import.chunk', $start['id']))->json();
        }
        $this->assertSame(2, $prog['created']);

        $this->assertSame(2, Student::where('school_id', $school->id)->count());
        $ivanov = Student::where('fio', 'Иванов Иван')->first();
        $this->assertSame('А', $ivanov->class_letter); // приведено к прописной
        $this->assertSame('7-А', $ivanov->className());
        $this->assertSame('2012-03-15', $ivanov->birth_date->toDateString());
        $this->assertNull(Student::where('fio', 'Петрова Анна')->first()->class_letter);
    }

    public function test_import_processes_over_multiple_chunks(): void
    {
        $school = $this->makeSchool();
        $lines = ['ФИО;Дата рождения;СНИЛС;Пол;ОВЗ;Класс;Литера'];
        for ($k = 0; $k < 220; $k++) {
            $lines[] = "Учащийся {$k};01.09.2012;;;;7;";
        }
        $path = tempnam(sys_get_temp_dir(), 'st').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));
        $file = new \Illuminate\Http\UploadedFile($path, 's.csv', 'text/csv', null, true);

        $this->actingAs($this->operator($school));
        $start = $this->post(route('school.students.import'), ['file' => $file])->json();
        $this->assertSame(220, $start['total']);

        $chunks = 0;
        $prog = ['done' => false];
        while (! $prog['done']) {
            $prog = $this->post(route('school.students.import.chunk', $start['id']))->json();
            $chunks++;
        }

        $this->assertGreaterThanOrEqual(2, $chunks);
        $this->assertSame(220, $prog['created']);
        $this->assertSame(220, Student::where('school_id', $school->id)->count());
    }

    public function test_cannot_edit_other_schools_student(): void
    {
        $school = $this->makeSchool();
        $other = $this->makeSchool();
        $foreign = $this->student($other, 'Чужой', 7);

        $this->actingAs($this->operator($school))
            ->put(route('school.students.update', $foreign), [
                'fio' => 'Взлом', 'birth_date' => '2012-01-01', 'real_grade' => 7,
            ])
            ->assertForbidden();
    }

    public function test_mark_departed_and_restore(): void
    {
        $school = $this->makeSchool();
        $student = Student::create([
            'fio' => 'Выбывающий', 'birth_date' => '2012-01-01', 'school_id' => $school->id,
            'real_grade' => 7, 'class_letter' => 'А',
        ]);
        $operator = $this->operator($school);

        $this->actingAs($operator)
            ->post(route('school.students.depart', $student), [
                'transfer_settlement' => 'г. Казань', 'transfer_school' => 'Гимназия №2', 'departed_at' => '2026-06-01',
            ])
            ->assertSessionHas('success');

        $student->refresh();
        $this->assertSame('departed', $student->status);
        $this->assertSame('г. Казань', $student->transfer_settlement);
        $this->assertSame('Гимназия №2', $student->transfer_school);

        $this->actingAs($operator)->post(route('school.students.restore', $student))->assertSessionHas('success');
        $student->refresh();
        $this->assertSame('active', $student->status);
        $this->assertNull($student->transfer_school);
    }

    public function test_departed_student_excluded_from_active_template(): void
    {
        $school = $this->makeSchool();
        $this->student($school, 'Активный', 7);
        $departed = $this->student($school, 'Выбывший', 7);
        $departed->update(['status' => 'departed']);

        $year = \App\Models\AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $olympiad = \App\Models\Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Математика', 'stage' => 'school',
            'grades' => '1,2,3,4,5,6,7,8,9,10,11', 'date_held' => '2025-11-15', 'status' => 'grading',
        ]);

        $content = $this->actingAs($this->operator($school))
            ->get(route('school.olympiad.template', $olympiad))->streamedContent();
        $text = $this->xlsxText($content);

        $this->assertStringContainsString('Активный', $text);
        $this->assertStringNotContainsString('Выбывший', $text);
    }

    public function test_results_template_filename_includes_class(): void
    {
        $school = $this->makeSchool();
        $this->student($school, 'Ученик', 7);
        $year = \App\Models\AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $olympiad = \App\Models\Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'school',
            'grades' => '1,2,3,4,5,6,7,8,9,10,11', 'date_held' => '2025-11-15',
        ]);

        $disposition = $this->actingAs($this->operator($school))
            ->get(route('school.olympiad.template', ['olympiad' => $olympiad->id, 'grade' => 7, 'letter' => 'А']))
            ->assertOk()
            ->headers->get('Content-Disposition');

        // Класс (7-А) попадает в имя файла; кириллица percent-кодируется (А → %D0%90).
        $this->assertStringContainsString('7-', $disposition);
    }

    public function test_profile_columns_only_in_technology_template(): void
    {
        $school = $this->makeSchool();
        $this->student($school, 'Ученик', 7);
        $year = \App\Models\AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $operator = $this->operator($school);

        $physics = \App\Models\Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'school',
            'grades' => '1,2,3,4,5,6,7,8,9,10,11', 'date_held' => '2025-11-15',
        ]);
        $text = $this->xlsxText($this->actingAs($operator)
            ->get(route('school.olympiad.template', $physics))->streamedContent());
        $this->assertStringNotContainsString('Профиль', $text);
        $this->assertStringNotContainsString('Виды практик', $text);

        $tech = \App\Models\Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Труд (технология)', 'stage' => 'school',
            'grades' => '1,2,3,4,5,6,7,8,9,10,11', 'date_held' => '2025-11-15',
        ]);
        $techText = $this->xlsxText($this->actingAs($operator)
            ->get(route('school.olympiad.template', $tech))->streamedContent());
        $this->assertStringContainsString('Код вида практики', $techText);
    }

    /** Текст всех ячеек XLSX (для проверки содержимого шаблонов). */
    private function xlsxText(string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($tmp, $content);
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
        $text = '';
        foreach ($sheet->toArray() as $row) {
            $text .= implode(' ', array_map(fn ($c) => (string) $c, $row))."\n";
        }
        @unlink($tmp);

        return $text;
    }

    public function test_cannot_depart_other_schools_student(): void
    {
        $school = $this->makeSchool();
        $other = $this->makeSchool();
        $foreign = $this->student($other, 'Чужой', 7);

        $this->actingAs($this->operator($school))
            ->post(route('school.students.depart', $foreign), [
                'transfer_settlement' => 'X', 'transfer_school' => 'Y',
            ])
            ->assertForbidden();
    }

    public function test_non_operator_forbidden(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin)->get(route('school.students.index'))->assertForbidden();
    }
}
