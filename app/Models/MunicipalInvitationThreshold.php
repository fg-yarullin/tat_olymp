<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Порог приглашения на МЭ (мин. балл ШЭ по классам участия) для пары (олимпиада, АТЕ).
 */
#[Fillable(['olympiad_id', 'ate_id', 'min_scores'])]
class MunicipalInvitationThreshold extends Model
{
    protected function casts(): array
    {
        return ['min_scores' => 'array'];
    }

    public function olympiad(): BelongsTo
    {
        return $this->belongsTo(Olympiad::class);
    }

    public function ate(): BelongsTo
    {
        return $this->belongsTo(Ate::class);
    }
}
