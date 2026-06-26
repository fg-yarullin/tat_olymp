<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\Olympiad;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminDataImportTest extends TestCase
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

    private function school(): School
    {
        $ate = Ate::firstOrCreate(['ate_code' => '10'], ['name' => 'АТЕ', 'type' => 'isolated']);
        $msu = Msu::firstOrCreate(['msu_code' => '10'], ['name' => 'МСУ', 'ate_id' => $ate->id]);

        return School::create([
            'oo_code' => '100001', 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);
    }

    private function csv(array $rows): UploadedFile
    {
        $lines = array_map(fn ($r) => implode(';', $r), $rows);
        $path = tempnam(sys_get_temp_dir(), 'di'.(++$this->seq)).'.csv';
        file_put_contents($path, "\xEF\xBB\xBF".implode("\n", $lines));

        return new UploadedFile($path, 'data.csv', 'text/csv', null, true);
    }

    public function test_import_subjects_creates_and_updates(): void
    {
        Subject::create(['name' => 'Черчение', 'is_active' => true]);

        $file = $this->csv([
            ['Название', 'Активен'],
            ['Робототехника', '1'],
            ['Черчение', '0'], // обновление -> деактивация
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.subjects'), ['file' => $file])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('subjects', ['name' => 'Робототехника', 'is_active' => true]);
        $this->assertDatabaseHas('subjects', ['name' => 'Черчение', 'is_active' => false]);
    }

    public function test_import_olympiads_upserts_by_natural_key(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        Subject::create(['name' => 'Математика', 'is_active' => true]);

        $rows = [
            ['Учебный год', 'Предмет', 'Этап', 'Статус', 'Дата'],
            ['2025/2026', 'Математика', 'school', 'published', '2025-11-15'],
        ];

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.imports.olympiads'), ['file' => $this->csv($rows)]);
        // Повторный импорт того же ключа с другой датой -> обновление, не дубликат
        $rows[1][4] = '2025-11-20';
        $this->actingAs($admin)->post(route('admin.imports.olympiads'), ['file' => $this->csv($rows)])
            ->assertSessionHas('success');

        $this->assertSame(1, Olympiad::count());
        $o = Olympiad::first();
        $this->assertSame('Математика', $o->subject);          // денормализованная строка
        $this->assertNotNull($o->subject_id);                  // нормализованная ссылка
        $this->assertNotNull($o->published_at);                // импорт «published» выставил окно показа
    }

    public function test_import_olympiads_parses_result_deadlines(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        Subject::create(['name' => 'Физика', 'is_active' => true]);

        $file = $this->csv([
            ['Год', 'Предмет', 'Этап', 'Статус', 'Дата', 'Первичный', 'Итоговый'],
            ['2025/2026', 'Физика', 'municipal', 'grading', '2025-12-10', '15.12.2025 18:00', '25.12.2025 18:00'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.olympiads'), ['file' => $file])
            ->assertSessionHasNoErrors();

        $o = Olympiad::first();
        $this->assertSame('2025-12-15 18:00', $o->results_deadline->format('Y-m-d H:i'));
        $this->assertSame('2025-12-25 18:00', $o->final_results_deadline->format('Y-m-d H:i'));
    }

    public function test_import_olympiads_splits_by_grades(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        Subject::create(['name' => 'Математика', 'is_active' => true]);

        // Те же год/предмет/этап, но разные классы -> две олимпиады (колонка 8 = классы)
        $file = $this->csv([
            ['Год', 'Предмет', 'Этап', 'Статус', 'Дата', 'Перв', 'Итог', 'Классы'],
            ['2025/2026', 'Математика', 'school', 'planned', '2025-11-15', '', '', '4-6'],
            ['2025/2026', 'Математика', 'school', 'planned', '2025-11-15', '', '', '7-11'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.olympiads'), ['file' => $file])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Olympiad::count());
        $this->assertDatabaseHas('olympiads', ['stage' => 'school', 'grades' => '4,5,6']);
        $this->assertDatabaseHas('olympiads', ['stage' => 'school', 'grades' => '7,8,9,10,11']);
    }

    public function test_import_olympiads_reports_unknown_subject(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);

        $file = $this->csv([
            ['Учебный год', 'Предмет', 'Этап', 'Статус', 'Дата'],
            ['2025/2026', 'Несуществующий', 'school', 'planned', '2025-11-15'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.olympiads'), ['file' => $file])
            ->assertSessionHasErrors('import');

        $this->assertSame(0, Olympiad::count());
    }

    public function test_import_students_converts_dmy_date_and_upserts(): void
    {
        $this->school();

        // Дата в школьном формате ДД-ММ-ГГГГ
        $rows = [
            ['ФИО', 'Дата рождения', 'СНИЛС', 'Код ОО', 'Класс', 'Статус'],
            ['Смирнов Алексей', '15-03-2012', '', '100001', '7', 'active'],
        ];

        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.imports.students'), ['file' => $this->csv($rows)]);
        $rows[1][4] = '8'; // тот же ученик (та же дата) -> обновление, не дубликат
        $this->actingAs($admin)->post(route('admin.imports.students'), ['file' => $this->csv($rows)])
            ->assertSessionHas('success');

        $this->assertSame(1, Student::count());
        $student = Student::first();
        $this->assertSame(8, $student->real_grade);
        // Сохранено в ISO ГГГГ-ММ-ДД
        $this->assertSame('2012-03-15', $student->birth_date->toDateString());
    }

    public function test_import_students_accepts_two_digit_year(): void
    {
        $this->school();

        $file = $this->csv([
            ['ФИО', 'Дата рождения', 'СНИЛС', 'Код ОО', 'Класс', 'Статус'],
            ['Юный Участник', '15.03.12', '', '100001', '6', 'active'], // ДД.ММ.ГГ
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.students'), ['file' => $file])
            ->assertSessionHasNoErrors();

        // ГГ=12 -> 2012 (окно 1970–2069), а не 0012
        $this->assertSame('2012-03-15', Student::first()->birth_date->toDateString());
    }

    public function test_import_students_maps_russian_gender(): void
    {
        $this->school();

        $file = $this->csv([
            ['ФИО', 'ДР', 'СНИЛС', 'Код ОО', 'Класс', 'Статус', 'ОВЗ', 'Пол'],
            ['Девочка Тест', '01-09-2011', '', '100001', '8', 'active', '', 'ж'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.students'), ['file' => $file])
            ->assertSessionHasNoErrors();

        $this->assertSame('female', Student::first()->gender);
    }

    public function test_import_students_rejects_invalid_date(): void
    {
        $this->school();

        $file = $this->csv([
            ['ФИО', 'Дата рождения', 'СНИЛС', 'Код ОО', 'Класс', 'Статус'],
            ['Кривая Дата', '32-13-2012', '', '100001', '7', 'active'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.students'), ['file' => $file])
            ->assertSessionHasErrors('import');

        $this->assertSame(0, Student::count());
    }

    public function test_import_users_allows_admin_role(): void
    {
        $file = $this->csv([
            ['ФИО', 'Email', 'Роль', 'Код', 'Пароль'],
            ['Главный Админ', 'newadmin@x.local', 'admin', '', 'secret123'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.imports.users'), ['file' => $file])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', [
            'email' => 'newadmin@x.local', 'role' => 'admin', 'ate_id' => null, 'school_id' => null,
        ]);
    }

    public function test_templates_parse_against_importers(): void
    {
        $admin = $this->admin();

        // Предметы: шаблон без зависимостей
        $this->actingAs($admin)->post(route('admin.imports.subjects'), [
            'file' => new UploadedFile(public_path('templates/import_subjects.csv'), 'import_subjects.csv', 'text/csv', null, true),
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subjects', ['name' => 'Робототехника']);

        // Олимпиады: нужны год и предметы из шаблона
        AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        Subject::firstOrCreate(['name' => 'Математика'], ['is_active' => true]);
        Subject::firstOrCreate(['name' => 'Физика'], ['is_active' => true]);
        $this->actingAs($admin)->post(route('admin.imports.olympiads'), [
            'file' => new UploadedFile(public_path('templates/import_olympiads.csv'), 'import_olympiads.csv', 'text/csv', null, true),
        ])->assertSessionHasNoErrors();
        // Шаблон: Математика 4–6, Математика 7–11, Физика = 3 олимпиады
        $this->assertSame(3, Olympiad::count());
    }
}
