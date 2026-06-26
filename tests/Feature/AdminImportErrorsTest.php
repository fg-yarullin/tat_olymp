<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Ate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminImportErrorsTest extends TestCase
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
        $path = tempnam(sys_get_temp_dir(), 'err'.(++$this->seq)).'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));

        return new UploadedFile($path, 'data.csv', 'text/csv', null, true);
    }

    public function test_failed_rows_are_stored_and_downloadable(): void
    {
        Ate::create(['ate_code' => '10', 'name' => 'АТЕ', 'type' => 'isolated']);
        $admin = $this->admin();

        // МСУ: первая строка валидна, вторая ссылается на несуществующую АТЕ
        $file = $this->csv([
            ['№', 'Код АТЕ', 'Код МСУ', 'Название'],
            ['1', '10', '100', 'Хорошее МСУ'],
            ['2', 'НЕТ', '200', 'Плохое МСУ'],
        ]);

        $response = $this->actingAs($admin)->post(route('admin.imports.msus'), ['file' => $file]);
        $response->assertSessionHasErrors('import');

        // Индекс отдаёт сводку об ошибках
        $this->actingAs($admin)->get(route('admin.imports.index'))
            ->assertInertia(fn ($page) => $page->where('importErrors.count', 1)->where('importErrors.label', 'МСУ'));

        // Выгрузка содержит проблемную строку с исходными ячейками + причину
        $download = $this->actingAs($admin)->get(route('admin.imports.errors'));
        $download->assertOk();
        $content = $download->streamedContent();
        $this->assertStringContainsString('Плохое МСУ', $content);
        $this->assertStringContainsString('Ошибка', $content);            // добавленный столбец
        $this->assertStringContainsString('неизвестный код АТЕ', $content);
        $this->assertStringNotContainsString('Хорошее МСУ', $content);    // валидная строка не попала
    }

    public function test_clean_import_clears_previous_errors(): void
    {
        Ate::create(['ate_code' => '10', 'name' => 'АТЕ', 'type' => 'isolated']);
        $admin = $this->admin();

        // Импорт с ошибкой -> сессия заполнена
        $this->actingAs($admin)->post(route('admin.imports.msus'), [
            'file' => $this->csv([['№', 'А', 'М', 'Н'], ['2', 'НЕТ', '200', 'Плохое']]),
        ]);
        $this->assertNotNull(session('import_errors'));

        // Чистый импорт -> ошибки очищены
        $this->actingAs($admin)->post(route('admin.imports.msus'), [
            'file' => $this->csv([['№', 'А', 'М', 'Н'], ['1', '10', '300', 'Хорошее']]),
        ]);
        $this->assertNull(session('import_errors'));
    }

    public function test_download_without_errors_redirects(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.imports.errors'))
            ->assertRedirect(route('admin.imports.index'));
    }
}
