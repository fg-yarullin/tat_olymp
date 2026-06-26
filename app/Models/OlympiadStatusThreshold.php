<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Порог статусов для класса участия: с какого балла — призёр, с какого — победитель.
 */
#[Fillable(['olympiad_id', 'grade', 'prize_from'])]
class OlympiadStatusThreshold extends Model
{
    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'prize_from' => 'float',
        ];
    }

    public function olympiad(): BelongsTo
    {
        return $this->belongsTo(Olympiad::class);
    }
}
