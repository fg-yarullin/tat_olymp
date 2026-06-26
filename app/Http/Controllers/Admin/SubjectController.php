<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Справочник предметов. Удаление запрещено, если предмет используется олимпиадами;
 * вместо удаления предмет можно деактивировать (скрыть из выбора).
 */
class SubjectController extends Controller
{
    public function index(): Response
    {
        $subjects = Subject::withCount('olympiads')->ordered()->get()
            ->map(fn (Subject $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'is_active' => $s->is_active,
                'olympiads_count' => $s->olympiads_count,
            ]);

        return Inertia::render('Admin/Subjects/Index', ['subjects' => $subjects]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:subjects,name'],
            'is_active' => ['required', 'boolean'],
        ]);

        Subject::create($data);

        return back()->with('success', 'Предмет добавлен.');
    }

    public function update(Request $request, Subject $subject): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('subjects', 'name')->ignore($subject->id)],
            'is_active' => ['required', 'boolean'],
        ]);

        $subject->update($data);

        return back()->with('success', 'Предмет обновлён.');
    }

    public function destroy(Subject $subject): RedirectResponse
    {
        if ($subject->olympiads()->exists()) {
            return back()->withErrors(['subject' => 'Нельзя удалить: предмет используется в олимпиадах. Деактивируйте его.']);
        }

        $subject->delete();

        return back()->with('success', 'Предмет удалён.');
    }
}
