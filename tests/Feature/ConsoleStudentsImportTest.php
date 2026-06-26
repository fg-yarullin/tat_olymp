<?php

namespace Tests\Feature;

use App\Models\Ate;
use App\Models\Msu;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsoleStudentsImportTest extends TestCase
{
    use RefreshDatabase;

    private string $errorsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $ate = Ate::create(['ate_code' => '10', 'name' => 'АТЕ', 'type' => 'isolated']);
        $msu = Msu::create(['msu_code' => '10', 'name' => 'МСУ', 'ate_id' => $ate->id]);
        School::create([
            'oo_code' => '100001', 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);

        $this->errorsPath = tempnam(sys_get_temp_dir(), 'cerr').'.csv';
    }

    private function makeCsv(array $rows): string
    {
        $header = 'ФИО;Дата рождения;СНИЛС;Код ОО;Класс;Статус;ОВЗ;Пол';
        $lines = array_map(fn ($r) => implode(';', $r), $rows);
        $path = tempnam(sys_get_temp_dir(), 'cimp').'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".$header."\n".implode("\n", $lines));

        return $path;
    }

    private function runImport(string $file): int
    {
        return $this->artisan('students:import', [
            'file' => $file, '--errors' => $this->errorsPath, '--chunk' => 2,
        ])->run();
    }

    public function test_imports_dedupes_and_normalizes_snils(): void
    {
        $file = $this->makeCsv([
            ['Иванов Иван', '15.03.2012', '123-456-789 00', '100001', '7', 'active', '', 'м'],
            ['Петров Пётр', '01.09.2011', '111-111-111 11', '100001', '8', 'active', '1', 'м'],
            // дубль по СНИЛС (другой формат записи того же номера) -> пропускается
            ['Иванов И.', '15.03.2012', '12345678900', '100001', '7', 'active', '', 'м'],
            // невалидный СНИЛС -> отклонение
            ['Без Снилса', '01.01.2010', '', '100001', '5', 'active', '', 'ж'],
            // неизвестная школа -> отклонение
            ['Чужой Код', '01.01.2010', '222-222-222 22', '999999', '5', 'active', '', 'ж'],
        ]);

        $this->assertSame(0, $this->runImport($file));

        // 2 уникальных валидных ученика
        $this->assertSame(2, Student::count());
        $ivanov = Student::where('fio', 'Иванов Иван')->first();
        $this->assertSame('12345678900', $ivanov->snils);   // канонизирован к 11 цифрам
        $this->assertSame('2012-03-15', $ivanov->birth_date->toDateString());
        $this->assertSame('male', $ivanov->gender);

        // Дубль и две невалидные строки попали в файл ошибок
        $errors = file_get_contents($this->errorsPath);
        $this->assertStringContainsString('дубль СНИЛС', $errors);
        $this->assertStringContainsString('СНИЛС обязателен', $errors);
        $this->assertStringContainsString('неизвестный код ОО', $errors);
    }

    public function test_rerun_is_idempotent_and_updates(): void
    {
        $first = $this->makeCsv([
            ['Иванов Иван', '15.03.2012', '123-456-789 00', '100001', '7', 'active', '', 'м'],
        ]);
        $this->runImport($first);
        $this->assertSame(1, Student::count());

        // Повторный запуск с изменённым классом -> та же запись, обновлённый класс
        $second = $this->makeCsv([
            ['Иванов Иван', '15.03.2012', '123-456-789 00', '100001', '9', 'active', '', 'м'],
        ]);
        $this->runImport($second);

        $this->assertSame(1, Student::count());          // не задвоилось
        $this->assertSame(9, Student::first()->real_grade); // обновилось
    }

    public function test_fails_on_missing_file(): void
    {
        $this->artisan('students:import', ['file' => '/no/such/file.csv'])
            ->assertExitCode(1);
    }
}
