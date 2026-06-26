<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'fio', 'birth_date', 'gender', 'snils', 'school_id', 'real_grade', 'class_letter',
    'status', 'ovz', 'from_other_region', 'origin_region', 'anonymized_at', 'transfer_settlement', 'transfer_school', 'departed_at',
])]
class Student extends Model
{
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'real_grade' => 'integer',
            'ovz' => 'boolean',
            'from_other_region' => 'boolean',
            'anonymized_at' => 'datetime',
            'departed_at' => 'date',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    /** Имя класса: «7-А», либо «7» если литеры нет. */
    public function className(): string
    {
        return trim((string) $this->class_letter) === ''
            ? (string) $this->real_grade
            : $this->real_grade.'-'.$this->class_letter;
    }

    public function humanOlympiads(): HasMany
    {
        return $this->hasMany(HumanOlympiad::class);
    }
}
