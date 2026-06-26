<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TechPractice;
use App\Models\TechProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Справочник технологии: направления и виды практик (см. olymp.odkzn.ru).
 * Пополняется администратором; используется при ручном вводе результатов по технологии.
 */
class TechReferenceController extends Controller
{
    public function index(): Response
    {
        $profiles = TechProfile::with(['practices' => fn ($q) => $q->ordered()])
            ->ordered()->get()
            ->map(fn (TechProfile $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'position' => $p->position,
                'is_active' => $p->is_active,
                'practices' => $p->practices->map(fn (TechPractice $pr) => [
                    'id' => $pr->id,
                    'code' => $pr->code,
                    'name' => $pr->name,
                    'position' => $pr->position,
                    'is_active' => $pr->is_active,
                ]),
            ]);

        return Inertia::render('Admin/TechReference/Index', ['profiles' => $profiles]);
    }

    public function storeProfile(Request $request): RedirectResponse
    {
        TechProfile::create($this->validateProfile($request, null));

        return back()->with('success', 'Направление добавлено.');
    }

    public function updateProfile(Request $request, TechProfile $profile): RedirectResponse
    {
        $profile->update($this->validateProfile($request, $profile));

        return back()->with('success', 'Направление обновлено.');
    }

    public function destroyProfile(TechProfile $profile): RedirectResponse
    {
        $profile->delete(); // практики удаляются каскадом

        return back()->with('success', 'Направление удалено.');
    }

    public function storePractice(Request $request, TechProfile $profile): RedirectResponse
    {
        $profile->practices()->create($this->validatePractice($request, $profile, null));

        return back()->with('success', 'Вид практики добавлен.');
    }

    public function updatePractice(Request $request, TechPractice $practice): RedirectResponse
    {
        $practice->update($this->validatePractice($request, $practice->profile, $practice));

        return back()->with('success', 'Вид практики обновлён.');
    }

    public function destroyPractice(TechPractice $practice): RedirectResponse
    {
        $practice->delete();

        return back()->with('success', 'Вид практики удалён.');
    }

    private function validateProfile(Request $request, ?TechProfile $profile): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('tech_profiles', 'name')->ignore($profile?->id)],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function validatePractice(Request $request, TechProfile $profile, ?TechPractice $practice): array
    {
        return $request->validate([
            'code' => [
                'nullable', 'string', 'max:20',
                Rule::unique('tech_practices', 'code')
                    ->where('tech_profile_id', $profile->id)
                    ->ignore($practice?->id),
            ],
            'name' => ['required', 'string', 'max:500'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
