<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ротация олимпиадного сезона (ТЗ 4.1):
 *  1) новый учебный год получает статус «Текущий», прежний уходит в архив;
 *  2) выпускники (11 класс) переводятся в статус «Выпустился»;
 *  3) активные ученики 1–10 классов получают инкремент класса обучения +1.
 */
class RotateAcademicYear extends Command
{
    protected $signature = 'season:rotate {name? : Название нового года, напр. 2026/2027} {--force : Без подтверждения}';

    protected $description = 'Закрытие сезона: новый учебный год, выпуск 11-х классов, перевод 1–10 классов';

    public function handle(): int
    {
        $current = AcademicYear::where('status', 'current')->first();

        $name = $this->argument('name') ?: $this->nextYearName($current?->name);
        if (! preg_match('/^\d{4}\/\d{4}$/', (string) $name)) {
            $this->error('Некорректное название года. Ожидается формат «2026/2027».');

            return self::FAILURE;
        }

        if (AcademicYear::where('name', $name)->exists()) {
            $this->error("Учебный год «{$name}» уже существует.");

            return self::FAILURE;
        }

        $graduates = Student::where('status', 'active')->where('real_grade', 11)->count();
        $promoted = Student::where('status', 'active')->whereBetween('real_grade', [1, 10])->count();

        $this->info("Текущий год: ".($current?->name ?? '—').' → архив');
        $this->info("Новый текущий год: $name");
        $this->info("Выпуск 11 класса: $graduates · перевод 1–10 классов: $promoted");

        if (! $this->option('force') && ! $this->confirm('Запустить ротацию сезона?', true)) {
            $this->warn('Отменено.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($name) {
            AcademicYear::where('status', 'current')->update(['status' => 'archive']);
            AcademicYear::create(['name' => $name, 'status' => 'current']);

            // Сначала выпускаем 11-е, затем повышаем 1–10 — иначе бывшие 10-е попадут под выпуск.
            Student::where('status', 'active')->where('real_grade', 11)
                ->update(['status' => 'graduated']);
            Student::where('status', 'active')->whereBetween('real_grade', [1, 10])
                ->increment('real_grade');
        });

        $this->info('Ротация сезона завершена.');

        return self::SUCCESS;
    }

    /** «2025/2026» → «2026/2027»; при отсутствии текущего года — от текущей даты. */
    private function nextYearName(?string $currentName): string
    {
        if ($currentName && preg_match('/^(\d{4})\/(\d{4})$/', $currentName, $m)) {
            return ((int) $m[1] + 1).'/'.((int) $m[2] + 1);
        }

        $y = (int) date('Y');

        return $y.'/'.($y + 1);
    }
}
