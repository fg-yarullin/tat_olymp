<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\HumanOlympiad;
use App\Models\Msu;
use App\Models\Olympiad;
use App\Models\ProtocolColumn;
use App\Models\ProtocolTemplate;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Services\ProtocolExporter;
use App\Support\ProtocolSources;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtocolConstructorTest extends TestCase
{
    use RefreshDatabase;

    private function participation(): HumanOlympiad
    {
        $ate = Ate::create(['ate_code' => '10', 'name' => 'АТЕ', 'type' => 'isolated']);
        $msu = Msu::create(['msu_code' => '10', 'name' => 'МСУ', 'ate_id' => $ate->id]);
        $school = School::create([
            'oo_code' => '100', 'short_name' => 'Гимназия', 'full_name' => 'Гимназия №1 г. Агрыз',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);
        $student = Student::create([
            'fio' => 'Тарасов Николай Даниилович', 'birth_date' => '2006-03-24', 'gender' => 'male',
            'snils' => '00101000001', 'school_id' => $school->id, 'real_grade' => 10, 'ovz' => true,
        ]);
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Астрономия', 'stage' => 'municipal',
            'grades' => '7,8,9,10,11', 'date_held' => '2025-12-01', 'status' => 'grading',
        ]);
        // Макс. балл — по классам, отдельной моделью.
        $olympiad->maxScores()->createMany([
            ['grade' => 9, 'max_score' => 42],
            ['grade' => 11, 'max_score' => 48],
        ]);

        return HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $olympiad->id, 'participation_grade' => 11,
            'primary_score' => 27, 'appeal_addition' => 1, 'result_status' => 'prize_winner',
            'prev_higher_stage_winner' => true, 'teacher_name' => 'Иванов И.И.',
            'question_scores' => ['1' => 4, '2' => 5, '3' => 6, '4' => 7, '5' => 2, '6' => 3],
            'question_appeals' => ['4' => 1],
        ])->load('student.school', 'olympiad.maxScores');
    }

    public function test_source_registry_resolves_values(): void
    {
        $ho = $this->participation();

        $this->assertSame('1', ProtocolSources::resolve('row_number', $ho, 1));
        $this->assertSame('Гимназия №1 г. Агрыз', ProtocolSources::resolve('school.full_name', $ho, 1));
        $this->assertSame('Тарасов', ProtocolSources::resolve('student.last_name', $ho, 1));
        $this->assertSame('м', ProtocolSources::resolve('student.gender', $ho, 1));
        $this->assertSame('24.03.2006', ProtocolSources::resolve('student.birth_date', $ho, 1));
        $this->assertSame('да', ProtocolSources::resolve('student.ovz', $ho, 1));
        $this->assertSame('27', ProtocolSources::resolve('ho.primary_score', $ho, 1));
        $this->assertSame('28', ProtocolSources::resolve('ho.final_score', $ho, 1)); // авто
        $this->assertSame('48', ProtocolSources::resolve('olympiad.max_score', $ho, 1)); // макс. балл олимпиады
        $this->assertSame('призер', ProtocolSources::resolve('ho.status', $ho, 1));
        $this->assertSame('да', ProtocolSources::resolve('ho.prev_higher_stage_winner', $ho, 1));
        // Баллы по вопросам из JSON
        $this->assertSame('4', ProtocolSources::resolve('question:1', $ho, 1));
        $this->assertSame('1', ProtocolSources::resolve('appeal:4', $ho, 1));
        $this->assertSame('', ProtocolSources::resolve('appeal:1', $ho, 1));
    }

    public function test_template_with_columns(): void
    {
        $subject = Subject::create(['name' => 'Технология', 'is_active' => true]);
        $template = ProtocolTemplate::create(['name' => 'МЭ Технология', 'stage' => 'municipal', 'subject_id' => $subject->id]);
        ProtocolColumn::create(['protocol_template_id' => $template->id, 'position' => 2, 'header' => 'СНИЛС', 'source_key' => 'student.snils']);
        ProtocolColumn::create(['protocol_template_id' => $template->id, 'position' => 1, 'header' => '№', 'source_key' => 'row_number']);

        $headers = $template->columns->pluck('header')->all();
        $this->assertSame(['№', 'СНИЛС'], $headers); // упорядочено по position
    }

    public function test_seeder_creates_default_templates(): void
    {
        Subject::create(['name' => 'Труд (технология)', 'is_active' => true]);
        Subject::create(['name' => 'Астрономия', 'is_active' => true]);

        $this->seed(\Database\Seeders\ProtocolTemplateSeeder::class);

        // Общий ШЭ и общий МЭ
        $this->assertSame(16, ProtocolTemplate::where('stage', 'school')->whereNull('subject_id')->first()?->columns()->count());
        $this->assertSame(19, ProtocolTemplate::where('stage', 'municipal')->whereNull('subject_id')->first()?->columns()->count());

        // Технология = общий МЭ + 2 колонки
        $tech = ProtocolTemplate::where('stage', 'municipal')
            ->whereHas('subject', fn ($q) => $q->where('name', 'Труд (технология)'))->first();
        $this->assertSame(21, $tech->columns()->count());

        // Астрономия: есть группа «Вопросы» и ключи question:*/appeal:*
        $astro = ProtocolTemplate::where('stage', 'municipal')
            ->whereHas('subject', fn ($q) => $q->where('name', 'Астрономия'))->first();
        $this->assertTrue($astro->columns()->where('group_header', 'Вопросы')->count() === 6);
        $this->assertTrue($astro->columns()->where('source_key', 'appeal:6')->exists());

        // Повторный запуск идемпотентен
        $this->seed(\Database\Seeders\ProtocolTemplateSeeder::class);
        $this->assertSame(4, ProtocolTemplate::count());
    }

    public function test_exporter_builds_two_level_header(): void
    {
        $ho = $this->participation();

        $template = ProtocolTemplate::create(['name' => 'Астрономия', 'stage' => 'municipal', 'subject_id' => null]);
        $cols = [
            ['№', 'row_number', null],
            ['В1', 'question:1', 'Вопросы'],
            ['В2', 'question:2', 'Вопросы'],
            ['Итог', 'ho.final_score', null],
        ];
        foreach ($cols as $pos => $c) {
            $template->columns()->create(['position' => $pos + 1, 'header' => $c[0], 'source_key' => $c[1], 'group_header' => $c[2]]);
        }

        $sheet = app(ProtocolExporter::class)->build($template->fresh('columns'), collect([$ho]))->getActiveSheet();

        // Двухуровневая шапка: группа «Вопросы» в строке 1 над B/C; заголовки — в строке 2
        $this->assertSame('Вопросы', $sheet->getCell('B1')->getValue());
        $this->assertSame('В1', $sheet->getCell('B2')->getValue());
        $this->assertSame('В2', $sheet->getCell('C2')->getValue());
        // Данные с третьей строки
        $this->assertSame('1', $sheet->getCell('A3')->getValue());
        $this->assertSame('4', $sheet->getCell('B3')->getValue()); // question:1
        $this->assertSame('5', $sheet->getCell('C3')->getValue()); // question:2
        $this->assertSame('28', $sheet->getCell('D3')->getValue()); // итог (авто)
        // B1:C1 слиты в группу
        $this->assertContains('B1:C1', array_keys($sheet->getMergeCells()));
    }
}
