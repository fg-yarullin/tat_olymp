<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Гостевой контур онлайн-показа (ТЗ 4.6, вариант 1): доступ по маркеру в сессии,
 * который ставится после входа гостя по ФИО + дате рождения. Это не учётная запись User.
 */
class EnsureGuestStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('guest_student_id')) {
            return redirect()->route('guest.login');
        }

        return $next($request);
    }
}
