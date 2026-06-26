<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'is_active'])]
class Subject extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Сортировка по названию. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    public function olympiads(): HasMany
    {
        return $this->hasMany(Olympiad::class);
    }
}
