<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Ate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Гарантирует, что отгружаемые пользователю шаблоны public/templates/*.csv
 * корректно разбираются реальным импортёром (BOM, разделитель, колонки).
 */
class AdminImportTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function templateUpload(string $name): UploadedFile
    {
        $path = public_path("templates/$name");
        $this->assertFileExists($path);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    public function test_ate_template_imports_cleanly(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.imports.ates'), ['file' => $this->templateUpload('import_ates.csv')])
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        // Две строки-примера из шаблона
        $this->assertDatabaseHas('ates', ['ate_code' => '10']);
        $this->assertDatabaseHas('ates', ['ate_code' => '11']);
    }

    public function test_coordinator_template_columns_parse(): void
    {
        // Готовим привязки, на которые ссылается шаблон координаторов
        Ate::create(['ate_code' => '10', 'name' => 'АТЕ', 'type' => 'isolated']);
        Ate::create(['ate_code' => '45', 'name' => 'Казань', 'type' => 'unified']);
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.imports.coordinators'), ['file' => $this->templateUpload('import_coordinators.csv')]);

        // Координатор и супер-координатор из шаблона созданы (оператор — нет школы 100001, это ожидаемо)
        $this->assertDatabaseHas('users', ['email' => 'coordinator@example.ru', 'role' => 'municipal_coordinator']);
        $this->assertDatabaseHas('users', ['email' => 'super.kazan@example.ru', 'role' => 'super_coordinator']);
    }
}
