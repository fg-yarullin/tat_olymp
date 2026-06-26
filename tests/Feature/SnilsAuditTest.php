<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Support\SnilsAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnilsAuditTest extends TestCase
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
            'oo_code' => 'OO'.(++$this->seq), 'short_name' => 'Школа '.$this->seq, 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);
    }

    private function student(School $school, string $fio, ?string $snils): Student
    {
        return Student::create([
            'fio' => $fio, 'birth_date' => '2012-01-01', 'school_id' => $school->id,
            'real_grade' => 7, 'snils' => $snils,
        ]);
    }

    public function test_suspicious_heuristics(): void
    {
        $this->assertTrue(SnilsAudit::isSuspicious('10000000001')); // мало разных цифр
        $this->assertTrue(SnilsAudit::isSuspicious('11111111111')); // все одинаковые
        $this->assertTrue(SnilsAudit::isSuspicious('12345678901')); // последовательность
        $this->assertTrue(SnilsAudit::isSuspicious('123'));         // не 11 цифр
        $this->assertFalse(SnilsAudit::isSuspicious('124-836-058 47')); // нормальный
        $this->assertFalse(SnilsAudit::isSuspicious(null));
    }

    public function test_same_snils_allowed_across_schools_not_within(): void
    {
        $a = $this->school();
        $b = $this->school();

        $this->student($a, 'Первый', '12483605847');
        // Та же СНИЛС в другой школе — допустимо
        $this->student($b, 'Второй', '12483605847');
        $this->assertSame(2, Student::count());

        // В той же школе — нарушение уникального индекса
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->student($a, 'Третий', '12483605847');
    }

    public function test_audit_lists_duplicates_and_suspicious(): void
    {
        $a = $this->school();
        $b = $this->school();
        $this->student($a, 'Дубль А', '12483605847');
        $this->student($b, 'Дубль Б', '12483605847'); // тот же СНИЛС в другой школе -> дубль
        $this->student($a, 'Выдуманный', '10000000001'); // подозрительный

        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin)->get(route('admin.snils.audit'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/SnilsAudit/Index')
                ->where('duplicates.0.snils', '12483605847')
                ->where('duplicates.0.students', fn ($s) => count($s) === 2)
                ->where('suspicious.0.snils', '10000000001'));
    }

    public function test_non_admin_forbidden(): void
    {
        $op = User::factory()->create(['role' => UserRole::SchoolOperator, 'is_active' => true]);

        $this->actingAs($op)->get(route('admin.snils.audit'))->assertForbidden();
    }
}
