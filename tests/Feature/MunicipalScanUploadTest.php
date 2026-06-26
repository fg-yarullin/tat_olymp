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

class MunicipalScanUploadTest extends TestCase
{
    use RefreshDatabase;

    private function makeWork(Olympiad $o, ?string $barcode, string $ateCode = '01'): HumanOlympiad
    {
        $ate = Ate::firstOrCreate(['ate_code' => $ateCode], ['name' => "АТЕ {$ateCode}", 'type' => 'isolated']);
        $msu = Msu::firstOrCreate(['msu_code' => $ateCode], ['name' => 'МСУ', 'ate_id' => $ate->id]);
        $school = School::create([
            'oo_code' => 'OO'.uniqid(), 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => $ateCode, 'ate_id' => $ate->id, 'ate_code' => $ateCode,
        ]);
        $student = Student::create(['fio' => 'Уч '.uniqid(), 'birth_date' => '2010-01-01', 'school_id' => $school->id, 'real_grade' => 9]);

        return HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $o->id, 'participation_grade' => 9,
            'result_status' => 'participant', 'barcode' => $barcode,
        ]);
    }

    private function zip(array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'scans').'.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return new UploadedFile($path, 'scans.zip', 'application/zip', null, true);
    }

    public function test_admin_uploads_scans_matched_by_cipher(): void
    {
        Storage::fake();
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal', 'grades' => '9',
            'date_held' => '2025-12-01',
        ]);
        $mine = $this->makeWork($olympiad, 'A-1', '01');
        $foreign = $this->makeWork($olympiad, 'B-2', '02'); // другой АТЕ
        $ate01 = Ate::where('ate_code', '01')->first();
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $file = $this->zip([
            'A-1.pdf' => '%PDF-1.4 ok',
            'B-2.pdf' => 'чужой АТЕ — не сопоставится при выборе АТЕ 01',
            'A-1.txt' => 'неверный формат',
        ]);

        // Без выбора АТЕ — ошибка валидации.
        $this->actingAs($admin)
            ->post(route('admin.olympiads.scans', $olympiad), ['file' => $this->zip(['A-1.pdf' => 'x'])])
            ->assertSessionHasErrors('ate_id');

        // С выбором АТЕ 01 — грузится только его работа.
        $this->actingAs($admin)
            ->post(route('admin.olympiads.scans', $olympiad), ['file' => $file, 'ate_id' => $ate01->id])
            ->assertSessionHas('success');

        $this->assertNotNull($mine->fresh()->scan_path);
        Storage::assertExists($mine->fresh()->scan_path);
        $this->assertNull($foreign->fresh()->scan_path);
    }

    public function test_coordinator_uploads_scans_only_for_own_ate(): void
    {
        Storage::fake();
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal', 'grades' => '9',
            'date_held' => '2025-12-01',
        ]);
        $mine = $this->makeWork($olympiad, 'A-1', '01');
        $foreign = $this->makeWork($olympiad, 'B-2', '02');
        $ate = Ate::where('ate_code', '01')->first();
        $coordinator = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'ate_id' => $ate->id, 'is_active' => true]);

        $file = $this->zip([
            'A-1.pdf' => '%PDF own',
            'B-2.pdf' => '%PDF foreign',   // чужой АТЕ — пропуск
        ]);

        $this->actingAs($coordinator)
            ->post(route('municipal.results.scans', $olympiad), ['file' => $file])
            ->assertSessionHas('success');

        $this->assertNotNull($mine->fresh()->scan_path);
        $this->assertNull($foreign->fresh()->scan_path);   // чужой АТЕ не затронут
    }

    public function test_artisan_imports_scans_from_server_folder(): void
    {
        Storage::fake();
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal', 'grades' => '9',
            'date_held' => '2025-12-01',
        ]);
        $mine = $this->makeWork($olympiad, 'A-1');
        $this->makeWork($olympiad, 'A-2');

        // Папка на «сервере» с распакованными сканами (имена = шифры).
        $dir = sys_get_temp_dir().'/scans_'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/A-1.pdf", '%PDF ok');
        file_put_contents("{$dir}/X-9.pdf", 'нет шифра'); // пропуск

        $this->artisan('scans:import', ['olympiad' => $olympiad->id, 'path' => $dir])
            ->assertExitCode(0);

        $this->assertNotNull($mine->fresh()->scan_path);
        Storage::assertExists($mine->fresh()->scan_path);

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    }

    public function test_artisan_rejects_school_stage(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $school = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'school', 'grades' => '9',
            'date_held' => '2025-11-01',
        ]);

        $this->artisan('scans:import', ['olympiad' => $school->id, 'path' => sys_get_temp_dir()])
            ->assertExitCode(1);
    }

    public function test_scan_upload_rejected_for_school_stage(): void
    {
        Storage::fake();
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $school = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'school', 'grades' => '9',
            'date_held' => '2025-11-01',
        ]);
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.olympiads.scans', $school), ['file' => $this->zip(['A-1.pdf' => 'x'])])
            ->assertNotFound();
    }
}
