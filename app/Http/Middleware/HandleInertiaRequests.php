<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                // Гость онлайн-показа (ТЗ 4.6): не User, а маркер ученика в сессии
                'guest' => $request->session()->has('guest_student_id') ? [
                    'student_id' => $request->session()->get('guest_student_id'),
                ] : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                'import_skipped' => $request->session()->get('import_skipped'),
            ],
            // Нужен для нативных form-POST, отдающих файл (Inertia такой ответ не парсит)
            'csrf_token' => csrf_token(),
        ];
    }
}
