<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\HistoricalStat;
use App\Models\HumanOlympiad;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Регламент очистки и архивации БД (ТЗ 4.9): 3-летний жизненный цикл данных.
 *  1) сканы работ (scan_path) старше N лет безвозвратно удаляются с диска;
 *  3) ПДн (ФИО/ДР/СНИЛС) обезличиваются в записях старше N лет (ФЗ-152),
 *     НО только после генерации агрегированной исторической статистики.
 *
 * П.2 (логи авторизации и таблицы Anti-DDoS) не реализуется: throttle работает
 * через кэш-драйвер, отдельных таблиц логов/блокировок в схеме нет.
 */
class PurgeOldData extends Command
{
    protected $signature = 'data:purge {--years=3 : Возраст сезона (лет) для очистки} {--force : Без подтверждения}';

    protected $description = 'Очистка сканов и обезличивание ПДн в сезонах старше N лет (ТЗ 4.9)';

    public function handle(): int
    {
        $years = (int) $this->option('years');
        $currentYear = (int) date('Y');

        $allYears = AcademicYear::all();
        // Сезон «истёк», если он в архиве и год его завершения (вторая часть «YYYY/YYYY») старше N лет.
        $purgeable = $allYears->filter(function (AcademicYear $y) use ($currentYear, $years) {
            return $y->status === 'archive'
                && $this->seasonEndYear($y->name) !== null
                && ($currentYear - $this->seasonEndYear($y->name)) >= $years;
        });
        $recentYearIds = $allYears->reject(fn ($y) => $purgeable->contains('id', $y->id))->pluck('id');

        if ($purgeable->isEmpty()) {
            $this->info('Нет сезонов старше '.$years.' лет для очистки.');

            return self::SUCCESS;
        }

        $this->info('Сезоны к очистке: '.$purgeable->pluck('name')->implode(', '));

        if (! $this->option('force') && ! $this->confirm('Запустить очистку? Действие необратимо.', true)) {
            $this->warn('Отменено.');

            return self::SUCCESS;
        }

        $purgeableYearIds = $purgeable->pluck('id');

        DB::transaction(function () use ($purgeable, $purgeableYearIds, $recentYearIds) {
            // 1. Историческая статистика ДО любого уничтожения (идемпотентно по году).
            $stats = 0;
            foreach ($purgeable as $year) {
                if (HistoricalStat::where('year_name', $year->name)->exists()) {
                    continue;
                }
                $stats += $this->aggregateSeason($year);
            }
            $this->info("Сохранено строк исторической статистики: $stats");

            // 2. Удаление файлов сканов истёкших сезонов.
            $scans = $this->purgeScans($purgeableYearIds);
            $this->info("Удалено скан-копий: $scans");

            // 3. Обезличивание ПДн участников, у которых нет записей в актуальных сезонах.
            $anonymized = $this->anonymizeStudents($recentYearIds);
            $this->info("Обезличено участников (ФЗ-152): $anonymized");
        });

        $this->info('Очистка завершена.');

        return self::SUCCESS;
    }

    /** Агрегирует итоги сезона в historical_stats по школе/предмету/этапу. Возвращает число строк. */
    private function aggregateSeason(AcademicYear $year): int
    {
        $rows = HumanOlympiad::query()
            ->join('olympiads', 'olympiads.id', '=', 'human_olympiad.olympiad_id')
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->where('olympiads.academic_year_id', $year->id)
            ->groupBy('schools.ate_code', 'schools.msu_code', 'schools.oo_code', 'olympiads.subject', 'olympiads.stage')
            ->selectRaw('schools.ate_code, schools.msu_code, schools.oo_code, olympiads.subject, olympiads.stage,
                COUNT(*) as total_participants,
                SUM(human_olympiad.result_status = ?) as prizes,
                SUM(human_olympiad.result_status = ?) as winners', ['prize_winner', 'winner'])
            ->get();

        foreach ($rows as $r) {
            HistoricalStat::create([
                'year_name' => $year->name,
                'ate_code' => $r->ate_code,
                'msu_code' => $r->msu_code,
                'oo_code' => $r->oo_code,
                'subject' => $r->subject,
                'stage' => $r->stage,
                'total_participants' => (int) $r->total_participants,
                'total_prizewinner_diplomas' => (int) $r->prizes,
                'total_winner_diplomas' => (int) $r->winners,
            ]);
        }

        return $rows->count();
    }

    /** Удаляет файлы сканов истёкших сезонов и обнуляет scan_path. Возвращает число удалённых. */
    private function purgeScans($purgeableYearIds): int
    {
        $works = HumanOlympiad::query()
            ->whereNotNull('scan_path')
            ->whereHas('olympiad', fn ($q) => $q->whereIn('academic_year_id', $purgeableYearIds))
            ->get();

        $deleted = 0;
        foreach ($works as $work) {
            if (Storage::exists($work->scan_path)) {
                Storage::delete($work->scan_path);
            }
            $work->update(['scan_path' => null]);
            $deleted++;
        }

        return $deleted;
    }

    /** Обезличивает ПДн участников без записей в актуальных сезонах. Возвращает число обезличенных. */
    private function anonymizeStudents($recentYearIds): int
    {
        $students = Student::query()
            ->whereNull('anonymized_at')
            ->whereHas('humanOlympiads') // участвовал хотя бы раз
            ->whereDoesntHave('humanOlympiads', function ($q) use ($recentYearIds) {
                $q->whereHas('olympiad', fn ($o) => $o->whereIn('academic_year_id', $recentYearIds));
            })
            ->get();

        foreach ($students as $student) {
            $student->update([
                'fio' => 'Удалённые данные (ФЗ-152)',
                'birth_date' => '1900-01-01',
                'snils' => null,
                'anonymized_at' => now(),
            ]);
        }

        return $students->count();
    }

    /** «2025/2026» → 2026 (год завершения сезона); null при неверном формате. */
    private function seasonEndYear(string $name): ?int
    {
        return preg_match('/^\d{4}\/(\d{4})$/', $name, $m) ? (int) $m[1] : null;
    }
}
