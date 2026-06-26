<?php

namespace App\Support;

use App\Models\HumanOlympiad;
use App\Models\Olympiad;

/**
 * Автоматическая расстановка статусов по порогу призёра. Только участия с введённым баллом.
 * Балл ≥ prize_from → призёр, иначе участник. Победитель (макс. балл в школе) ставится
 * вручную школьным оператором и при пересчёте не сбрасывается.
 */
class StatusAssigner
{
    /**
     * Применяет пороги к участиям олимпиады (опц. ограничение по школе).
     *
     * @return int число обновлённых участий
     */
    public static function apply(Olympiad $olympiad, ?int $schoolId = null): int
    {
        $thresholds = $olympiad->thresholdsMap();
        if (empty($thresholds)) {
            return 0;
        }

        $participations = HumanOlympiad::query()
            ->where('human_olympiad.olympiad_id', $olympiad->id)
            ->whereNotNull('human_olympiad.score')
            ->when($schoolId, fn ($q) => $q
                ->whereHas('student', fn ($s) => $s->where('school_id', $schoolId)))
            ->get();

        $updated = 0;
        foreach ($participations as $ho) {
            // Победителя выставляет оператор вручную — не сбрасываем при пересчёте.
            if ($ho->result_status === 'winner') {
                continue;
            }
            $prizeFrom = $thresholds[$ho->participation_grade]['prize_from'] ?? null;
            if ($prizeFrom === null) {
                continue; // для класса без порога не классифицируем
            }

            $status = (float) $ho->score >= $prizeFrom ? 'prize_winner' : 'participant';
            if ($ho->result_status !== $status) {
                $ho->result_status = $status;
                $ho->save();
                $updated++;
            }
        }

        return $updated;
    }
}
