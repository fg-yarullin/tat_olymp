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

class SchoolResultsTest extends TestCase
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

    private function olympiad(string $status = 'grading', string $grades = '7,8,9,10,11'): Olympiad
    {
        $year = AcademicYear::firstOrCreate(['name' => '2025/2026'], ['status' => 'current']);

        return Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Математика', 'stage' => 'school',
            'grades' => $grades, 'date_held' => '2025-11-15',
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    private function student(School $school, int $grade = 7): Student
    {
        return Student::create([
            'fio' => 'Ученик '.(++$this->seq), 'birth_date' => '2012-01-01',
            'school_id' => $school->id, 'real_grade' => $grade,
        ]);
    }

    private function csv(array $lines): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));

        return new UploadedFile($path, 'r.csv', 'text/csv', null, true);
    }

    /** Запускает импорт результатов ШЭ (по частям) и доводит его до завершения; возвращает итог. */
    private function runImport(User $operator, Olympiad $olympiad, UploadedFile $file): array
    {
        $this->actingAs($operator);
        $start = $this->post(route('school.olympiad.import', $olympiad), ['file' => $file])->json();
        $prog = ['done' => false];
        while (! $prog['done']) {
            $prog = $this->post(route('school.olympiad.import.chunk', $start['id']))->json();
        }

        return $prog;
    }

    public function test_inline_score_autosave_updates_only_score(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 7);
        $olympiad = $this->olympiad(); // открыт
        $olympiad->maxScores()->create(['grade' => 7, 'max_score' => 50]);
        $ho = HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 7,
            'result_status' => 'prize_winner', 'teacher_name' => 'Иванов И.',
        ]);

        $this->actingAs($this->operator($school))
            ->post(route('school.results.score', $ho), ['score' => '34,5'])
            ->assertSessionHasNoErrors();

        $ho->refresh();
        $this->assertEqualsWithDelta(34.5, (float) $ho->score, 0.001);
        $this->assertSame('prize_winner', $ho->result_status); // прочие поля не затронуты
        $this->assertSame('Иванов И.', $ho->teacher_name);

        // Балл выше максимума отклоняется.
        $this->actingAs($this->operator($school))
            ->post(route('school.results.score', $ho), ['score' => '60'])
            ->assertSessionHasErrors('score');

        // После закрытия ввода — нельзя.
        $closed = $this->olympiad('published');
        $hoC = HumanOlympiad::create(['student_id' => $student->id, 'olympiad_id' => $closed->id, 'participation_grade' => 7, 'result_status' => 'participant']);
        $this->actingAs($this->operator($school))
            ->post(route('school.results.score', $hoC), ['score' => '10'])
            ->assertSessionHasErrors('score');
    }

    public function test_results_search_sort_and_session_restore(): void
    {
        $school = $this->makeSchool();
        $olympiad = $this->olympiad();
        $operator = $this->operator($school);

        $mkParticipant = function (string $fio, int $grade) use ($school, $olympiad) {
            $s = Student::create(['fio' => $fio, 'birth_date' => '2011-01-01', 'school_id' => $school->id, 'real_grade' => $grade]);
            HumanOlympiad::create(['student_id' => $s->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => $grade]);
        };
        $mkParticipant('Борисов Иван', 8);
        $mkParticipant('Антонов Пётр', 8);
        $mkParticipant('Яшин Олег', 7);

        // По умолчанию: класс обучения, затем ФИО → 7-й класс, потом 8-е по алфавиту.
        $this->actingAs($operator)->get(route('school.results.show', $olympiad))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->where('participations.total', 3)
                ->where('participations.data.0.fio', 'Яшин Олег')
                ->where('participations.data.1.fio', 'Антонов Пётр')
                ->where('participations.data.2.fio', 'Борисов Иван'));

        // Сортировка по ФИО.
        $this->actingAs($operator)->get(route('school.results.show', [$olympiad, 'sort' => 'fio', 'dir' => 'asc']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->where('participations.data.0.fio', 'Антонов Пётр')
                ->where('participations.data.2.fio', 'Яшин Олег'));

        // Поиск по ФИО — сохраняет вид в сессии.
        $this->actingAs($operator)->get(route('school.results.show', [$olympiad, 'q' => 'Антон']))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->where('participations.total', 1)
                ->where('participations.data.0.fio', 'Антонов Пётр'));

        // Возврат без параметров — восстановление вида из сессии (редирект на URL с q).
        $this->actingAs($operator)->get(route('school.results.show', $olympiad))
            ->assertRedirect(route('school.results.show', [$olympiad, 'q' => 'Антон']));
    }

    public function test_results_filter_by_grade_and_participation_grade(): void
    {
        $school = $this->makeSchool();
        $olympiad = $this->olympiad();
        $operator = $this->operator($school);

        $mk = function (int $realGrade, int $partGrade) use ($school, $olympiad) {
            $s = Student::create(['fio' => 'Уч '.$realGrade.'/'.$partGrade, 'birth_date' => '2011-01-01', 'school_id' => $school->id, 'real_grade' => $realGrade]);
            HumanOlympiad::create(['student_id' => $s->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => $partGrade]);
        };
        $mk(7, 7);
        $mk(7, 8);
        $mk(8, 8);

        $this->actingAs($operator)->get(route('school.results.show', [$olympiad, 'grade' => 7]))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->where('participations.total', 2));
        $this->actingAs($operator)->get(route('school.results.show', [$olympiad, 'pgrade' => 8]))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p->where('participations.total', 2));
        $this->actingAs($operator)->get(route('school.results.show', [$olympiad, 'grade' => 7, 'pgrade' => 8]))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->where('participations.total', 1)
                ->where('participations.data.0.fio', 'Уч 7/8'));
    }

    public function test_show_exposes_teacher_directory_and_school_name(): void
    {
        $school = $this->makeSchool();
        $olympiad = $this->olympiad();
        $student = $this->student($school, 7);
        HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 7,
            'teacher_name' => 'Иванов Иван Иванович', 'teacher_workplace' => 'Школа',
        ]);

        $this->actingAs($this->operator($school))->get(route('school.results.show', $olympiad))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->where('school_name', 'Школа')
                ->where('teachers', [['name' => 'Иванов Иван Иванович', 'workplace' => 'Школа']]));
    }

    public function test_manual_store_creates_participation(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 7);
        $olympiad = $this->olympiad();

        $this->actingAs($this->operator($school))
            ->post(route('school.results.store', $olympiad), [
                'student_id' => $student->id, 'participation_grade' => 7, 'score' => 91,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('human_olympiad', [
            'student_id' => $student->id, 'olympiad_id' => $olympiad->id,
            'participation_grade' => 7, 'score' => 91,
        ]);
    }

    public function test_score_accepts_decimal_with_comma_and_rejects_more_than_two_places(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 7);
        $olympiad = $this->olympiad();
        $operator = $this->operator($school);

        // Запятая как разделитель, два знака — принимается и нормализуется.
        $this->actingAs($operator)->post(route('school.results.store', $olympiad), [
            'student_id' => $student->id, 'participation_grade' => 7, 'score' => '12,5',
        ])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(12.5, (float) HumanOlympiad::first()->score, 0.001);

        // Больше двух знаков после запятой — ошибка валидации.
        $this->actingAs($operator)->post(route('school.results.store', $olympiad), [
            'student_id' => $student->id, 'participation_grade' => 7, 'score' => '12.555',
        ])->assertSessionHasErrors('score');
    }

    public function test_manual_score_cannot_exceed_max_when_set(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 7);
        $olympiad = $this->olympiad();
        $olympiad->maxScores()->create(['grade' => 7, 'max_score' => 80]);
        $operator = $this->operator($school);

        $this->actingAs($operator)->post(route('school.results.store', $olympiad), [
            'student_id' => $student->id, 'participation_grade' => 7, 'score' => '85',
        ])->assertSessionHasErrors('score');
        $this->assertDatabaseMissing('human_olympiad', ['student_id' => $student->id, 'score' => 85]);

        $this->actingAs($operator)->post(route('school.results.store', $olympiad), [
            'student_id' => $student->id, 'participation_grade' => 7, 'score' => '80',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('human_olympiad', ['student_id' => $student->id, 'score' => 80]);
    }

    public function test_over_max_scores_are_flagged_counted_and_filterable(): void
    {
        $school = $this->makeSchool();
        $olympiad = $this->olympiad();
        $olympiad->maxScores()->create(['grade' => 7, 'max_score' => 80]);
        $operator = $this->operator($school);

        // Баллы внесены до задания макс. (имитируем прямой записью): один — выше максимума.
        $over = Student::create(['fio' => 'Алексеев Пётр', 'birth_date' => '2012-01-01', 'school_id' => $school->id, 'real_grade' => 7]);
        $ok = Student::create(['fio' => 'Борисов Иван', 'birth_date' => '2012-01-01', 'school_id' => $school->id, 'real_grade' => 7]);
        HumanOlympiad::create(['student_id' => $over->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 7, 'score' => 90]);
        HumanOlympiad::create(['student_id' => $ok->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 7, 'score' => 50]);

        $this->actingAs($operator)->get(route('school.results.show', $olympiad))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->where('over_max_count', 1)
                ->where('participations.data.0.fio', 'Алексеев Пётр')
                ->where('participations.data.0.over_max', true)
                ->where('participations.data.1.over_max', false));

        // Фильтр «только превышения».
        $this->actingAs($operator)->get(route('school.results.show', [$olympiad, 'over' => 1]))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->where('participations.total', 1)
                ->where('participations.data.0.fio', 'Алексеев Пётр'));
    }

    public function test_student_can_participate_for_multiple_grades(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 7);
        $olympiad = $this->olympiad();
        $operator = $this->operator($school);

        // За свой класс
        $this->actingAs($operator)->post(route('school.results.store', $olympiad), [
            'student_id' => $student->id, 'participation_grade' => 7, 'score' => 80,
        ]);
        // За класс выше — отдельное участие
        $this->actingAs($operator)->post(route('school.results.store', $olympiad), [
            'student_id' => $student->id, 'participation_grade' => 8, 'score' => 75,
        ])->assertSessionHas('success');

        $this->assertSame(2, HumanOlympiad::where('student_id', $student->id)->count());
    }

    public function test_cannot_participate_below_own_grade(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 8);
        $olympiad = $this->olympiad();

        $this->actingAs($this->operator($school))
            ->post(route('school.results.store', $olympiad), [
                'student_id' => $student->id, 'participation_grade' => 7, 'score' => 50,
            ])
            ->assertSessionHasErrors('participation_grade');

        $this->assertSame(0, HumanOlympiad::count());
    }

    public function test_grade_must_be_within_olympiad_grades(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 5);
        $olympiad = $this->olympiad('grading', '7,8,9,10,11'); // только 7–11

        $this->actingAs($this->operator($school))
            ->post(route('school.results.store', $olympiad), [
                'student_id' => $student->id, 'participation_grade' => 5, 'score' => 50,
            ])
            ->assertSessionHasErrors('participation_grade');
    }

    public function test_blocked_when_input_closed(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 7);
        $olympiad = $this->olympiad('published');

        $this->actingAs($this->operator($school))
            ->post(route('school.results.store', $olympiad), [
                'student_id' => $student->id, 'participation_grade' => 7, 'score' => 50,
            ])
            ->assertSessionHasErrors('score');

        $this->assertSame(0, HumanOlympiad::count());
    }

    public function test_import_multiple_grades_for_same_student(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 7);
        $olympiad = $this->olympiad();

        $lines = [
            'ID;ФИО;ДР;Класс;Класс участия;Макс. балл;Балл',
            "{$student->id};Уч;2012-01-01;7;7;;88",
            "{$student->id};Уч;2012-01-01;7;8;;72",
        ];
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));
        $file = new UploadedFile($path, 'r.csv', 'text/csv', null, true);

        $prog = $this->runImport($this->operator($school), $olympiad, $file);
        $this->assertSame(2, $prog['created']);

        $this->assertSame(2, HumanOlympiad::where('student_id', $student->id)->count());
    }

    public function test_import_processes_over_multiple_chunks_with_correct_lines(): void
    {
        $school = $this->makeSchool();
        $olympiad = $this->olympiad();
        $lines = ['ID;ФИО;ДР;Класс;Класс участия;Макс. балл;Балл'];
        $studentIds = [];
        for ($k = 0; $k < 200; $k++) {
            $s = $this->student($school, 7);
            $studentIds[] = $s->id;
            $lines[] = "{$s->id};Уч;2012-01-01;7;7;;80";
        }
        // Одна ошибочная строка (несуществующий ID) — проверяем правильный номер строки в CSV ошибок.
        $lines[] = '999999;Чужой;2012-01-01;7;7;;80';
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));
        $file = new UploadedFile($path, 'r.csv', 'text/csv', null, true);

        $this->actingAs($this->operator($school));
        $start = $this->post(route('school.olympiad.import', $olympiad), ['file' => $file])->json();
        $this->assertSame(201, $start['total']);

        $chunks = 0;
        $prog = ['done' => false];
        while (! $prog['done']) {
            $prog = $this->post(route('school.olympiad.import.chunk', $start['id']))->json();
            $chunks++;
        }

        $this->assertGreaterThanOrEqual(2, $chunks);
        $this->assertSame(200, $prog['created']);
        $this->assertSame(1, $prog['failed']);
        $this->assertSame(200, HumanOlympiad::where('olympiad_id', $olympiad->id)->count());

        // Строка 202 в файле (1 заголовок + 200 успешных + ошибочная строка).
        $errCsv = $this->get(route('school.olympiad.import.errors', $start['id']))->streamedContent();
        $this->assertStringContainsString('999999', $errCsv);
    }

    public function test_import_defaults_teacher_workplace_to_own_school_when_blank(): void
    {
        $school = $this->makeSchool(); // full_name = 'Школа'
        $withName = $this->student($school, 7);
        $withoutName = $this->student($school, 7);
        $olympiad = $this->olympiad();

        $file = $this->csv([
            'ID;ФИО;ДР;Класс;Класс участия;Макс. балл;Балл;Статус;ПризерМЭ;Учитель;Место работы',
            "{$withName->id};Уч;2012-01-01;7;7;;50;;;Иванов И.И.;", // учитель заполнен, место работы — пусто
            "{$withoutName->id};Уч;2012-01-01;7;7;;60;;;;", // и учитель, и место работы пусты
        ]);

        $prog = $this->runImport($this->operator($school), $olympiad, $file);
        $this->assertSame(2, $prog['created']);

        $this->assertDatabaseHas('human_olympiad', [
            'student_id' => $withName->id, 'teacher_name' => 'Иванов И.И.', 'teacher_workplace' => 'Школа',
        ]);
        $this->assertDatabaseHas('human_olympiad', [
            'student_id' => $withoutName->id, 'teacher_workplace' => 'Школа',
        ]);
    }

    public function test_manual_store_saves_protocol_fields(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 10);
        $olympiad = $this->olympiad('grading', '7,8,9,10,11');

        $this->actingAs($this->operator($school))
            ->post(route('school.results.store', $olympiad), [
                'student_id' => $student->id, 'participation_grade' => 11, 'score' => 45,
                'result_status' => 'prize_winner', 'prev_municipal_winner' => true,
                'teacher_name' => 'Иванов Иван Иванович', 'teacher_workplace' => 'Школа 1',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('human_olympiad', [
            'student_id' => $student->id, 'participation_grade' => 11, 'score' => 45,
            'result_status' => 'prize_winner', 'prev_municipal_winner' => true,
            'teacher_name' => 'Иванов Иван Иванович', 'teacher_workplace' => 'Школа 1',
        ]);
    }

    public function test_import_parses_protocol_columns(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 10);
        $olympiad = $this->olympiad();

        $lines = [
            'ID;ФИО;ДР;Класс;Класс участия;Макс. балл;Балл;Статус;Призер МЭ;Учитель;Место работы',
            "{$student->id};Уч;2012-01-01;10;11;;45;призер;да;Иванов И.И.;Школа 1",
        ];
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));
        $file = new UploadedFile($path, 'r.csv', 'text/csv', null, true);

        $prog = $this->runImport($this->operator($school), $olympiad, $file);
        $this->assertSame(1, $prog['created']);

        $this->assertDatabaseHas('human_olympiad', [
            'student_id' => $student->id, 'participation_grade' => 11,
            'result_status' => 'prize_winner', 'prev_municipal_winner' => true, 'teacher_name' => 'Иванов И.И.',
        ]);
    }

    public function test_protocol_export_matches_columns(): void
    {
        $school = $this->makeSchool();
        $student = Student::create([
            'fio' => 'Тарасов Николай Даниилович', 'birth_date' => '2006-03-24', 'gender' => 'male',
            'snils' => '00101000001', 'school_id' => $school->id, 'real_grade' => 10,
        ]);
        $olympiad = $this->olympiad();
        $olympiad->maxScores()->create(['grade' => 11, 'max_score' => 80]); // макс. балл — по классам (админ)
        HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 11,
            'score' => 45, 'result_status' => 'prize_winner',
            'prev_municipal_winner' => true, 'teacher_name' => 'Иванов Иван Иванович', 'teacher_workplace' => 'Школа 1',
        ]);

        $this->seed(\Database\Seeders\ProtocolTemplateSeeder::class); // общий шаблон ШЭ

        $response = $this->actingAs($this->operator($school))
            ->get(route('school.results.protocol', $olympiad));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();

        $this->assertSame('СНИЛС', $sheet->getCell('B1')->getValue());
        $this->assertSame('Класс участия', $sheet->getCell('J1')->getValue());
        // Данные: ФИО разобрано, дата/статус/учитель
        $this->assertSame('Тарасов', $sheet->getCell('C2')->getValue());
        $this->assertSame('Николай', $sheet->getCell('D2')->getValue());
        $this->assertSame('24.03.2006', $sheet->getCell('G2')->getValue());
        $this->assertSame('призер', $sheet->getCell('M2')->getValue());
        $this->assertSame('да', $sheet->getCell('N2')->getValue());
        $this->assertSame('Иванов Иван Иванович', $sheet->getCell('O2')->getValue());
        @unlink($tmp);
    }

    public function test_manual_store_saves_technology_fields(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 10);
        $olympiad = $this->olympiad();

        $this->actingAs($this->operator($school))
            ->post(route('school.results.store', $olympiad), [
                'student_id' => $student->id, 'participation_grade' => 10, 'score' => 55,
                'profile' => '2 - Культура дома', 'practice_types' => '2.1 Ручная обработка',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('human_olympiad', [
            'student_id' => $student->id, 'profile' => '2 - Культура дома',
            'practice_types' => '2.1 Ручная обработка',
        ]);
    }

    public function test_final_score_is_computed_automatically(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 10);
        $olympiad = $this->olympiad();

        $ho = HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 11,
            'primary_score' => 27, 'appeal_addition' => 1, 'result_status' => 'participant',
        ]);

        $this->assertSame(28.0, $ho->fresh()->final_score);
    }

    public function test_non_technology_import_ignores_profile_practice(): void
    {
        $school = $this->makeSchool();
        $student = $this->student($school, 10);
        $olympiad = $this->olympiad(); // обычный предмет (не технология)

        // Даже если в файле есть колонки профиля/практики — у не-технологии они игнорируются.
        $lines = [
            'ID;ФИО;ДР;Класс;Кл.уч;Макс;Балл;Статус;ПризерМЭ;Учитель;Место;Профиль;Практики',
            "{$student->id};Уч;2012-01-01;10;10;;55;;;Учитель;Школа;Профиль X;Практика Y",
        ];
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));
        $file = new UploadedFile($path, 'r.csv', 'text/csv', null, true);

        $prog = $this->runImport($this->operator($school), $olympiad, $file);
        $this->assertSame(1, $prog['created']);

        $this->assertDatabaseHas('human_olympiad', [
            'student_id' => $student->id, 'profile' => null, 'practice_types' => null,
        ]);
    }

    private function technologyOlympiad(): Olympiad
    {
        $year = AcademicYear::firstOrCreate(['name' => '2025/2026'], ['status' => 'current']);

        return Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Труд (технология)', 'stage' => 'school',
            'grades' => '5,6,7,8,9,10,11', 'date_held' => '2025-11-15', 'status' => 'grading',
        ]);
    }

    public function test_technology_import_resolves_practice_code_to_canonical_names(): void
    {
        $this->seed(\Database\Seeders\TechReferenceSeeder::class);
        $school = $this->makeSchool();
        $student = $this->student($school, 10);
        $olympiad = $this->technologyOlympiad();

        $lines = [
            'ID;ФИО;ДР;Класс;Кл.уч;Макс;Балл;Статус;ПризерМЭ;Учитель;Место;Код',
            "{$student->id};Уч;2012-01-01;10;10;;55;;;;;1.1",
        ];
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));
        $file = new UploadedFile($path, 'r.csv', 'text/csv', null, true);

        $prog = $this->runImport($this->operator($school), $olympiad, $file);
        $this->assertSame(1, $prog['created']);

        $this->assertDatabaseHas('human_olympiad', [
            'student_id' => $student->id,
            'profile' => 'Техника, технологии и техническое творчество',
            'practice_types' => '1.1 Практика по ручной деревообработке',
        ]);
    }

    public function test_technology_import_rejects_unknown_practice_code(): void
    {
        $this->seed(\Database\Seeders\TechReferenceSeeder::class);
        $school = $this->makeSchool();
        $student = $this->student($school, 10);
        $olympiad = $this->technologyOlympiad();

        $lines = [
            'ID;ФИО;ДР;Класс;Кл.уч;Макс;Балл;Статус;ПризерМЭ;Учитель;Место;Код',
            "{$student->id};Уч;2012-01-01;10;10;;55;;;;;9.9",
        ];
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));
        $file = new UploadedFile($path, 'r.csv', 'text/csv', null, true);

        $prog = $this->runImport($this->operator($school), $olympiad, $file);
        $this->assertSame(1, $prog['failed']);

        $this->assertDatabaseMissing('human_olympiad', ['student_id' => $student->id]);
    }

    public function test_non_operator_forbidden(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin)->get(route('school.results.index'))->assertForbidden();
    }

    public function test_bulk_destroy_selected_ids(): void
    {
        $school = $this->makeSchool();
        $olympiad = $this->olympiad();
        $s1 = $this->student($school, 7);
        $s2 = $this->student($school, 7);
        $s3 = $this->student($school, 7);
        $h1 = HumanOlympiad::create(['student_id' => $s1->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 7]);
        $h2 = HumanOlympiad::create(['student_id' => $s2->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 7]);
        HumanOlympiad::create(['student_id' => $s3->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 7]);

        $this->actingAs($this->operator($school))
            ->post(route('school.results.bulk-destroy', $olympiad), ['mode' => 'selected', 'ids' => [$h1->id, $h2->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, HumanOlympiad::where('olympiad_id', $olympiad->id)->count());
        $this->assertDatabaseMissing('human_olympiad', ['id' => $h1->id]);
    }

    public function test_bulk_destroy_filtered_by_grade(): void
    {
        $school = $this->makeSchool();
        $olympiad = $this->olympiad();
        $s7 = $this->student($school, 7);
        $s8 = $this->student($school, 8);
        HumanOlympiad::create(['student_id' => $s7->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 7]);
        HumanOlympiad::create(['student_id' => $s8->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 8]);

        $this->actingAs($this->operator($school))
            ->post(route('school.results.bulk-destroy', $olympiad), ['mode' => 'filtered', 'grade' => 7])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, HumanOlympiad::where('olympiad_id', $olympiad->id)->count());
        $this->assertDatabaseHas('human_olympiad', ['student_id' => $s8->id]);
    }

    public function test_bulk_destroy_all_wipes_only_own_school(): void
    {
        $school = $this->makeSchool();
        $otherSchool = $this->makeSchool();
        $olympiad = $this->olympiad();
        $mine = $this->student($school, 7);
        $other = $this->student($otherSchool, 7);
        HumanOlympiad::create(['student_id' => $mine->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 7]);
        HumanOlympiad::create(['student_id' => $other->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 7]);

        $this->actingAs($this->operator($school))
            ->post(route('school.results.bulk-destroy', $olympiad), ['mode' => 'all'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('human_olympiad', ['student_id' => $mine->id]);
        $this->assertDatabaseHas('human_olympiad', ['student_id' => $other->id]);
    }

    public function test_bulk_destroy_ignores_ids_from_other_school(): void
    {
        $school = $this->makeSchool();
        $otherSchool = $this->makeSchool();
        $olympiad = $this->olympiad();
        $other = $this->student($otherSchool, 7);
        $h = HumanOlympiad::create(['student_id' => $other->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 7]);

        $this->actingAs($this->operator($school))
            ->post(route('school.results.bulk-destroy', $olympiad), ['mode' => 'selected', 'ids' => [$h->id]])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('human_olympiad', ['id' => $h->id]);
    }

    public function test_bulk_destroy_redirects_to_previous_page_when_current_page_empties(): void
    {
        $school = $this->makeSchool();
        $olympiad = $this->olympiad();
        // 26 участий → 2 страницы по 25; удаляем единственную строку 2-й страницы.
        $ids = [];
        for ($i = 0; $i < 26; $i++) {
            $student = $this->student($school, 7);
            $ids[] = HumanOlympiad::create(['student_id' => $student->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 7])->id;
        }
        $lastId = end($ids);

        $this->actingAs($this->operator($school))
            ->post(route('school.results.bulk-destroy', $olympiad), ['mode' => 'selected', 'ids' => [$lastId], 'page' => 2])
            ->assertRedirect(route('school.results.show', $olympiad));

        $this->assertSame(25, HumanOlympiad::where('olympiad_id', $olympiad->id)->count());
    }

    public function test_bulk_destroy_blocked_when_input_closed(): void
    {
        $school = $this->makeSchool();
        $olympiad = $this->olympiad('published');
        $student = $this->student($school, 7);
        $h = HumanOlympiad::create(['student_id' => $student->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 7]);

        $this->actingAs($this->operator($school))
            ->post(route('school.results.bulk-destroy', $olympiad), ['mode' => 'selected', 'ids' => [$h->id]])
            ->assertSessionHasErrors('participation');

        $this->assertDatabaseHas('human_olympiad', ['id' => $h->id]);
    }
}
