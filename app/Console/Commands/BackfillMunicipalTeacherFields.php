<?php

namespace App\Console\Commands;

use App\Models\HumanOlympiad;
use App\Models\Olympiad;
use Illuminate\Console\Command;

/**
 * Разовая коррекция данных: участия МЭ, приглашённые до того, как учитель/технология
 * (teacher_name, teacher_workplace, profile, practice_types) стали переноситься со школьного
 * этапа при формировании состава, остались с пустыми полями. Заполняет только пустые поля
 * из соответствующей записи ШЭ того же ученика/класса/предмета/года — уже заполненные вручную
 * значения не трогает.
 */
class BackfillMunicipalTeacherFields extends Command
{
    protected $signature = 'municipal:backfill-teacher-fields {--olympiad= : ID одной олимпиады МЭ (по умолчанию — все)}';

    protected $description = 'Заполняет пустые teacher_name/teacher_workplace/profile/practice_types на МЭ данными со школьного этапа';

    public function handle(): int
    {
        $olympiadId = $this->option('olympiad');

        $query = HumanOlympiad::query()
            ->whereHas('olympiad', fn ($q) => $q->where('stage', 'municipal'))
            ->where(function ($q) {
                $q->whereNull('teacher_name')->orWhereNull('teacher_workplace')
                    ->orWhereNull('profile')->orWhereNull('practice_types');
            })
            ->when($olympiadId, fn ($q) => $q->where('olympiad_id', $olympiadId))
            ->with('olympiad');

        $rows = $query->get();
        $updated = 0;

        foreach ($rows as $ho) {
            /** @var Olympiad $olympiad */
            $olympiad = $ho->olympiad;

            $she = HumanOlympiad::query()
                ->join('olympiads', 'olympiads.id', '=', 'human_olympiad.olympiad_id')
                ->where('olympiads.stage', 'school')
                ->where('olympiads.subject_id', $olympiad->subject_id)
                ->where('olympiads.academic_year_id', $olympiad->academic_year_id)
                ->where('human_olympiad.student_id', $ho->student_id)
                ->where('human_olympiad.participation_grade', $ho->participation_grade)
                ->select('human_olympiad.*')
                ->first();

            if (! $she) {
                continue;
            }

            $ho->teacher_name ??= $she->teacher_name;
            $ho->teacher_workplace ??= $she->teacher_workplace;
            $ho->profile ??= $she->profile;
            $ho->practice_types ??= $she->practice_types;

            if ($ho->isDirty()) {
                $ho->save();
                $updated++;
            }
        }

        $this->info("Проверено участий: {$rows->count()}. Обновлено: {$updated}.");

        return self::SUCCESS;
    }
}
