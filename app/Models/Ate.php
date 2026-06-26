<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['ate_code', 'name', 'type', 'parent_ate_id'])]
class Ate extends Model
{
    public function msus(): HasMany
    {
        return $this->hasMany(Msu::class);
    }

    public function schools(): HasMany
    {
        return $this->hasMany(School::class);
    }

    /** Зонтичный родитель (для районов Казани — АТЕ «г. Казань»). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_ate_id');
    }

    /** Дочерние АТЕ (районы внутри зонтичного АТЕ). */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_ate_id');
    }
}
