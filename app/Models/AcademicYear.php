<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'status'])]
class AcademicYear extends Model
{
    public function olympiads(): HasMany
    {
        return $this->hasMany(Olympiad::class);
    }
}
