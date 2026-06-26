<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Support\SnilsAudit;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Аудит СНИЛС: дубли (один и тот же СНИЛС у разных учеников/школ) и подозрительные
 * (выдуманные) номера. Помогает находить ошибки в данных ОО.
 */
class SnilsAuditController extends Controller
{
    private const LIMIT = 200;

    public function index(): Response
    {
        // Дубли: один СНИЛС у нескольких учеников.
        $dupSnils = Student::whereNotNull('snils')
            ->selectRaw('snils, COUNT(*) as cnt')
            ->groupBy('snils')->havingRaw('COUNT(*) > 1')
            ->orderByDesc('cnt')->limit(self::LIMIT)->pluck('snils');

        $duplicates = Student::with('school:id,short_name')
            ->whereIn('snils', $dupSnils)->orderBy('snils')->orderBy('fio')->get()
            ->groupBy('snils')
            ->map(fn ($group, $snils) => [
                'snils' => (string) $snils,
                'students' => $group->map(fn (Student $s) => [
                    'id' => $s->id, 'fio' => $s->fio, 'school' => $s->school?->short_name,
                ])->values(),
            ])->values();

        // Подозрительные: фильтруем уникальные СНИЛС по эвристике в PHP.
        $suspiciousSnils = Student::whereNotNull('snils')->distinct()->pluck('snils')
            ->filter(fn ($s) => SnilsAudit::isSuspicious($s))->take(self::LIMIT)->values();

        $suspicious = Student::with('school:id,short_name')
            ->whereIn('snils', $suspiciousSnils)->orderBy('snils')->get()
            ->map(fn (Student $s) => [
                'id' => $s->id, 'fio' => $s->fio, 'snils' => $s->snils,
                'school' => $s->school?->short_name, 'reason' => SnilsAudit::reason($s->snils),
            ]);

        return Inertia::render('Admin/SnilsAudit/Index', [
            'duplicates' => $duplicates,
            'suspicious' => $suspicious,
            'limit' => self::LIMIT,
        ]);
    }
}
