<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\HumanOlympiad;
use App\Models\Olympiad;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Скоуп по предмету для координатора Казани. Для роли kazan_subject_coordinator проверяет, что
 * предмет олимпиады из URL ({olympiad} или {participation}) входит в назначенные ему предметы —
 * иначе 403. Для остальных ролей (обычный мун. координатор) — пропуск без изменений. Маршруты
 * без олимпиады/участия (списки, управление председателями) скоупятся в контроллерах.
 */
class EnsureKazanSubjectScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->role !== UserRole::KazanSubjectCoordinator) {
            return $next($request);
        }

        $subjectId = $this->routeSubjectId($request);
        if ($subjectId === null) {
            return $next($request); // в маршруте нет олимпиады/участия — скоуп на уровне контроллера
        }

        $allowed = $user->kazanSubjects()->pluck('subjects.id')->map(fn ($v) => (int) $v)->all();
        abort_unless(in_array((int) $subjectId, $allowed, true), 403);

        return $next($request);
    }

    /** Предмет олимпиады, к которой относится запрос (по {olympiad} или {participation}). */
    private function routeSubjectId(Request $request): ?int
    {
        $olympiadParam = $request->route('olympiad');
        if ($olympiadParam !== null) {
            $id = $olympiadParam instanceof Olympiad ? $olympiadParam->subject_id : Olympiad::whereKey($olympiadParam)->value('subject_id');

            return $id !== null ? (int) $id : null;
        }

        $participation = $request->route('participation');
        if ($participation !== null) {
            $ho = $participation instanceof HumanOlympiad ? $participation : HumanOlympiad::find($participation);

            return $ho?->olympiad?->subject_id !== null ? (int) $ho->olympiad->subject_id : null;
        }

        return null;
    }
}
