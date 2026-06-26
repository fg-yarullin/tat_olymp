<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\HistoricalStat;
use App\Models\HumanOlympiad;
use App\Models\School;
use Illuminate\Support\Collection;

/**
 * Расчёт рейтингов и аналитики (ТЗ 4.4): локальные рейтинги ОО внутри АТЕ,
 * сквозной рейтинг по денормализованным кодам (городской округ Казани),
 * республиканский срез по учебным годам.
 *
 * Источник данных выбирается по году: архивные (очищенные) сезоны берутся из
 * historical_stats (ТЗ 4.9), текущий сезон — из «живых» human_olympiad.
 * Баллы рейтинга: победитель = 3, призёр = 1.
 */
class RatingService
{
    private const POINTS_WINNER = 3;
    private const POINTS_PRIZEWINNER = 1;

    /** Рейтинг школ внутри одной АТЕ (для координатора АТЕ и сквозного по Казани). */
    public function schoolRatings(string $ateCode, ?string $yearName = null): array
    {
        $yearName ??= $this->currentYearName();

        return $this->enrichAndRank(
            $this->aggregateBySchool($yearName)->where('ate_code', $ateCode)->values()
        );
    }

    /**
     * Республиканский ранжированный рейтинг всех ОО за год с опциональным
     * территориальным срезом (city/rural) — для министерской отчётности (ТЗ 4.5).
     */
    public function republicSchoolRatings(?string $yearName = null, ?string $territory = null): array
    {
        $yearName ??= $this->currentYearName();
        $rows = $this->aggregateBySchool((string) $yearName);

        if (in_array($territory, ['city', 'rural'], true)) {
            $rows = $rows->where('territorial_sign', $territory)->values();
        }

        return $this->enrichAndRank($rows);
    }

    /** Республиканский срез: итоги по каждому учебному году (живые + архивные). */
    public function republicanByYear(): array
    {
        return AcademicYear::orderBy('name')->get()->map(function (AcademicYear $year) {
            $totals = $this->aggregateBySchool($year->name)
                ->reduce(function ($acc, $row) {
                    $acc['participants'] += $row['participants'];
                    $acc['prizes'] += $row['prizes'];
                    $acc['winners'] += $row['winners'];

                    return $acc;
                }, ['participants' => 0, 'prizes' => 0, 'winners' => 0]);

            return [
                'year' => $year->name,
                'status' => $year->status,
                'participants' => $totals['participants'],
                'prizes' => $totals['prizes'],
                'winners' => $totals['winners'],
            ];
        })->values()->all();
    }

    /** Список доступных для аналитики учебных лет. */
    public function availableYears(): array
    {
        return AcademicYear::orderByDesc('name')->pluck('name')->all();
    }

    /**
     * Агрегирует результаты сезона по школам (oo_code): участники, дипломы призёров/победителей.
     * Возвращает коллекцию строк с кодами, названием и территориальным признаком ОО.
     */
    private function aggregateBySchool(string $yearName): Collection
    {
        $historical = HistoricalStat::where('year_name', $yearName)
            ->groupBy('oo_code', 'ate_code', 'msu_code')
            ->selectRaw('oo_code, ate_code, msu_code,
                SUM(total_participants) as participants,
                SUM(total_prizewinner_diplomas) as prizes,
                SUM(total_winner_diplomas) as winners')
            ->get();

        $rows = $historical->isNotEmpty()
            ? $historical->map(fn ($r) => $this->normalize($r))
            : $this->liveAggregate($yearName);

        return $this->withSchoolMeta($rows);
    }

    /** «Живая» агрегация из human_olympiad для текущего/непокрытого архивом года. */
    private function liveAggregate(string $yearName): Collection
    {
        return HumanOlympiad::query()
            ->join('olympiads', 'olympiads.id', '=', 'human_olympiad.olympiad_id')
            ->join('academic_years', 'academic_years.id', '=', 'olympiads.academic_year_id')
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->where('academic_years.name', $yearName)
            ->groupBy('schools.oo_code', 'schools.ate_code', 'schools.msu_code')
            ->selectRaw('schools.oo_code, schools.ate_code, schools.msu_code,
                COUNT(*) as participants,
                SUM(human_olympiad.result_status = ?) as prizes,
                SUM(human_olympiad.result_status = ?) as winners', ['prize_winner', 'winner'])
            ->get()
            ->map(fn ($r) => $this->normalize($r));
    }

    private function normalize($r): array
    {
        return [
            'oo_code' => $r->oo_code,
            'ate_code' => $r->ate_code,
            'msu_code' => $r->msu_code,
            'participants' => (int) $r->participants,
            'prizes' => (int) $r->prizes,
            'winners' => (int) $r->winners,
        ];
    }

    /** Дополняет агрегаты названием и территориальным признаком ОО (из schools по oo_code). */
    private function withSchoolMeta(Collection $rows): Collection
    {
        $meta = School::whereIn('oo_code', $rows->pluck('oo_code'))
            ->get(['oo_code', 'short_name', 'territorial_sign'])
            ->keyBy('oo_code');

        return $rows->map(function ($row) use ($meta) {
            $school = $meta->get($row['oo_code']);
            $row['school'] = $school?->short_name ?? $row['oo_code'];
            $row['territorial_sign'] = $school?->territorial_sign ?? 'city';

            return $row;
        });
    }

    /** Считает баллы, сортирует и проставляет места (rank). */
    private function enrichAndRank(Collection $rows): array
    {
        return $rows
            ->map(function ($row) {
                $row['points'] = $row['winners'] * self::POINTS_WINNER
                    + $row['prizes'] * self::POINTS_PRIZEWINNER;

                return $row;
            })
            ->sortByDesc(fn ($r) => [$r['points'], $r['winners'], $r['participants']])
            ->values()
            ->map(function ($row, $i) {
                $row['rank'] = $i + 1;

                return $row;
            })
            ->all();
    }

    private function currentYearName(): ?string
    {
        return AcademicYear::where('status', 'current')->value('name');
    }
}
