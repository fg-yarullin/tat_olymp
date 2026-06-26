<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Управление учебными годами. Единовременно «Текущим» может быть только один год —
 * назначение текущего автоматически отправляет остальные в архив (ср. ротация, ТЗ 4.1).
 */
class AcademicYearController extends Controller
{
    public function index(): Response
    {
        $years = AcademicYear::withCount('olympiads')->orderByDesc('name')->get()
            ->map(fn (AcademicYear $y) => [
                'id' => $y->id,
                'name' => $y->name,
                'status' => $y->status,
                'olympiads_count' => $y->olympiads_count,
            ]);

        return Inertia::render('Admin/AcademicYears/Index', ['years' => $years]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'regex:/^\d{4}\/\d{4}$/', 'unique:academic_years,name'],
            'status' => ['required', Rule::in(['current', 'archive'])],
        ]);

        DB::transaction(function () use ($data) {
            if ($data['status'] === 'current') {
                AcademicYear::where('status', 'current')->update(['status' => 'archive']);
            }
            AcademicYear::create($data);
        });

        return back()->with('success', 'Учебный год создан.');
    }

    public function update(Request $request, AcademicYear $academicYear): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'regex:/^\d{4}\/\d{4}$/', Rule::unique('academic_years', 'name')->ignore($academicYear->id)],
            'status' => ['required', Rule::in(['current', 'archive'])],
        ]);

        DB::transaction(function () use ($data, $academicYear) {
            if ($data['status'] === 'current') {
                AcademicYear::where('status', 'current')->where('id', '!=', $academicYear->id)
                    ->update(['status' => 'archive']);
            }
            $academicYear->update($data);
        });

        return back()->with('success', 'Учебный год обновлён.');
    }

    public function makeCurrent(AcademicYear $academicYear): RedirectResponse
    {
        DB::transaction(function () use ($academicYear) {
            AcademicYear::where('status', 'current')->update(['status' => 'archive']);
            $academicYear->update(['status' => 'current']);
        });

        return back()->with('success', "«{$academicYear->name}» назначен текущим.");
    }

    public function destroy(AcademicYear $academicYear): RedirectResponse
    {
        if ($academicYear->olympiads()->exists()) {
            return back()->withErrors(['year' => 'Нельзя удалить год: к нему привязаны олимпиады.']);
        }

        $academicYear->delete();

        return back()->with('success', 'Учебный год удалён.');
    }
}
