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

    public function test_imports_coordinator_bound_to_ate(): void
    {
        $ate = $this->ate('10');

        $file = $this->csv([
            ['ФИО', 'email', 'роль', 'код', 'пароль'],
            ['Иванов И.', 'coord@x.local', 'municipal_coordinator', '10', 'secret123'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.coordinators'), ['file' => $file])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'email' => 'coord@x.local', 'role' => 'municipal_coordinator',
            'ate_id' => $ate->id, 'school_id' => null,
        ]);
    }

    public function test_imports_operator_bound_to_school(): void
    {
        $ate = $this->ate('20');
        $school = $this->school($ate, '2001');

        $file = $this->csv([
            ['ФИО', 'email', 'роль', 'код', 'пароль'],
            ['Петров П.', 'op@x.local', 'school_operator', '2001', 'secret123'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.coordinators'), ['file' => $file])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'email' => 'op@x.local', 'role' => 'school_operator',
            'school_id' => $school->id, 'ate_id' => null,
        ]);
    }

    public function test_unknown_ate_and_bad_role_are_reported(): void
    {
        $file = $this->csv([
            ['ФИО', 'email', 'роль', 'код', 'пароль'],
            ['Без АТЕ', 'a@x.local', 'municipal_coordinator', 'НЕТ', 'secret123'],
            ['Плохая роль', 'b@x.local', 'admin', '10', 'secret123'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.coordinators'), ['file' => $file])
            ->assertSessionHasErrors('import');

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

        $file = $this->csv([
            ['ФИО', 'email', 'роль', 'код', 'пароль'],
            ['Новое Имя', 'up@x.local', 'municipal_coordinator', '30', ''],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.coordinators'), ['file' => $file])
            ->assertSessionHas('success');

        $existing->refresh();
        $this->assertSame('Новое Имя', $existing->name);
        $this->assertSame($originalHash, $existing->password);
    }

    public function test_new_user_without_password_is_rejected(): void
    {
        $this->ate('40');

        $file = $this->csv([
            ['ФИО', 'email', 'роль', 'код', 'пароль'],
            ['Без пароля', 'np@x.local', 'municipal_coordinator', '40', ''],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.coordinators'), ['file' => $file])
            ->assertSessionHasErrors('import');

        $this->assertDatabaseMissing('users', ['email' => 'np@x.local']);
    }
}
