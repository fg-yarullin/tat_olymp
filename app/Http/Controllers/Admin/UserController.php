<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Ate;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Управление учётными записями (ТЗ 3): админ создаёт координаторов и операторов
 * (самостоятельная регистрация запрещена), назначает их на АТЕ/ОО, активирует.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $q = $request->query('q');
        $ateId = $request->query('ate_id');

        $users = User::query()
            // Председателей комиссий и координаторов РОЦ администратор не ведёт — ими управляют
            // муниципальный координатор и представитель РОЦ соответственно.
            ->whereNotIn('role', [UserRole::CommissionChair, UserRole::RocSubjectCoordinator, UserRole::KazanSubjectCoordinator])
            ->with(['ate:id,name', 'school:id,short_name', 'coordinatorAtes:id,name'])
            ->when($q, fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%")))
            // Район координатора — его ate_id (или набор АТЕ); район оператора — через его школу
            ->when($ateId, fn ($query) => $query->where(fn ($w) => $w
                ->where('ate_id', $ateId)
                ->orWhereHas('coordinatorAtes', fn ($a) => $a->where('ates.id', $ateId))
                ->orWhereHas('school', fn ($s) => $s->where('ate_id', $ateId))))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role?->value,
                'role_label' => $u->role?->label(),
                'ate' => $u->role === UserRole::SuperCoordinator && $u->coordinatorAtes->isNotEmpty()
                    ? $u->coordinatorAtes->pluck('name')->join(', ')
                    : $u->ate?->name,
                'ate_ids' => $u->coordinatorAtes->pluck('id')->values(),
                'school' => $u->school?->short_name,
                'is_active' => $u->is_active,
            ]);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => ['q' => $q, 'ate_id' => $ateId],
            'roles' => collect(UserRole::cases())
                ->reject(fn ($r) => in_array($r, [UserRole::CommissionChair, UserRole::RocSubjectCoordinator, UserRole::KazanSubjectCoordinator], true))
                ->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()])
                ->values(),
            'ates' => Ate::orderBy('ate_code')->get(['id', 'name']),
            'schools' => School::orderBy('short_name')->get(['id', 'short_name', 'ate_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request, null);
        $data['password'] = Hash::make($data['password']);
        $data['email_verified_at'] = now();

        $user = User::create($data);
        $this->syncCoordinatorAtes($user, $request);

        return back()->with('success', 'Пользователь создан.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validateData($request, $user);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        // Нельзя снять активность с самого себя — чтобы админ не заблокировал свой вход.
        if ($user->id === $request->user()->id && ! $data['is_active']) {
            return back()->withErrors(['is_active' => 'Нельзя деактивировать собственную учётную запись.']);
        }

        $user->update($data);
        $this->syncCoordinatorAtes($user, $request);

        return back()->with('success', 'Пользователь обновлён.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'Нельзя удалить собственную учётную запись.']);
        }

        $user->delete();

        return back()->with('success', 'Пользователь удалён.');
    }

    /** Валидация + нормализация привязок по роли (лишние FK обнуляются). */
    private function validateData(Request $request, ?User $user): array
    {
        $role = $request->input('role');

        $isSuper = $role === UserRole::SuperCoordinator->value;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'nullable', Password::default()],
            // Председателей комиссий и координаторов РОЦ создаёт не администратор.
            'role' => ['required', Rule::enum(UserRole::class), Rule::notIn([UserRole::CommissionChair->value, UserRole::RocSubjectCoordinator->value, UserRole::KazanSubjectCoordinator->value])],
            'is_active' => ['required', 'boolean'],
            // Супер-координатор Казани ведёт НАБОР АТЕ (мультивыбор); обычный координатор — один.
            'ate_id' => [
                Rule::requiredIf(in_array($role, [
                    UserRole::MunicipalCoordinator->value, UserRole::CommissionChair->value,
                ], true)),
                'nullable', 'exists:ates,id',
            ],
            'ate_ids' => [Rule::requiredIf($isSuper), 'array'],
            'ate_ids.*' => ['integer', 'exists:ates,id'],
            'school_id' => [
                Rule::requiredIf($role === UserRole::SchoolOperator->value),
                'nullable', 'exists:schools,id',
            ],
        ]);

        // Нормализация привязок по роли.
        if ($role === UserRole::SchoolOperator->value) {
            $data['ate_id'] = null;
        } elseif ($isSuper) {
            // Первый из выбранных АТЕ — «домашний» (для сроков/конфига); набор — в pivot.
            $data['ate_id'] = (int) ($data['ate_ids'][0] ?? null) ?: null;
            $data['school_id'] = null;
        } elseif (in_array($role, [UserRole::MunicipalCoordinator->value, UserRole::CommissionChair->value], true)) {
            $data['school_id'] = null;
        } else { // admin / roc_representative
            $data['ate_id'] = null;
            $data['school_id'] = null;
        }
        unset($data['ate_ids'], $data['ate_ids.*']);

        return $data;
    }

    /** Синхронизирует набор АТЕ супер-координатора (pivot); у остальных — очищает. */
    private function syncCoordinatorAtes(User $user, Request $request): void
    {
        $user->coordinatorAtes()->sync(
            $user->role === UserRole::SuperCoordinator ? array_map('intval', (array) $request->input('ate_ids', [])) : []
        );
    }
}
