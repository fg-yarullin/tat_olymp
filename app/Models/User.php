<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'ate_id', 'school_id', 'is_active', 'ui_preferences'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function ate(): BelongsTo
    {
        return $this->belongsTo(Ate::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** Муниципальные олимпиады, по которым пользователь — председатель комиссии. */
    public function chairedOlympiads(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Olympiad::class, 'commission_chair_olympiad');
    }

    /** Предметы координатора РОЦ (по которым он видит протоколы ШЭ/МЭ). */
    public function rocSubjects(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'roc_coordinator_subject');
    }

    /** Предметы координатора Казани (по которым он ведёт МЭ-контур в АТЕ Казани). */
    public function kazanSubjects(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'kazan_coordinator_subject');
    }

    /** Набор АТЕ координатора (мультивыбор) — для супер-координатора Казани (все районы). */
    public function coordinatorAtes(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Ate::class, 'coordinator_ate');
    }

    /**
     * Набор АТЕ для скоупа МЭ-контура: назначенные АТЕ (pivot coordinator_ate), а при их
     * отсутствии — свой `ate_id` (обычный координатор одного АТЕ). Для листового координатора
     * это просто [ate_id] — поведение не меняется.
     */
    public function municipalAteScope(): array
    {
        $ids = $this->coordinatorAtes()->pluck('ates.id')->map(fn ($v) => (int) $v)->all();
        if ($ids) {
            return array_values(array_unique($ids));
        }

        return $this->ate_id ? [(int) $this->ate_id] : [];
    }

    /**
     * Ограничение по предметам в МЭ-контуре: null — без ограничения (муниципальный координатор),
     * массив id предметов — координатор Казани (видит/ведёт только свои предметы).
     */
    public function municipalSubjectScope(): ?array
    {
        return $this->role === UserRole::KazanSubjectCoordinator
            ? $this->kazanSubjects()->pluck('subjects.id')->map(fn ($v) => (int) $v)->all()
            : null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'ui_preferences' => 'array',
        ];
    }
}
