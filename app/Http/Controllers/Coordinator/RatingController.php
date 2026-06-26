<?php

namespace App\Http\Controllers\Coordinator;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\RatingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Аналитика и рейтинги (ТЗ 4.4) для координаторов и администратора.
 *  • Муниципальный координатор — рейтинг ОО внутри своей АТЕ;
 *  • Супер-координатор Казани — сквозной рейтинг по денормализованным кодам (много МСУ);
 *  • Республиканский срез по учебным годам доступен всем.
 */
class RatingController extends Controller
{
    public function index(Request $request, RatingService $ratings): Response
    {
        $user = $request->user();
        $ateCode = $user->ate?->ate_code;

        $year = $request->query('year') ?: null;

        return Inertia::render('Coordinator/Ratings', [
            'ate' => $ateCode ? ['code' => $ateCode, 'name' => $user->ate?->name] : null,
            'isKazanCross' => $user->role === UserRole::SuperCoordinator,
            'selectedYear' => $year ?? $ratings->availableYears()[0] ?? null,
            'years' => $ratings->availableYears(),
            'schoolRatings' => $ateCode ? $ratings->schoolRatings($ateCode, $year) : [],
            'republican' => $ratings->republicanByYear(),
        ]);
    }
}
