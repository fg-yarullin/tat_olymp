<?php

namespace App\Http\Controllers\CityCenter;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Управление ответственными по предметам в Казани (только супер-координатор). Координатору
 * назначаются один или несколько предметов — по ним он получает права муниципального
 * координатора внутри АТЕ Казани. АТЕ наследуется от супер-координатора (= АТЕ Казани).
 */
class SubjectCoordinatorController extends Controller
{
    public function index(Request $request): Response
    {
        $ateId = $request->user()->ate_id;

        $coordinators = User::query()
            ->where('role', UserRole::KazanSubjectCoordinator)
            ->where('ate_id', $ateId)
            ->with('kazanSubjects:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => $u->is_active,
                'subjects' => $u->kazanSubjects->map(fn (Subject $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            ]);

        return Inertia::render('CityCenter/SubjectCoordinators/Index', [
            'coordinators' => $coordinators,
            'subjects' => Subject::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request, null);

        $coordinator = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => UserRole::KazanSubjectCoordinator,
            'ate_id' => $request->user()->ate_id, // АТЕ Казани (от супер-координатора)
            'is_active' => $data['is_active'],
        ]);
        $coordinator->kazanSubjects()->sync($data['subject_ids']);

        return back()->with('success', 'Ответственный по предмету создан.');
    }

    public function update(Request $request, User $coordinator): RedirectResponse
    {
        $this->authorizeCoordinator($request, $coordinator);
        $data = $this->validateData($request, $coordinator);

        $coordinator->name = $data['name'];
        $coordinator->email = $data['email'];
        $coordinator->is_active = $data['is_active'];
        if (! empty($data['password'])) {
            $coordinator->password = $data['password'];
        }
        $coordinator->save();
        $coordinator->kazanSubjects()->sync($data['subject_ids']);

        return back()->with('success', 'Ответственный по предмету обновлён.');
    }

    public function destroy(Request $request, User $coordinator): RedirectResponse
    {
        $this->authorizeCoordinator($request, $coordinator);
        $coordinator->delete(); // привязки предметов снимаются каскадом

        return back()->with('success', 'Ответственный по предмету удалён.');
    }

    private function validateData(Request $request, ?User $coordinator): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($coordinator?->id)],
            'password' => [$coordinator ? 'nullable' : 'required', Password::default()],
            'is_active' => ['required', 'boolean'],
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => ['integer', Rule::exists('subjects', 'id')],
        ]);
    }

    private function authorizeCoordinator(Request $request, User $coordinator): void
    {
        abort_unless(
            $coordinator->role === UserRole::KazanSubjectCoordinator && $coordinator->ate_id === $request->user()->ate_id,
            403,
        );
    }
}
