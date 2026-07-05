<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Olympiad;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Расписание олимпиад текущего года: начало, закрытие ввода и публикация результатов.
 * Показываются ЗАФИКСИРОВАННЫЕ (базовые) сроки олимпиады — продления (OlympiadEntryExtension)
 * на расписание не влияют (они только операционно открывают/закрывают ввод в кабинетах).
 * Полное доступно рабочим ролям; публичное (краткое: только даты проведения) — без входа.
 */
class ScheduleController extends Controller
{
    /** Полное расписание для авторизованных рабочих ролей. */
    public function index(): Response
    {
        $rows = $this->olympiads()->map(function (Olympiad $o) {
            $twoPhase = $o->stage !== 'school';
            $primary = $this->phaseDate($o, 'primary');
            $publication = $twoPhase ? $this->phaseDate($o, 'appeal') : $primary;

            return array_merge($this->base($o), [
                'two_phase' => $twoPhase,
                'primary_close' => $primary,
                'publication' => $publication,
            ]);
        });

        return Inertia::render('Schedule/Index', ['olympiads' => $rows]);
    }

    /** Публичное краткое расписание (без входа): даты проведения олимпиад. */
    public function publicView(): Response
    {
        $rows = $this->olympiads()->map(fn (Olympiad $o) => $this->base($o));

        return Inertia::render('Schedule/Public', ['olympiads' => $rows]);
    }

    /** Олимпиады текущего года, отсортированные по дате проведения. */
    private function olympiads()
    {
        $currentYearId = AcademicYear::where('status', 'current')->value('id');

        return Olympiad::query()
            ->when($currentYearId, fn ($q) => $q->where('academic_year_id', $currentYearId))
            ->orderBy('date_held')->orderBy('stage')->orderBy('subject')
            ->get();
    }

    /** Базовые поля строки расписания. */
    private function base(Olympiad $o): array
    {
        return [
            'id' => $o->id,
            'subject' => $o->subject,
            'stage' => $o->stage,
            'level' => $o->level,
            'grades' => $o->gradesArray(),
            'start' => $o->date_held?->toIso8601String(),
            'published_at' => $o->published_at?->toIso8601String(),
        ];
    }

    /** Зафиксированный срок фазы (без продлений). */
    private function phaseDate(Olympiad $o, string $phase): array
    {
        $base = $phase === 'appeal' ? $o->final_results_deadline : $o->results_deadline;

        return ['date' => $base?->toIso8601String()];
    }
}
