<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ate;
use App\Models\School;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Управление учащимися. Обезличенные по ФЗ-152 записи (anonymized_at, ТЗ 4.9)
 * редактировать нельзя — чтобы не вернуть уничтоженные ПДн. Удаление запрещено при
 * наличии участий (человеко-олимпиад), чтобы не потерять результаты каскадом.
 */
class StudentController extends Controller
{
    private const STATUSES = ['active', 'graduated', 'transferring', 'departed'];

    public function index(Request $request): Response
    {
        $q = $request->query('q');
        $ateId = $request->query('ate_id');
        $schoolId = $request->query('school_id');
        $grade = $request->query('grade');

        $students = Student::query()
            ->with('school:id,short_name')
            ->when($q, fn ($query) => $query->where(fn ($w) => $w
                ->where('fio', 'like', "%$q%")->orWhere('snils', 'like', "%$q%")))
            ->when($ateId, fn ($query) => $query->whereHas('school', fn ($s) => $s->where('ate_id', $ateId)))
            ->when($schoolId, fn ($query) => $query->where('school_id', $schoolId))
            ->when($grade, fn ($query) => $query->where('real_grade', $grade))
            ->orderBy('fio')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Student $s) => [
                'id' => $s->id,
                'fio' => $s->fio,
                'birth_date' => $s->birth_date?->toDateString(),
                'gender' => $s->gender,
                'snils' => $s->snils,
                'real_grade' => $s->real_grade,
                'status' => $s->status,
                'ovz' => $s->ovz,
                'school_id' => $s->school_id,
                'school' => $s->school?->short_name,
                'anonymized' => $s->anonymized_at !== null,
            ]);

        return Inertia::render('Admin/Students/Index', [
            'students' => $students,
            'filters' => ['q' => $q, 'ate_id' => $ateId, 'school_id' => $schoolId, 'grade' => $grade],
            'statuses' => self::STATUSES,
            'ates' => Ate::orderBy('name')->get(['id', 'name']),
            'schools' => School::orderBy('short_name')->get(['id', 'short_name', 'ate_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Student::create($this->validateData($request, null));

        return back()->with('success', 'Учащийся добавлен.');
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        if ($student->anonymized_at !== null) {
            return back()->withErrors(['student' => 'Запись обезличена по ФЗ-152 и не подлежит редактированию.']);
        }

        $student->update($this->validateData($request, $student));

        return back()->with('success', 'Учащийся обновлён.');
    }

    public function destroy(Student $student): RedirectResponse
    {
        if ($student->humanOlympiads()->exists()) {
            return back()->withErrors(['student' => 'Нельзя удалить: есть участия в олимпиадах.']);
        }

        $student->delete();

        return back()->with('success', 'Учащийся удалён.');
    }

    private function validateData(Request $request, ?Student $student): array
    {
        return $request->validate([
            'fio' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date'],
            'gender' => ['nullable', 'in:male,female'],
            // СНИЛС уникален в рамках выбранной ОО (см. миграцию per-school).
            'snils' => [
                'nullable', 'string', 'max:14',
                Rule::unique('students', 'snils')
                    ->where(fn ($q) => $q->where('school_id', $request->input('school_id')))
                    ->ignore($student?->id),
            ],
            'school_id' => ['required', 'exists:schools,id'],
            'real_grade' => ['required', 'integer', 'between:1,11'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'ovz' => ['nullable', 'boolean'],
        ]);
    }
}
