<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Панель обслуживания администратора (ТЗ 5): запуск ротации сезона (ТЗ 4.1)
 * и регламента очистки БД (ТЗ 4.9) из интерфейса. Обёртка над console-командами.
 */
class MaintenanceController extends Controller
{
    public function index(): Response
    {
        $currentYear = AcademicYear::where('status', 'current')->first();
        $thisYear = (int) date('Y');

        $expiredSeasons = AcademicYear::where('status', 'archive')->get()
            ->filter(function (AcademicYear $y) use ($thisYear) {
                return preg_match('/^\d{4}\/(\d{4})$/', $y->name, $m) && ($thisYear - (int) $m[1]) >= 3;
            })
            ->pluck('name')
            ->values();

        return Inertia::render('Admin/Maintenance', [
            'currentYear' => $currentYear?->name,
            'stats' => [
                'archive_years' => AcademicYear::where('status', 'archive')->count(),
                'active_students' => Student::where('status', 'active')->count(),
                'graduating' => Student::where('status', 'active')->where('real_grade', 11)->count(),
                'promoting' => Student::where('status', 'active')->whereBetween('real_grade', [1, 10])->count(),
            ],
            'expiredSeasons' => $expiredSeasons,
        ]);
    }

    public function rotate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'regex:/^\d{4}\/\d{4}$/'],
        ]);

        $exit = Artisan::call('season:rotate', array_filter([
            'name' => $validated['name'] ?? null,
            '--force' => true,
        ], fn ($v) => $v !== null));

        return $this->respond($exit, 'Ротация сезона');
    }

    public function purge(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'years' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $exit = Artisan::call('data:purge', [
            '--years' => $validated['years'] ?? 3,
            '--force' => true,
        ]);

        return $this->respond($exit, 'Очистка БД');
    }

    /** Превращает результат команды в flash-сообщение с её выводом. */
    private function respond(int $exit, string $label): RedirectResponse
    {
        $output = trim(Artisan::output());

        if ($exit === 0) {
            return back()->with('success', "$label: выполнено.\n".$output);
        }

        return back()->withErrors(['maintenance' => "$label: ошибка.\n".$output]);
    }
}
