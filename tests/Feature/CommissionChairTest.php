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

class CommissionChairTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function ateSchool(string $code = '01'): array
    {
        $ate = Ate::firstOrCreate(['ate_code' => $code], ['name' => "АТЕ {$code}", 'type' => 'isolated']);
        $msu = Msu::firstOrCreate(['msu_code' => $code], ['name' => 'МСУ', 'ate_id' => $ate->id]);
        $school = School::create([
            'oo_code' => 'OO'.(++$this->seq), 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => $code, 'ate_id' => $ate->id, 'ate_code' => $code,
        ]);

        return [$ate, $school];
    }

    private function municipal(AcademicYear $year): Olympiad
    {
        $o = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Астрономия', 'stage' => 'municipal', 'grades' => '9',
            'date_held' => '2025-12-01', 'status' => 'grading',
        ]);
        $o->maxScores()->create(['grade' => 9, 'max_score' => 50]);

        return $o;
    }

    private function participant(School $school, Olympiad $o, ?string $cipher = null): HumanOlympiad
    {
        $s = Student::create(['fio' => 'Уч '.(++$this->seq), 'birth_date' => '2010-01-01', 'school_id' => $school->id, 'real_grade' => 9]);

        return HumanOlympiad::create([
            'student_id' => $s->id, 'olympiad_id' => $o->id, 'participation_grade' => 9,
            'result_status' => 'participant', 'barcode' => $cipher,
        ]);
    }

    public function test_admin_cannot_create_commission_chair(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Председатель', 'email' => 'chair@example.com', 'password' => 'password123',
            'role' => 'commission_chair', 'is_active' => true,
        ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'chair@example.com']);
    }

    public function test_coordinator_creates_chair_bound_to_olympiad(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ateA] = $this->ateSchool('01');
        [$ateB] = $this->ateSchool('02');
        $olympiad = $this->municipal($year);
        $coordinatorA = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ateA->id, 'is_active' => true]);
        $foreignChair = User::factory()->create(['role' => UserRole::CommissionChair, 'ate_id' => $ateB->id, 'is_active' => true]);

        // Создаёт председателя с выбором олимпиад(ы) — АТЕ авто, привязка через мультивыбор.
        $this->actingAs($coordinatorA)->post(route('municipal.chairs.store'), [
            'name' => 'Пред А', 'email' => 'preda@example.com', 'password' => 'password123', 'is_active' => true,
            'olympiad_ids' => [$olympiad->id],
        ])->assertSessionHas('success');
        $chair = User::where('email', 'preda@example.com')->first();
        $this->assertNotNull($chair);
        $this->assertSame($ateA->id, $chair->ate_id);
        $this->assertSame('commission_chair', $chair->role->value);
        $this->assertTrue($olympiad->commissionChairs()->whereKey($chair->id)->exists());

        // Не может управлять председателем чужого АТЕ.
        $this->actingAs($coordinatorA)->put(route('municipal.chairs.update', $foreignChair), [
            'name' => 'X', 'email' => 'x@example.com', 'is_active' => true,
        ])->assertForbidden();
        $this->actingAs($coordinatorA)->delete(route('municipal.chairs.destroy', $foreignChair))->assertForbidden();
    }

    public function test_chair_can_be_assigned_to_multiple_olympiads_and_resynced(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ateA] = $this->ateSchool('01');
        $ol1 = $this->municipal($year);
        $ol2 = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal',
            'grades' => '9', 'date_held' => '2025-12-02', 'status' => 'grading']);
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ateA->id, 'is_active' => true]);

        // Один председатель на две олимпиады.
        $this->actingAs($coordinator)->post(route('municipal.chairs.store'), [
            'name' => 'Пред', 'email' => 'pred@example.com', 'password' => 'password123', 'is_active' => true,
            'olympiad_ids' => [$ol1->id, $ol2->id],
        ])->assertSessionHas('success');

        $chair = User::where('email', 'pred@example.com')->first();
        $this->assertEqualsCanonicalizing([$ol1->id, $ol2->id], $chair->chairedOlympiads()->pluck('olympiads.id')->all());

        // Редактирование пересинхронизирует набор — оставляем только вторую.
        $this->actingAs($coordinator)->put(route('municipal.chairs.update', $chair), [
            'name' => 'Пред', 'email' => 'pred@example.com', 'is_active' => true, 'olympiad_ids' => [$ol2->id],
        ])->assertSessionHas('success');
        $this->assertSame([$ol2->id], $chair->fresh()->chairedOlympiads()->pluck('olympiads.id')->all());
    }

    public function test_coordinator_assigns_unique_cipher(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal($year);
        $p1 = $this->participant($school, $olympiad);
        $p2 = $this->participant($school, $olympiad, 'A-1');
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $this->actingAs($coordinator)->post(route('municipal.results.cipher', $p1), ['cipher' => 'A-2'])
            ->assertSessionHasNoErrors();
        $this->assertSame('A-2', $p1->fresh()->barcode);

        // Дубль шифра в рамках олимпиады отклоняется.
        $this->actingAs($coordinator)->post(route('municipal.results.cipher', $p1), ['cipher' => 'A-1'])
            ->assertSessionHasErrors('cipher');
    }

    public function test_coordinator_imports_ciphers_from_key_file(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ateA, $schoolA] = $this->ateSchool('01');
        [, $schoolB] = $this->ateSchool('02');
        $olympiad = $this->municipal($year);
        $p1 = $this->participant($schoolA, $olympiad);
        $p2 = $this->participant($schoolA, $olympiad);
        $taken = $this->participant($schoolA, $olympiad, 'BUSY'); // шифр уже занят
        $foreign = $this->participant($schoolB, $olympiad);       // чужой АТЕ
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ateA->id, 'is_active' => true]);

        // p1→C-1 ок; p2→BUSY занят; foreign не из состава; дубль шифра C-1.
        $csv = "ID;Шифр\n{$p1->id};C-1\n{$p2->id};BUSY\n{$foreign->id};C-9\n{$taken->id};C-1\n";
        $file = \Illuminate\Http\Testing\File::createWithContent('keys.csv', $csv);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.import-ciphers', $olympiad), ['file' => $file])
            ->assertSessionHas('success');

        $this->assertSame('C-1', $p1->fresh()->barcode);
        $this->assertNull($p2->fresh()->barcode);          // шифр занят
        $this->assertSame('BUSY', $taken->fresh()->barcode); // не перезаписан (C-1 уже занят p1)
        $this->assertNull($foreign->fresh()->barcode);      // чужой АТЕ
    }

    public function test_chair_sees_only_own_ate_ciphered_works_and_enters_score(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ateA, $schoolA] = $this->ateSchool('01');
        [, $schoolB] = $this->ateSchool('02');
        $olympiad = $this->municipal($year);
        $mine = $this->participant($schoolA, $olympiad, 'SH-7');   // мой АТЕ, зашифрован
        $this->participant($schoolA, $olympiad);                   // мой АТЕ, без шифра — не показываем
        $foreign = $this->participant($schoolB, $olympiad, 'SH-B'); // другой АТЕ — не показываем

        $chair = User::factory()->create(['role' => UserRole::CommissionChair, 'ate_id' => $ateA->id, 'is_active' => true]);
        $olympiad->commissionChairs()->attach($chair->id);

        // Видит только зашифрованную работу своего АТЕ, без ФИО.
        $this->actingAs($chair)->get(route('commission.results.show', $olympiad))
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->component('Commission/Results/Show')
                ->where('works.total', 1)
                ->where('works.data.0.cipher', 'SH-7')
                ->missing('works.data.0.fio'));

        // Вводит балл по своей работе; чужой АТЕ — запрещено.
        $this->actingAs($chair)->post(route('commission.results.primary', $mine), ['primary_score' => '42'])
            ->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(42, (float) $mine->fresh()->primary_score, 0.001);

        $this->actingAs($chair)->post(route('commission.results.primary', $foreign), ['primary_score' => '10'])->assertForbidden();
    }

    public function test_chair_imports_primary_scores_from_csv(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ateA, $schoolA] = $this->ateSchool('01');
        [, $schoolB] = $this->ateSchool('02');
        $olympiad = $this->municipal($year);
        $mine = $this->participant($schoolA, $olympiad, 'SH-1');
        $second = $this->participant($schoolA, $olympiad, 'SH-2');
        $foreign = $this->participant($schoolB, $olympiad, 'SH-B'); // другой АТЕ — игнор

        $chair = User::factory()->create(['role' => UserRole::CommissionChair, 'ate_id' => $ateA->id, 'is_active' => true]);
        $olympiad->commissionChairs()->attach($chair->id);

        // max_score для класса 9 = 50: балл 60 превышает максимум → пропуск; SH-2 второй раз — дубль.
        $csv = "Шифр;Балл\nSH-1;42\nSH-2;60\nSH-2;30\nSH-X;15\nSH-B;25\n";
        $file = \Illuminate\Http\Testing\File::createWithContent('scores.csv', $csv);

        $this->actingAs($chair)
            ->post(route('commission.results.import', $olympiad), ['file' => $file])
            ->assertSessionHas('success');

        $this->assertEqualsWithDelta(42, (float) $mine->fresh()->primary_score, 0.001);
        $this->assertNull($second->fresh()->primary_score);   // 60>max и дубль → не записан
        $this->assertNull($foreign->fresh()->primary_score);  // чужой АТЕ не затронут
    }

    public function test_chair_imports_scores_from_xlsx_template(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ateA, $schoolA] = $this->ateSchool('01');
        $olympiad = $this->municipal($year);
        $mine = $this->participant($schoolA, $olympiad, 'SH-1');
        $chair = User::factory()->create(['role' => UserRole::CommissionChair, 'ate_id' => $ateA->id, 'is_active' => true]);
        $olympiad->commissionChairs()->attach($chair->id);

        // Реальный XLSX с шапкой (код этой олимпиады) + строка «шифр … балл».
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setCellValue('A1', 'Олимпиада');
        $sh->setCellValue('B1', $olympiad->subject);
        $sh->setCellValue('A2', 'Код олимпиады (не изменять)');
        $sh->setCellValueExplicit('B2', (string) $olympiad->id, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sh->fromArray(['Шифр', 'Класс участия', 'Балл'], null, 'A3');
        $sh->fromArray(['SH-1', '9', '37'], null, 'A4');
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($path);
        $file = new \Illuminate\Http\UploadedFile($path, 'bally.xlsx', null, null, true);

        $this->actingAs($chair)
            ->post(route('commission.results.import', $olympiad), ['file' => $file])
            ->assertSessionHas('success');

        $this->assertEqualsWithDelta(37, (float) $mine->fresh()->primary_score, 0.001);
        @unlink($path);
    }

    public function test_import_rejected_when_file_from_other_olympiad(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ateA, $schoolA] = $this->ateSchool('01');
        $olympiad = $this->municipal($year);
        $mine = $this->participant($schoolA, $olympiad, 'SH-1');
        $chair = User::factory()->create(['role' => UserRole::CommissionChair, 'ate_id' => $ateA->id, 'is_active' => true]);
        $olympiad->commissionChairs()->attach($chair->id);

        // Файл с шапкой от ДРУГОЙ олимпиады (код 99999) — импорт должен отклониться.
        $csv = "Олимпиада;Физ\nКод олимпиады (не изменять);99999\nШифр;Балл\nSH-1;42\n";
        $file = \Illuminate\Http\Testing\File::createWithContent('scores.csv', $csv);

        $this->actingAs($chair)
            ->post(route('commission.results.import', $olympiad), ['file' => $file])
            ->assertSessionHasErrors('file');

        $this->assertNull($mine->fresh()->primary_score); // ничего не записано
    }

    public function test_chair_forbidden_on_unassigned_olympiad(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        [$ate, $school] = $this->ateSchool();
        $olympiad = $this->municipal($year);
        $work = $this->participant($school, $olympiad, 'X-1');
        $chair = User::factory()->create(['role' => UserRole::CommissionChair, 'ate_id' => $ate->id, 'is_active' => true]); // не назначен

        $this->actingAs($chair)->get(route('commission.results.show', $olympiad))->assertForbidden();
        $this->actingAs($chair)->post(route('commission.results.primary', $work), ['primary_score' => '10'])->assertForbidden();
    }
}
