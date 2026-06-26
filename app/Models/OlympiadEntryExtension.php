<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Продление ввода результатов для скоупа (все/АТЕ/МСУ/школа) до момента extended_until.
 */
#[Fillable(['olympiad_id', 'phase', 'scope', 'ate_id', 'msu_id', 'school_id', 'extended_until', 'created_by'])]
class OlympiadEntryExtension extends Model
{
    protected function casts(): array
    {
        return ['extended_until' => 'datetime'];
    }

    /** Применимо ли продление к данной школе (контекст ШЭ). */
    public function appliesTo(School $school): bool
    {
        return match ($this->scope) {
            'all' => true,
            'ate' => $this->ate_id === $school->ate_id,
            'msu' => $this->msu_id === $school->msu_id,
            'school' => $this->school_id === $school->id,
            default => false,
        };
    }

    /** Применимо ли продление к АТЕ (контекст МЭ — координатор работает по своему АТЕ). */
    public function appliesToAte(int $ateId): bool
    {
        return $this->scope === 'all' || ($this->scope === 'ate' && $this->ate_id === $ateId);
    }

    public function olympiad(): BelongsTo
    {
        return $this->belongsTo(Olympiad::class);
    }

    public function ate(): BelongsTo
    {
        return $this->belongsTo(Ate::class);
    }

    public function msu(): BelongsTo
    {
        return $this->belongsTo(Msu::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
