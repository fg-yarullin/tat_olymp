<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Olympiad;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Concerns\ParsesImportValues;
use Illuminate\Database\Seeder;

/**
 * Демо-данные школьного этапа: текущий учебный год 2025/2026, три школьные олимпиады
 * (Труд (технология), Астрономия, Биология) с началом 20.10.2025 и закрытием ввода «сейчас»,
 * а также учащиеся из database/seeders/data/students_100001.csv (формат школьного импорта:
 * ФИО;Дата рождения;СНИЛС;Пол;ОВЗ;Класс;Литера). Ученики привязываются к школе демо-оператора.
 */
class DemoSchoolStageSeeder extends Seeder
{
    use ParsesImportValues;

    private const SUBJECTS = ['Труд (технология)', 'Астрономия', 'Биология'];

    public function run(): void
    {
        // 1. Текущий учебный год.
        $year = AcademicYear::updateOrCreate(['name' => '2025/2026'], ['status' => 'current']);

        // 2. Три олимпиады школьного этапа (все классы), начало 20.10.2025, закрытие ввода — сейчас.
        $allGrades = Olympiad::canonicalGrades([]); // «1,2,…,11»
        foreach (self::SUBJECTS as $name) {
            $subject = Subject::firstOrCreate(['name' => $name], ['is_active' => true]);
            Olympiad::updateOrCreate(
                [
                    'academic_year_id' => $year->id,
                    'subject_id' => $subject->id,
                    'stage' => 'school',
                    'grades' => $allGrades,
                ],
                [
                    'subject' => $name,
                    'level' => 'regional',
                    'date_held' => '2025-10-20',
                    'results_deadline' => now(),
                ],
            );
        }

        // 3. Учащиеся из CSV — в школу демо-оператора (иначе пропускаем).
        $schoolId = User::where('email', 'school@tat-olymp.local')->value('school_id')
            ?? School::orderBy('id')->value('id');
        if (! $schoolId) {
            $this->command?->warn('DemoSchoolStageSeeder: нет школ — учащиеся пропущены.');

            return;
        }

        $path = database_path('seeders/data/students_100001.csv');
        if (! is_file($path)) {
            $this->command?->warn("DemoSchoolStageSeeder: файл не найден: {$path}");

            return;
        }

        $created = 0;
        foreach (array_slice($this->readCsv($path), 1) as $r) {
            $fio = trim((string) ($r[0] ?? ''));
            $birth = $this->parseDate((string) ($r[1] ?? ''));
            $snils = $this->normalizeSnils((string) ($r[2] ?? ''));
            $gender = $this->parseGender((string) ($r[3] ?? ''));
            $ovzRaw = trim((string) ($r[4] ?? ''));
            $grade = (int) trim((string) ($r[5] ?? 0));
            $letter = mb_strtoupper(trim((string) ($r[6] ?? ''))) ?: null;

            if ($fio === '' || $birth === null || $grade < 1 || $grade > 11) {
                continue;
            }

            $key = $snils
                ? ['school_id' => $schoolId, 'snils' => $snils]
                : ['school_id' => $schoolId, 'fio' => $fio, 'birth_date' => $birth];

            Student::updateOrCreate($key, [
                'fio' => $fio,
                'birth_date' => $birth,
                'snils' => $snils,
                'gender' => $gender,
                'real_grade' => $grade,
                'class_letter' => $letter,
                'ovz' => $ovzRaw === '' ? null : ($ovzRaw === '1'),
                'school_id' => $schoolId,
                'status' => 'active',
            ]);
            $created++;
        }

        $this->command?->info("DemoSchoolStageSeeder: год 2025/2026, олимпиад ".count(self::SUBJECTS).", учащихся {$created}.");
    }

    /** Чтение CSV: снятие BOM, автоопределение разделителя «;»/«,». */
    private function readCsv(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        $delimiter = null;
        while (($line = fgets($handle)) !== false) {
            if ($delimiter === null) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
                $delimiter = substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
            }
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line, $delimiter);
        }
        fclose($handle);

        return $rows;
    }
}
