<?php

namespace App\Http\Controllers\Roc;

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
 * Управление координаторами РОЦ по предметам (только представитель РОЦ РТ). Координатору
 * назначаются один или несколько предметов — только по ним он видит протоколы ШЭ/МЭ.
 */
class CoordinatorController extends Controller
{
    public function index(): Response
    {
        $coordinators = User::query()
            ->where('role', UserRole::RocSubjectCoordinator)
            ->with('rocSubjects:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_active' => $u->is_active,
                'subjects' => $u->rocSubjects->map(fn (Subject $s) => ['id' => $s->id, 'name' => $s->name])->values(),
            ]);

        return Inertia::render('Roc/Coordinators/Index', [
            'coordinators' => $coordinators,
            'subjects' => Subject::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::default()],
            'is_active' => ['required', 'boolean'],
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => ['integer', Rule::exists('subjects', 'id')],
        ]);

        $coordinator = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => UserRole::RocSubjectCoordinator,
            'is_active' => $data['is_active'],
        ]);
        $coordinator->rocSubjects()->sync($data['subject_ids']);

        return back()->with('success', 'Координатор РОЦ создан.');
    }

    public function update(Request $request, User $coordinator): RedirectResponse
    {
        $this->authorizeCoordinator($coordinator);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($coordinator->id)],
            'password' => ['nullable', Password::default()],
            'is_active' => ['required', 'boolean'],
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => ['integer', Rule::exists('subjects', 'id')],
        ]);

        $coordinator->name = $data['name'];
        $coordinator->email = $data['email'];
        $coordinator->is_active = $data['is_active'];
        if (! empty($data['password'])) {
            $coordinator->password = $data['password'];
        }
        $coordinator->save();
        $coordinator->rocSubjects()->sync($data['subject_ids']);

        return back()->with('success', 'Координатор РОЦ обновлён.');
    }

    public function destroy(User $coordinator): RedirectResponse
    {
        $this->authorizeCoordinator($coordinator);
        $coordinator->delete(); // привязки предметов снимаются каскадом

        return back()->with('success', 'Координатор РОЦ удалён.');
    }

    private function authorizeCoordinator(User $coordinator): void
    {
        abort_unless($coordinator->role === UserRole::RocSubjectCoordinator, 403);
    }
}
