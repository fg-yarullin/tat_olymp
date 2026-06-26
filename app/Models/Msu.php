<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['msu_code', 'name', 'ate_id'])]
class Msu extends Model
{
    public function ate(): BelongsTo
    {
        return $this->belongsTo(Ate::class);
    }

    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }
}
