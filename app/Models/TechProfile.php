<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Направление олимпиады по технологии (напр. «Техника, технологии и техническое творчество»).
 */
#[Fillable(['name', 'position', 'is_active'])]
class TechProfile extends Model
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

    public function practices(): HasMany
    {
        return $this->hasMany(TechPractice::class);
    }
}
