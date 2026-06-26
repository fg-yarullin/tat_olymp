<?php

namespace App\Http\Middleware;

use App\Models\HumanOlympiad;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Окно онлайн-показа работ (ТЗ 4.6, вариант 1):
 *   - результаты опубликованы и прошло не более 48 часов с момента публикации;
 *   - текущее время по татарстанскому поясу в интервале 07:30–19:30.
 * Метки времени в БД хранятся в UTC, дневное окно считается в Europe/Moscow.
 */
class EnsureWithinShowcaseWindow
{
    private const TIMEZONE = 'Europe/Moscow'; // Татарстан, UTC+3

    private const OPEN = '07:30';

    private const CLOSE = '19:30';

    private const VISIBILITY_HOURS = 48;

    public function handle(Request $request, Closure $next): Response
    {
        $humanOlympiad = $request->route('humanOlympiad');

        if (! $humanOlympiad instanceof HumanOlympiad) {
            abort(404);
        }

        $olympiad = $humanOlympiad->olympiad;

        // Результаты должны быть опубликованы
        if ($olympiad->published_at === null) {
            abort(403, 'Результаты ещё не опубликованы.');
        }

        // 48 часов с момента публикации
        if ($olympiad->published_at->copy()->addHours(self::VISIBILITY_HOURS)->isPast()) {
            abort(403, 'Окно показа работы закрыто (прошло более 48 часов с публикации).');
        }

        // Дневной интервал 07:30–19:30 по татарстанскому времени
        $local = Carbon::now(self::TIMEZONE);
        $open = $local->copy()->setTimeFromTimeString(self::OPEN);
        $close = $local->copy()->setTimeFromTimeString(self::CLOSE);

        if ($local->lt($open) || $local->gt($close)) {
            abort(403, 'Просмотр работ доступен ежедневно с 07:30 до 19:30.');
        }

        return $next($request);
    }
}
