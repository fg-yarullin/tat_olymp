<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Тип ОО (организационно-правовая форма) — 3-я цифра кода ОО. Справочник, управляемый админом.
 */
#[Fillable(['digit', 'name'])]
class SchoolType extends Model
{
    protected function casts(): array
    {
        return ['digit' => 'integer'];
    }

    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }
}
