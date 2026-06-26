<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Вид практики по технологии в рамках направления (напр. «1.1 Практика по ручной деревообработке»).
 */
#[Fillable(['tech_profile_id', 'code', 'name', 'position', 'is_active'])]
class TechPractice extends Model
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /** Подпись для протокола и выпадающих списков: «1.1 Название» или просто название. */
    public function label(): string
    {
        return trim(($this->code ? $this->code.' ' : '').$this->name);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(TechProfile::class, 'tech_profile_id');
    }
}
