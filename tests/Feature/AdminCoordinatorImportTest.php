<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCoordinatorImportTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function ate(string $code = '10'): Ate
    {
        return Ate::firstOrCreate(['ate_code' => $code], ['name' => 'АТЕ '.$code, 'type' => 'isolated']);
    }

    private function school(Ate $ate, string $oo = '1001'): School
    {
        $msu = Msu::firstOrCreate(['msu_code' => $ate->ate_code], ['name' => 'МСУ', 'ate_id' => $ate->id]);

        return School::create([
            'oo_code' => $oo, 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => $msu->msu_code, 'ate_id' => $ate->id, 'ate_code' => $ate->ate_code,
        ]);
    }

    private function csv(array $rows): UploadedFile
    {
        $lines = array_map(fn ($r) => implode(';', $r), $rows);
        $path = tempnam(sys_get_temp_dir(), 'crd'.(++$this->seq)).'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));

        return new UploadedFile($path, 'coords.csv', 'text/csv', null, true);
    }

    /** Запускает чанковый импорт (start → чанки до завершения) тем же админом; возвращает итог. */
    private function runImport(UploadedFile $file): array
    {
        $this->actingAs($this->admin());
        $start = $this->post(route('admin.imports.coordinators'), ['file' => $file])->json();
        $prog = ['done' => false];
        while (! $prog['done']) {
            $prog = $this->post(route('admin.imports.users.chunk', $start['id']))->json();
        }

        return $prog;
    }

    public function test_imports_coordinator_bound_to_ate(): void
    {
        $ate = $this->ate('10');

        $prog = $this->runImport($this->csv([
            ['ФИО', 'email', 'роль', 'код', 'пароль'],
            ['Иванов И.', 'coord@x.local', 'municipal_coordinator', '10', 'secret123'],
        ]));

        $this->assertSame(1, $prog['created']);
        $this->assertDatabaseHas('users', [
            'email' => 'coord@x.local', 'role' => 'municipal_coordinator',
            'ate_id' => $ate->id, 'school_id' => null,
        ]);
    }

    public function test_imports_operator_bound_to_school(): void
    {
        $ate = $this->ate('20');
        $school = $this->school($ate, '2001');

        $prog = $this->runImport($this->csv([
            ['ФИО', 'email', 'роль', 'код', 'пароль'],
            ['Петров П.', 'op@x.local', 'school_operator', '2001', 'secret123'],
        ]));

        $this->assertSame(1, $prog['created']);
        $this->assertDatabaseHas('users', [
            'email' => 'op@x.local', 'role' => 'school_operator',
            'school_id' => $school->id, 'ate_id' => null,
        ]);
    }

    public function test_unknown_ate_and_bad_role_are_reported(): void
    {
        $prog = $this->runImport($this->csv([
            ['ФИО', 'email', 'роль', 'код', 'пароль'],
            ['Без АТЕ', 'a@x.local', 'municipal_coordinator', 'НЕТ', 'secret123'],
            ['Плохая роль', 'b@x.local', 'admin', '10', 'secret123'],
        ]));

        $this->assertSame(2, $prog['failed']);
        $this->assertDatabaseMissing('users', ['email' => 'a@x.local']);
        $this->assertDatabaseMissing('users', ['email' => 'b@x.local']);
    }

    public function test_update_by_email_keeps_password_when_blank(): void
    {
        $ate = $this->ate('30');
        $existing = User::factory()->create([
            'email' => 'up@x.local', 'role' => UserRole::MunicipalCoordinator,
            'ate_id' => $ate->id, 'is_active' => true, 'name' => 'Старое',
        ]);
        $originalHash = $existing->password;

        $prog = $this->runImport($this->csv([
            ['ФИО', 'email', 'роль', 'код', 'пароль'],
            ['Новое Имя', 'up@x.local', 'municipal_coordinator', '30', ''],
        ]));

        $this->assertSame(1, $prog['updated']);
        $existing->refresh();
        $this->assertSame('Новое Имя', $existing->name);
        $this->assertSame($originalHash, $existing->password);
    }

    public function test_new_user_without_password_is_rejected(): void
    {
        $this->ate('40');

        $prog = $this->runImport($this->csv([
            ['ФИО', 'email', 'роль', 'код', 'пароль'],
            ['Без пароля', 'np@x.local', 'municipal_coordinator', '40', ''],
        ]));

        $this->assertSame(1, $prog['failed']);
        $this->assertDatabaseMissing('users', ['email' => 'np@x.local']);
    }

    public function test_chunked_progress_completes_over_multiple_chunks(): void
    {
        $ate = $this->ate('50');
        // 150 строк (> размера чанка 100) — импорт пройдёт за несколько чанков.
        $rows = [['ФИО', 'email', 'роль', 'код', 'пароль']];
        for ($k = 0; $k < 150; $k++) {
            $rows[] = ["Коорд {$k}", "c{$k}@x.local", 'municipal_coordinator', '50', 'secret123'];
        }

        $this->actingAs($this->admin());
        $start = $this->post(route('admin.imports.coordinators'), ['file' => $this->csv($rows)])->json();
        $this->assertSame(150, $start['total']);

        $chunks = 0;
        $prog = ['done' => false];
        while (! $prog['done']) {
            $prog = $this->post(route('admin.imports.users.chunk', $start['id']))->json();
            $chunks++;
        }

        $this->assertGreaterThanOrEqual(2, $chunks); // обработано не за один чанк
        $this->assertSame(150, $prog['created']);
        $this->assertSame(150, User::where('role', 'municipal_coordinator')->where('ate_id', $ate->id)->count());
    }
}
