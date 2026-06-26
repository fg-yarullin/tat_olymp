<?php

namespace App\Http\Controllers\Municipal;

use App\Http\Controllers\Controller;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\School;
use App\Models\SchoolType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Управление школами в рамках своего АТЕ для муниципального координатора и супер-координатора
 * Казани (зонтичный скоуп — все районы Казани). Просмотр / добавление / редактирование; код ОО
 * присваивается автоматически. Удаление недоступно (только админ в справочниках).
 */
class SchoolController extends Controller
{
    public function index(Request $request): Response
    {
        $ateIds = $request->user()->municipalAteScope(); // [ate_id] или районы Казани
        $q = $request->query('school_q');
        $ateFilter = $request->query('school_ate');
        $msuFilter = $request->query('school_msu');

        $schools = School::query()
            ->whereIn('ate_id', $ateIds)
            ->with(['msu:id,name', 'ate:id,name', 'schoolType:id,name'])
            ->when($q, fn ($query) => $query->where(fn ($w) => $w
                ->where('short_name', 'like', "%$q%")->orWhere('oo_code', 'like', "%$q%")))
            ->when($ateFilter && in_array((int) $ateFilter, $ateIds, true), fn ($query) => $query->where('ate_id', $ateFilter))
            ->when($msuFilter, fn ($query) => $query->where('msu_id', $msuFilter))
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

        return Inertia::render('Municipal/Schools/Index', [
            'schools' => $schools,
            'filters' => ['school_q' => $q, 'school_ate' => $ateFilter, 'school_msu' => $msuFilter],
            'ateList' => Ate::whereIn('id', $ateIds)->orderBy('name')->get(['id', 'name']),
            'msuList' => Msu::whereIn('ate_id', $ateIds)->orderBy('msu_code')->get(['id', 'name', 'ate_id']),
            'typeList' => SchoolType::orderBy('digit')->get(['id', 'digit', 'name']),
            'multiAte' => count($ateIds) > 1, // показывать ли фильтр по АТЕ (для супер-координатора)
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateSchool($request);
        $digit = (int) SchoolType::findOrFail($data['school_type_id'])->digit;
        $data['oo_code'] = School::nextOoCode((int) $data['msu_id'], $data['msu_code'], $digit);
        $school = School::create($data);

        return back()->with('success', "Школа добавлена. Код ОО: {$school->oo_code}.");
    }

    public function update(Request $request, School $school): RedirectResponse
    {
        $this->authorizeSchool($request, $school);
        $school->update($this->validateSchool($request));

        return back()->with('success', 'Школа обновлена.');
    }

    /** Валидация + привязки. МСУ должен принадлежать АТЕ из скоупа координатора. */
    private function validateSchool(Request $request): array
    {
        $ateIds = $request->user()->municipalAteScope();

        $data = $request->validate([
            'short_name' => ['required', 'string', 'max:255'],
            'full_name' => ['required', 'string', 'max:1000'],
            'education_level' => ['required', 'integer', 'between:1,3'],
            'territorial_sign' => ['required', Rule::in(['city', 'rural'])],
            'msu_id' => ['required', Rule::exists('msus', 'id')->whereIn('ate_id', $ateIds)],
            'school_type_id' => ['required', 'exists:school_types,id'],
        ]);

        $msu = Msu::with('ate:id,ate_code')->findOrFail($data['msu_id']);
        $data['msu_code'] = $msu->msu_code;
        $data['ate_id'] = $msu->ate_id;
        $data['ate_code'] = $msu->ate?->ate_code;

        return $data;
    }

    private function authorizeSchool(Request $request, School $school): void
    {
        abort_unless(in_array($school->ate_id, $request->user()->municipalAteScope(), true), 403);
    }
}
