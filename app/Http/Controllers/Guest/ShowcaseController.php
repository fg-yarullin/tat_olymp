<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\HumanOlympiad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Просмотр работ авторизованным гостем (ТЗ 4.6 / 4.8).
 * Доступ к маршрутам view/scan/appeal дополнительно ограничен окном показа
 * (middleware olympiad.window). Принадлежность работы ученику проверяется здесь.
 */
class ShowcaseController extends Controller
{
    /** Список доступных к показу работ текущего гостя (опубликованы и в пределах 48 ч). */
    public function index(Request $request): Response
    {
        $studentId = $request->session()->get('guest_student_id');

        $works = HumanOlympiad::query()
            ->with('olympiad')
            ->where('student_id', $studentId)
            ->whereHas('olympiad', function ($q) {
                $q->whereNotNull('published_at')
                    ->where('published_at', '>=', now()->subHours(48));
            })
            ->get()
            ->map(fn (HumanOlympiad $ho) => [
                'id' => $ho->id,
                'subject' => $ho->olympiad->subject,
                'stage' => $ho->olympiad->stage,
                'score' => $this->displayScore($ho),
                'result_status' => $ho->result_status,
                'has_scan' => (bool) $ho->scan_path,
                'can_appeal' => $ho->result_status === 'participant',
            ]);

        return Inertia::render('Guest/AvailableWorksList', [
            'works' => $works,
        ]);
    }

    public function view(Request $request, HumanOlympiad $humanOlympiad): Response
    {
        $this->assertOwnership($request, $humanOlympiad);

        return Inertia::render('Guest/WorkView', [
            'work' => [
                'id' => $humanOlympiad->id,
                'subject' => $humanOlympiad->olympiad->subject,
                'stage' => $humanOlympiad->olympiad->stage,
                'score' => $this->displayScore($humanOlympiad),
                'result_status' => $humanOlympiad->result_status,
                'has_scan' => (bool) $humanOlympiad->scan_path,
                'can_appeal' => $humanOlympiad->result_status === 'participant',
                'scan_url' => $humanOlympiad->scan_path ? route('guest.work.scan', $humanOlympiad) : null,
            ],
        ]);
    }

    /** Потоковая отдача скан-копии (inline). */
    public function scan(Request $request, HumanOlympiad $humanOlympiad): StreamedResponse
    {
        $this->assertOwnership($request, $humanOlympiad);

        if (! $humanOlympiad->scan_path || ! Storage::exists($humanOlympiad->scan_path)) {
            abort(404, 'Скан-копия недоступна.');
        }

        return Storage::response($humanOlympiad->scan_path);
    }

    /** Подача апелляции (ТЗ 4.8): перевод участия в статус «подана апелляция». */
    public function submitAppeal(Request $request, HumanOlympiad $humanOlympiad): RedirectResponse
    {
        $this->assertOwnership($request, $humanOlympiad);

        if ($humanOlympiad->result_status !== 'participant') {
            return back()->with('error', 'Апелляция по этой работе недоступна.');
        }

        // NOTE: текст заявления пока негде хранить — модуль обработки апелляций (ТЗ 4.8)
        // потребует отдельной таблицы/поля. Здесь фиксируется только смена статуса.
        $humanOlympiad->update(['result_status' => 'appealed']);

        return back()->with('success', 'Апелляция подана. Ожидайте решения координатора.');
    }

    /** Балл для показа: муниципальный этап — итоговый (первичный + апелляция), иначе балл ШЭ. */
    private function displayScore(HumanOlympiad $ho): ?float
    {
        if ($ho->olympiad->stage === 'municipal') {
            return $ho->final_score ?? $ho->primary_score;
        }

        return $ho->score;
    }

    /** Гость может работать только со своими работами. */
    private function assertOwnership(Request $request, HumanOlympiad $humanOlympiad): void
    {
        if ($humanOlympiad->student_id !== (int) $request->session()->get('guest_student_id')) {
            abort(403, 'Доступ к чужой работе запрещён.');
        }
    }
}
