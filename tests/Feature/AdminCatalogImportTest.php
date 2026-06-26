<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AdminCatalogImportTest extends TestCase
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

    private function csv(array $rows): UploadedFile
    {
        $lines = array_map(fn ($r) => implode(';', $r), $rows);
        $path = tempnam(sys_get_temp_dir(), 'imp'.(++$this->seq)).'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));

        return new UploadedFile($path, 'data.csv', 'text/csv', null, true);
    }

    private function xlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'imp'.(++$this->seq)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'data.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_import_ates_csv_creates_and_updates(): void
    {
        Ate::create(['ate_code' => '77', 'name' => 'Старое имя', 'type' => 'isolated']);

        $file = $this->csv([
            ['№', 'код', 'название'],
            ['1', '77', 'Новое имя'],      // обновление
            ['2', '88', 'Новая АТЕ'],      // создание
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.ates'), ['file' => $file])
            ->assertSessionHas('success');

        $this->assertSame('Новое имя', Ate::firstWhere('ate_code', '77')->name);
        $this->assertDatabaseHas('ates', ['ate_code' => '88', 'name' => 'Новая АТЕ']);
    }

    public function test_import_msus_links_ate_and_recomputes_type(): void
    {
        Ate::create(['ate_code' => '77', 'name' => 'АТЕ', 'type' => 'isolated']);

        $file = $this->csv([
            ['№', 'код_АТЕ', 'код_МСУ', 'название'],
            ['1', '77', '770', 'МСУ-1'],
            ['2', '77', '771', 'МСУ-2'], // две МСУ под одной АТЕ -> unified
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.msus'), ['file' => $file])
            ->assertSessionHas('success');

        $this->assertSame(2, Msu::where('msu_code', 'like', '77%')->count());
        $this->assertSame('unified', Ate::firstWhere('ate_code', '77')->type);
    }

    public function test_import_schools_derives_codes_from_msu(): void
    {
        $ate = Ate::create(['ate_code' => '77', 'name' => 'АТЕ', 'type' => 'isolated']);
        $msu = Msu::create(['msu_code' => '770', 'name' => 'МСУ', 'ate_id' => $ate->id]);

        $file = $this->csv([
            ['№', 'код_ОО', 'полное', 'краткое', 'уровень', 'код_АТЕ', 'код_МСУ', 'город'],
            ['1', '7701', 'Гимназия №1', 'Гимназия', '3', '99', '770', '1'], // код АТЕ в файле игнорируется
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.schools'), ['file' => $file])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('schools', [
            'oo_code' => '7701', 'short_name' => 'Гимназия', 'education_level' => 3,
            'territorial_sign' => 'city', 'msu_id' => $msu->id, 'msu_code' => '770',
            'ate_id' => $ate->id, 'ate_code' => '77',
        ]);
    }

    public function test_import_accepts_xlsx(): void
    {
        $file = $this->xlsx([
            ['№', 'код', 'название'],
            [1, '50', 'АТЕ из Excel'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.ates'), ['file' => $file])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ates', ['ate_code' => '50', 'name' => 'АТЕ из Excel']);
    }

    public function test_unknown_msu_in_school_row_is_reported(): void
    {
        $file = $this->csv([
            ['№', 'код_ОО', 'полное', 'краткое', 'уровень', 'код_АТЕ', 'код_МСУ', 'город'],
            ['1', '9999', 'Школа', 'Ш', '3', '1', 'НЕТ', '1'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.schools'), ['file' => $file])
            ->assertSessionHasErrors('import');

        $this->assertDatabaseMissing('schools', ['oo_code' => '9999']);
    }

    public function test_non_admin_forbidden(): void
    {
        $coord = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'is_active' => true]);

        $this->actingAs($coord)->get(route('admin.imports.index'))->assertForbidden();
    }
}
