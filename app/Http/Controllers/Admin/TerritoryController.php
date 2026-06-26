<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\School;
use App\Models\SchoolType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Справочники территориальной структуры: АТЕ → МСУ → ОО (школы).
 * Денормализованные коды в schools (ate_code/msu_code) поддерживаются автоматически:
 * выводятся из выбранного МСУ и каскадно обновляются при смене кода АТЕ/МСУ.
 */
class TerritoryController extends Controller
{
    public function index(Request $request): Response
    {
        $q = $request->query('school_q');
        $schoolAte = $request->query('school_ate');
        $schoolMsu = $request->query('school_msu');

        $schools = School::query()
            ->with(['msu:id,name', 'ate:id,name', 'schoolType:id,name'])
            ->when($q, fn ($query) => $query->where(fn ($w) => $w
                ->where('short_name', 'like', "%$q%")->orWhere('oo_code', 'like', "%$q%")))
            ->when($schoolAte, fn ($query) => $query->where('ate_id', $schoolAte))
            ->when($schoolMsu, fn ($query) => $query->where('msu_id', $schoolMsu))
            ->orderBy('oo_code')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (School $s) => [
                'id' => $s->id,
                'oo_code' => $s->oo_code,
                'short_name' => $s->short_name,
                'full_name' => $s->full_name,
                'education_level' => $s->education_level,
                'territorial_sign' => $s->territorial_sign,
                'msu_id' => $s->msu_id,
                'msu' => $s->msu?->name,
                'ate' => $s->ate?->name,
                'school_type_id' => $s->school_type_id,
                'school_type' => $s->schoolType?->name,
            ]);

        return Inertia::render('Admin/Territory/Index', [
            'ates' => Ate::withCount(['msus', 'schools'])->orderBy('ate_code')->get()
                ->map(fn (Ate $a) => [
                    'id' => $a->id, 'ate_code' => $a->ate_code, 'name' => $a->name, 'type' => $a->type,
                    'msus_count' => $a->msus_count, 'schools_count' => $a->schools_count,
                ]),
            'msus' => Msu::with('ate:id,name')->withCount('schools')->orderBy('msu_code')->get()
                ->map(fn (Msu $m) => [
                    'id' => $m->id, 'msu_code' => $m->msu_code, 'name' => $m->name,
                    'ate_id' => $m->ate_id, 'ate' => $m->ate?->name, 'schools_count' => $m->schools_count,
                ]),
            'schools' => $schools,
            'schoolTypes' => SchoolType::withCount('schools')->orderBy('digit')->get()
                ->map(fn (SchoolType $t) => [
                    'id' => $t->id, 'digit' => $t->digit, 'name' => $t->name, 'schools_count' => $t->schools_count,
                ]),
            'filters' => ['school_q' => $q, 'school_ate' => $schoolAte, 'school_msu' => $schoolMsu],
            'ateList' => Ate::orderBy('ate_code')->get(['id', 'name']),
            'msuList' => Msu::orderBy('msu_code')->get(['id', 'name', 'ate_id']),
            'typeList' => SchoolType::orderBy('digit')->get(['id', 'digit', 'name']),
        ]);
    }

    // ---- АТЕ ----

    public function storeAte(Request $request): RedirectResponse
    {
        Ate::create($this->validateAte($request, null));

        return back()->with('success', 'АТЕ добавлена.');
    }

    public function updateAte(Request $request, Ate $ate): RedirectResponse
    {
        $data = $this->validateAte($request, $ate);

        DB::transaction(function () use ($ate, $data) {
            if ($data['ate_code'] !== $ate->ate_code) {
                School::where('ate_id', $ate->id)->update(['ate_code' => $data['ate_code']]);
            }
            $ate->update($data);
        });

        return back()->with('success', 'АТЕ обновлена.');
    }

    public function destroyAte(Ate $ate): RedirectResponse
    {
        if ($ate->msus()->exists() || $ate->schools()->exists()) {
            return back()->withErrors(['ate' => 'Нельзя удалить АТЕ: есть привязанные МСУ или школы.']);
        }
        $ate->delete();

        return back()->with('success', 'АТЕ удалена.');
    }

    // ---- МСУ ----

    public function storeMsu(Request $request): RedirectResponse
    {
        Msu::create($this->validateMsu($request, null));

        return back()->with('success', 'МСУ добавлено.');
    }

    public function updateMsu(Request $request, Msu $msu): RedirectResponse
    {
        $data = $this->validateMsu($request, $msu);

        DB::transaction(function () use ($msu, $data) {
            if ($data['msu_code'] !== $msu->msu_code) {
                School::where('msu_id', $msu->id)->update(['msu_code' => $data['msu_code']]);
            }
            $msu->update($data);
        });

        return back()->with('success', 'МСУ обновлено.');
    }

    public function destroyMsu(Msu $msu): RedirectResponse
    {
        if ($msu->schools()->exists()) {
            return back()->withErrors(['msu' => 'Нельзя удалить МСУ: есть привязанные школы.']);
        }
        $msu->delete();

        return back()->with('success', 'МСУ удалено.');
    }

    // ---- Школы (ОО) ----

    public function storeSchool(Request $request): RedirectResponse
    {
        $data = $this->validateSchool($request, null);
        // Код ОО присваивается автоматически: msu_code(2) + цифра типа(1) + порядковый(3).
        $digit = (int) SchoolType::findOrFail($data['school_type_id'])->digit;
        $data['oo_code'] = School::nextOoCode((int) $data['msu_id'], $data['msu_code'], $digit);
        $school = School::create($data);

        return back()->with('success', "Школа добавлена. Код ОО: {$school->oo_code}.");
    }

    public function updateSchool(Request $request, School $school): RedirectResponse
    {
        $school->update($this->validateSchool($request, $school));

        return back()->with('success', 'Школа обновлена.');
    }

    public function destroySchool(School $school): RedirectResponse
    {
        if ($school->students()->exists()) {
            return back()->withErrors(['school' => 'Нельзя удалить школу: есть привязанные учащиеся.']);
        }
        $school->delete();

        return back()->with('success', 'Школа удалена.');
    }

    // ---- Валидация ----

    private function validateAte(Request $request, ?Ate $ate): array
    {
        return $request->validate([
            'ate_code' => ['required', 'string', 'max:50', Rule::unique('ates', 'ate_code')->ignore($ate?->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['isolated', 'unified'])],
        ]);
    }

    private function validateMsu(Request $request, ?Msu $msu): array
    {
        return $request->validate([
            'msu_code' => ['required', 'string', 'max:50', Rule::unique('msus', 'msu_code')->ignore($msu?->id)],
            'name' => ['required', 'string', 'max:255'],
            'ate_id' => ['required', 'exists:ates,id'],
        ]);
    }

    /** Денормализованные коды/ate_id выводятся из выбранного МСУ. */
    private function validateSchool(Request $request, ?School $school): array
    {
        // Код ОО не вводится вручную — собирается автоматически (МСУ + тип + порядковый).
        $data = $request->validate([
            'short_name' => ['required', 'string', 'max:255'],
            'full_name' => ['required', 'string', 'max:1000'],
            'education_level' => ['required', 'integer', 'between:1,3'],
            'territorial_sign' => ['required', Rule::in(['city', 'rural'])],
            'msu_id' => ['required', 'exists:msus,id'],
            'school_type_id' => ['required', 'exists:school_types,id'],
        ]);

        $msu = Msu::with('ate:id,ate_code')->findOrFail($data['msu_id']);
        $data['msu_code'] = $msu->msu_code;
        $data['ate_id'] = $msu->ate_id;
        $data['ate_code'] = $msu->ate?->ate_code;

        return $data;
    }

    // ---- Типы ОО (3-я цифра кода) ----

    public function storeSchoolType(Request $request): RedirectResponse
    {
        SchoolType::create($this->validateSchoolType($request, null));

        return back()->with('success', 'Тип ОО добавлен.');
    }

    public function updateSchoolType(Request $request, SchoolType $schoolType): RedirectResponse
    {
        $schoolType->update($this->validateSchoolType($request, $schoolType));

        return back()->with('success', 'Тип ОО обновлён.');
    }

    public function destroySchoolType(SchoolType $schoolType): RedirectResponse
    {
        if ($schoolType->schools()->exists()) {
            return back()->withErrors(['school_type' => 'Нельзя удалить тип: есть школы этого типа.']);
        }
        $schoolType->delete();

        return back()->with('success', 'Тип ОО удалён.');
    }

    private function validateSchoolType(Request $request, ?SchoolType $type): array
    {
        return $request->validate([
            'digit' => ['required', 'integer', 'between:0,9', Rule::unique('school_types', 'digit')->ignore($type?->id)],
            'name' => ['required', 'string', 'max:255'],
        ]);
    }
}
