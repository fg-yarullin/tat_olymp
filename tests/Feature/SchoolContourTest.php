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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchoolContourTest extends TestCase
{
    use RefreshDatabase;

    private int $schoolSeq = 0;

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
            'oo_code' => '1000'.(++$this->schoolSeq), 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);
    }

    private function makeOperator(School $school): User
    {
        return User::factory()->create([
            'role' => UserRole::SchoolOperator,
            'school_id' => $school->id,
            'is_active' => true,
        ]);
    }

    private function makeOlympiad(string $status = 'published'): Olympiad
    {
        $year = AcademicYear::firstOrCreate(['name' => '2025/2026'], ['status' => 'current']);

        return Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Математика', 'stage' => 'school',
            'date_held' => '2025-11-15',
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    private function makeStudent(School $school, string $fio, int $grade = 7): Student
    {
        return Student::create([
            'fio' => $fio, 'birth_date' => '2012-03-01',
            'school_id' => $school->id, 'real_grade' => $grade,
        ]);
    }

    private function uploadCsv(array $dataRows): UploadedFile
    {
        $lines = ['ID;ФИО;Дата рождения;Класс;Класс участия;Макс. балл;Балл'];
        foreach ($dataRows as $row) {
            array_splice($row, 5, 0, ''); // справочная колонка «Макс. балл» перед баллом
            $lines[] = implode(';', $row);
        }
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));

        return new UploadedFile($path, 'results.csv', 'text/csv', null, true);
    }

    private function makeWork(Olympiad $olympiad, School $school, ?string $scanPath = null): HumanOlympiad
    {
        $student = Student::create([
            'fio' => 'Петров Иван Сергеевич', 'birth_date' => '2012-03-01',
            'school_id' => $school->id, 'real_grade' => 7,
        ]);

        if ($scanPath) {
            Storage::put($scanPath, 'PDF-CONTENT');
        }

        return HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $olympiad->id,
            'participation_grade' => 7, 'barcode' => 'BC'.$student->id,
            'score' => 80, 'result_status' => 'participant', 'scan_path' => $scanPath,
        ]);
    }

    public function test_non_operator_is_forbidden(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin)->get(route('school.results.index'))->assertForbidden();
    }

    public function test_operator_downloads_zip_of_own_school_scans(): void
    {
        Storage::fake();
        $school = $this->makeSchool();
        $operator = $this->makeOperator($school);
        $olympiad = $this->makeOlympiad();
        $work = $this->makeWork($olympiad, $school, 'scans/own.pdf');

        $this->actingAs($operator)
            ->post(route('school.olympiad.zip', $olympiad), ['student_ids' => [$work->student_id]])
            ->assertOk()
            ->assertDownload();
    }

    public function test_operator_cannot_download_other_schools_scans(): void
    {
        Storage::fake();
        $schoolA = $this->makeSchool();
        $schoolB = $this->makeSchool();
        $operatorA = $this->makeOperator($schoolA);
        $olympiad = $this->makeOlympiad();
        // Работа принадлежит ЧУЖОЙ школе B
        $foreignWork = $this->makeWork($olympiad, $schoolB, 'scans/foreign.pdf');

        $this->actingAs($operatorA)
            ->post(route('school.olympiad.zip', $olympiad), ['student_ids' => [$foreignWork->student_id]])
            ->assertSessionHasErrors('student_ids');
    }

    public function test_template_download_lists_school_students(): void
    {
        $school = $this->makeSchool();
        $operator = $this->makeOperator($school);
        $this->makeStudent($school, 'Иванов Иван Иванович'); // 7 класс
        $olympiad = $this->makeOlympiad('grading');
        $olympiad->maxScores()->create(['grade' => 7, 'max_score' => 42]);

        $response = $this->actingAs($operator)
            ->get(route('school.olympiad.template', $olympiad));

        $response->assertOk();
        // Шаблон теперь XLSX — проверяем содержимое через разбор таблицы.
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $text = collect(\PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet()->toArray())
            ->flatten()->implode(' ');
        @unlink($tmp);
        $this->assertStringContainsString('Иванов Иван Иванович', $text);
        // Колонка «Макс. балл» с подставленным значением для класса участия.
        $this->assertStringContainsString('Макс. балл', $text);
        $this->assertStringContainsString('42', $text);
    }

    /** Запускает импорт результатов ШЭ (по частям) и доводит его до завершения; возвращает итог. */
    private function runResultsImport($operator, $olympiad, $csv): array
    {
        $this->actingAs($operator);
        $start = $this->post(route('school.olympiad.import', $olympiad), ['file' => $csv])->json();
        $prog = ['done' => false];
        while (! $prog['done']) {
            $prog = $this->post(route('school.olympiad.import.chunk', $start['id']))->json();
        }

        return $prog;
    }

    public function test_import_creates_human_olympiad_only_for_scored_rows(): void
    {
        $school = $this->makeSchool();
        $operator = $this->makeOperator($school);
        $scored = $this->makeStudent($school, 'Со баллом');
        $blank = $this->makeStudent($school, 'Без балла');
        $olympiad = $this->makeOlympiad('grading');

        $csv = $this->uploadCsv([
            [$scored->id, 'Со баллом', '2012-03-01', 7, 7, '95'],
            [$blank->id, 'Без балла', '2012-03-01', 7, 7, ''], // балл пуст -> пропуск
        ]);

        $prog = $this->runResultsImport($operator, $olympiad, $csv);
        $this->assertSame(1, $prog['created']);
        $this->assertSame(1, $prog['skipped']);

        $this->assertDatabaseHas('human_olympiad', [
            'student_id' => $scored->id, 'olympiad_id' => $olympiad->id, 'score' => 95,
        ]);
        $this->assertDatabaseMissing('human_olympiad', [
            'student_id' => $blank->id, 'olympiad_id' => $olympiad->id,
        ]);
    }

    public function test_import_rejects_foreign_student_ids(): void
    {
        $schoolA = $this->makeSchool();
        $schoolB = $this->makeSchool();
        $operatorA = $this->makeOperator($schoolA);
        $foreign = $this->makeStudent($schoolB, 'Чужой Ученик');
        $olympiad = $this->makeOlympiad('grading');

        $csv = $this->uploadCsv([[$foreign->id, 'Чужой Ученик', '2012-03-01', 7, 7, '90']]);

        $prog = $this->runResultsImport($operatorA, $olympiad, $csv);
        $this->assertSame(1, $prog['failed']);

        $this->assertDatabaseMissing('human_olympiad', ['student_id' => $foreign->id]);
    }

    public function test_import_rejects_score_above_max(): void
    {
        $school = $this->makeSchool();
        $operator = $this->makeOperator($school);
        $student = $this->makeStudent($school, 'Высокий Балл');
        $olympiad = $this->makeOlympiad('grading');
        $olympiad->maxScores()->create(['grade' => 7, 'max_score' => 80]);

        $csv = $this->uploadCsv([[$student->id, 'Высокий Балл', '2012-03-01', 7, 7, '95']]);

        $prog = $this->runResultsImport($operator, $olympiad, $csv);
        $this->assertSame(1, $prog['failed']);

        $this->assertDatabaseMissing('human_olympiad', ['student_id' => $student->id]);
    }

    public function test_import_blocked_when_input_closed(): void
    {
        $school = $this->makeSchool();
        $operator = $this->makeOperator($school);
        $student = $this->makeStudent($school, 'Поздний Балл');
        $olympiad = $this->makeOlympiad('published'); // ввод закрыт

        $csv = $this->uploadCsv([[$student->id, 'Поздний Балл', '2012-03-01', 7, 7, '90']]);

        // Закрытый ввод — импорт даже не стартует (422 с ошибкой по файлу).
        $this->actingAs($operator)
            ->post(route('school.olympiad.import', $olympiad), ['file' => $csv])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseMissing('human_olympiad', ['student_id' => $student->id]);
    }
}
