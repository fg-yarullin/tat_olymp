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
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class RatingsExportTest extends TestCase
{
    use RefreshDatabase;

    private Ate $ate;
    private Msu $msu;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ate = Ate::create(['ate_code' => '50', 'name' => 'Тестовая АТЕ', 'type' => 'isolated']);
        $this->msu = Msu::create(['msu_code' => '50', 'name' => 'МСУ', 'ate_id' => $this->ate->id]);
    }

    private function makeSchool(string $name, string $territory = 'city'): School
    {
        return School::create([
            'oo_code' => '500'.(++$this->seq), 'short_name' => $name, 'full_name' => $name,
            'education_level' => 3, 'territorial_sign' => $territory,
            'msu_id' => $this->msu->id, 'msu_code' => '50', 'ate_id' => $this->ate->id, 'ate_code' => '50',
        ]);
    }

    private function makeWork(School $school, Olympiad $olympiad, string $status): void
    {
        $student = Student::create([
            'fio' => 'Уч '.(++$this->seq), 'birth_date' => '2011-03-01',
            'school_id' => $school->id, 'real_grade' => 9,
        ]);
        HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $olympiad->id,
            'participation_grade' => 9, 'score' => 90, 'result_status' => $status,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    public function test_export_returns_valid_xlsx_with_ranked_rows(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Математика', 'stage' => 'municipal',
            'date_held' => '2025-12-15', 'published_at' => now(),
        ]);
        $top = $this->makeSchool('Лидер');
        $second = $this->makeSchool('Второй');
        $this->makeWork($top, $olympiad, 'winner');
        $this->makeWork($top, $olympiad, 'winner');
        $this->makeWork($second, $olympiad, 'winner');

        $response = $this->actingAs($this->admin())
            ->get(route('analytics.ratings.xlsx', ['year' => '2025/2026', 'territory' => 'all']));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Считываем поток обратно и проверяем содержимое
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();

        // Заголовок + шапка таблицы
        $this->assertStringContainsString('Итоговый протокол', $sheet->getCell('A1')->getValue());
        $this->assertSame('Место', $sheet->getCell('A3')->getValue());

        // Ранжирование: «Лидер» (2 победителя) на 1 месте
        $this->assertSame(1, (int) $sheet->getCell('A4')->getValue());
        $this->assertSame('Лидер', $sheet->getCell('B4')->getValue());
        $this->assertSame('Второй', $sheet->getCell('B5')->getValue());

        @unlink($tmp);
    }

    public function test_territory_slice_filters_rural_only(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Математика', 'stage' => 'municipal',
            'date_held' => '2025-12-15', 'published_at' => now(),
        ]);
        $this->makeWork($this->makeSchool('Городская', 'city'), $olympiad, 'winner');
        $this->makeWork($this->makeSchool('Сельская', 'rural'), $olympiad, 'winner');

        $response = $this->actingAs($this->admin())
            ->get(route('analytics.ratings.xlsx', ['year' => '2025/2026', 'territory' => 'rural']));

        $response->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();

        $this->assertSame('Сельская', $sheet->getCell('B4')->getValue());
        $this->assertNull($sheet->getCell('B5')->getValue()); // только одна строка
        @unlink($tmp);
    }

    public function test_school_operator_is_forbidden(): void
    {
        $operator = User::factory()->create(['role' => UserRole::SchoolOperator, 'is_active' => true]);

        $this->actingAs($operator)
            ->get(route('analytics.ratings.xlsx'))
            ->assertForbidden();
    }
}
