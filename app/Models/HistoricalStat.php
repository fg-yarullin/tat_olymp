<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'year_name', 'ate_code', 'msu_code', 'oo_code', 'subject', 'stage',
    'total_participants', 'total_prizewinner_diplomas', 'total_winner_diplomas',
])]
class HistoricalStat extends Model
{
    protected function casts(): array
    {
        return [
            'total_participants' => 'integer',
            'total_prizewinner_diplomas' => 'integer',
            'total_winner_diplomas' => 'integer',
        ];
    }
}
