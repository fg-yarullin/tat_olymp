<?php

namespace App\Http\Controllers\Municipal;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Olympiad;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Председатели предметных комиссий МЭ: плоский список председателей своего АТЕ; один
 * председатель может быть назначен на несколько олимпиад (предметов) — мультивыбор, как у
 * координаторов РОЦ. АТЕ председателя = АТЕ координатора; чужими управлять нельзя.
 *
 * Координатор Казани по предмету видит/ведёт только председателей своих предметов; при правке
 * и удалении сохраняются привязки к олимпиадам других предметов (их ведут другие координаторы).
 */
class ChairController extends Controller
{
    public function index(Request $request): Response
    {
        $ateId = $request->user()->ate_id;
        $currentYearId = AcademicYear::where('status', 'current')->value('id');
        $scope = $request->user()->municipalSubjectScope();

        $chairs = User::query()
            ->where('role', UserRole::CommissionChair)
            ->where('ate_id', $ateId)
            ->when($scope !== null, fn ($q) => $q->whereHas('chairedOlympiads', fn ($o) => $o->whereIn('subject_id', $scope)))
            ->with(['chairedOlympiads' => fn ($q) => $q->where('stage', 'municipal')->orderBy('subject')])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => $u->is_active,
                'olympiads' => $u->chairedOlympiads->map(fn (Olympiad $o) => [
                    'id' => $o->id, 'subject' => $o->subject, 'grades' => $o->gradesArray(),
                ])->values(),
            ]);

        $olympiads = Olympiad::query()
            ->where('stage', 'municipal')
            ->when($currentYearId, fn ($q) => $q->where('academic_year_id', $currentYearId))
            ->when($scope !== null, fn ($q) => $q->whereIn('subject_id', $scope))
            ->orderBy('subject')
            ->get()
            ->map(fn (Olympiad $o) => ['id' => $o->id, 'subject' => $o->subject, 'grades' => $o->gradesArray()]);

        return Inertia::render('Municipal/Chairs/Index', [
            'chairs' => $chairs,
            'olympiads' => $olympiads,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateChair($request, null);

        $chair = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => UserRole::CommissionChair,
            'ate_id' => $request->user()->ate_id,
            'is_active' => $data['is_active'],
        ]);
        $chair->chairedOlympiads()->sync($data['olympiad_ids']);

        return back()->with('success', 'Председатель создан.');
    }

    public function update(Request $request, User $chair): RedirectResponse
    {
        $this->authorizeOwn($request, $chair);
        $data = $this->validateChair($request, $chair);

        $chair->name = $data['name'];
        $chair->email = $data['email'];
        $chair->is_active = $data['is_active'];
        if (! empty($data['password'])) {
            $chair->password = $data['password'];
        }
        $chair->save();

        $ids = $data['olympiad_ids'];
        $scope = $request->user()->municipalSubjectScope();
        if ($scope !== null) {
            // Сохраняем привязки к олимпиадам вне своих предметов (их ведут другие координаторы).
            $outOfScope = $chair->chairedOlympiads()->whereNotIn('subject_id', $scope)->pluck('olympiads.id')->all();
            $ids = array_values(array_unique([...$outOfScope, ...$ids]));
        }
        $chair->chairedOlympiads()->sync($ids);

        return back()->with('success', 'Председатель обновлён.');
    }

    public function destroy(Request $request, User $chair): RedirectResponse
    {
        $this->authorizeOwn($request, $chair);

        $scope = $request->user()->municipalSubjectScope();
        if ($scope !== null) {
            // Снимаем только привязки к своим предметам; если остались чужие — пользователя не удаляем.
            $hasOther = $chair->chairedOlympiads()->whereNotIn('subject_id', $scope)->exists();
            $chair->chairedOlympiads()->detach(
                $chair->chairedOlympiads()->whereIn('subject_id', $scope)->pluck('olympiads.id')->all()
            );
            if ($hasOther) {
                return back()->with('success', 'Председатель снят с ваших предметов.');
            }
        }
        $chair->delete(); // назначения на олимпиады снимаются каскадом

        return back()->with('success', 'Председатель удалён.');
    }

    private function validateChair(Request $request, ?User $chair): array
    {
        $currentYearId = AcademicYear::where('status', 'current')->value('id');
        $scope = $request->user()->municipalSubjectScope();

        $olympiadExists = Rule::exists('olympiads', 'id')->where('stage', 'municipal')->where('academic_year_id', $currentYearId);
        if ($scope !== null) {
            $olympiadExists->whereIn('subject_id', $scope);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($chair?->id)],
            'password' => [$chair ? 'nullable' : 'required', Password::default()],
            'is_active' => ['required', 'boolean'],
            'olympiad_ids' => ['required', 'array', 'min:1'],
            'olympiad_ids.*' => ['integer', $olympiadExists],
        ]);
    }

    private function authorizeOwn(Request $request, User $chair): void
    {
        abort_unless(
            $chair->role === UserRole::CommissionChair && $chair->ate_id === $request->user()->ate_id,
            403,
        );
        // Координатор Казани управляет только председателями своих предметов.
        $scope = $request->user()->municipalSubjectScope();
        if ($scope !== null) {
            abort_unless($chair->chairedOlympiads()->whereIn('subject_id', $scope)->exists(), 403);
        }
    }
}
