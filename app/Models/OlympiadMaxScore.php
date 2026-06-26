<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Максимальный балл по олимпиаде для конкретного класса участия.
 * Заполняется администратором по мере поступления данных от организаторов.
 */
#[Fillable(['olympiad_id', 'grade', 'max_score'])]
class OlympiadMaxScore extends Model
{
    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'max_score' => 'float',
        ];
    }

    public function olympiad(): BelongsTo
    {
        return $this->belongsTo(Olympiad::class);
    }
}
