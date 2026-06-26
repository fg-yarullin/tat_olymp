<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Гостевой вход на онлайн-показ работ (ТЗ 4.6, вариант 1).
 * Авторизация строго по ФИО + дате рождения, без шифров школы.
 * Защита от перебора — throttle:3,10 на POST-маршруте (см. routes/web.php).
 */
class AuthController extends Controller
{
    public function showForm(): Response
    {
        return Inertia::render('Guest/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'fio' => 'required|string|max:255',
            'birth_date' => 'required|date',
        ]);

        $student = Student::query()
            ->where('fio', $validated['fio'])
            ->where('birth_date', $validated['birth_date'])
            ->where('status', 'active')
            ->first();

        if (! $student) {
            return back()->withErrors([
                'fio' => 'Участник не найден. Проверьте ФИО и дату рождения.',
            ]);
        }

        // Защита от фиксации сессии + маркеры гостевого контура
        $request->session()->regenerate();
        $request->session()->put([
            'guest_student_id' => $student->id,
            'auth_type' => 'guest_student',
        ]);

        return redirect()->route('guest.works');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(['guest_student_id', 'auth_type']);

        return redirect()->route('guest.login');
    }
}
