<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Olympiad;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Общая «Главная» после входа (для всех ролей): олимпиады текущего года, по которым
 * сейчас идёт ввод, с обратным отсчётом до закрытия периода, и ближайшие предстоящие
 * олимпиады по дате проведения. Сроки — общие по олимпиаде (база + продления scope=all),
 * без привязки к школе/АТЕ.
 */
class DashboardController extends Controller
{
    public function index(): Response
    {
        $currentYearId = AcademicYear::where('status', 'current')->value('id');

        $olympiads = Olympiad::query()
            ->when($currentYearId, fn ($q) => $q->where('academic_year_id', $currentYearId))
            ->with('entryExtensions')
            ->get();

        // Олимпиады с открытым окном ввода (первичка или апелляции) + срок закрытия.
        $active = [];
        $closingSoon = 0;
        foreach ($olympiads as $o) {
            $primaryOpen = $o->isEntryOpenGlobal('primary');
            // Апелляции — только этапы с апелляционной фазой (у школьного их нет).
            $appealOpen = $o->stage !== 'school' && $o->isEntryOpenGlobal('appeal');
            if (! $primaryOpen && ! $appealOpen) {
                continue;
            }
            $phase = $primaryOpen ? 'primary' : 'appeal';
            $deadline = $o->entryDeadline($phase, fn ($e) => $e->scope === 'all');
            // В блок сроков берём только олимпиады с заданным сроком (есть что отсчитывать);
            // открытые без срока (напр. «planned» до начала) живут в «Предстоящих».
            if (! $deadline) {
                continue;
            }
            if (now()->diffInHours($deadline, false) <= 24) {
                $closingSoon++;
            }
            $active[] = [
                'id' => $o->id,
                'subject' => $o->subject,
                'stage' => $o->stage,
                'level' => $o->level,
                'grades' => $o->gradesArray(),
                'phase' => $phase,
                'deadline' => $deadline?->toIso8601String(),
            ];
        }

        // Сортировка по близости срока (без срока — в конце).
        usort($active, function ($a, $b) {
            if ($a['deadline'] === $b['deadline']) {
                return strcmp($a['subject'], $b['subject']);
            }
            if ($a['deadline'] === null) {
                return 1;
            }
            if ($b['deadline'] === null) {
                return -1;
            }

            return strcmp($a['deadline'], $b['deadline']);
        });

        // Предстоящие олимпиады по дате проведения (сегодня и позже).
        $upcoming = $olympiads
            ->filter(fn (Olympiad $o) => $o->date_held && $o->date_held->gte(today()))
            ->sortBy('date_held')
            ->take(6)
            ->map(fn (Olympiad $o) => [
                'id' => $o->id,
                'subject' => $o->subject,
                'stage' => $o->stage,
                'level' => $o->level,
                'grades' => $o->gradesArray(),
                'date_held' => $o->date_held->toIso8601String(),
            ])->values();

        return Inertia::render('Dashboard', [
            'active' => $active,
            'upcoming' => $upcoming,
            'counts' => [
                'active' => count($active),
                'closing_soon' => $closingSoon,
                'upcoming' => $upcoming->count(),
            ],
            'has_current_year' => $currentYearId !== null,
        ]);
    }
}
