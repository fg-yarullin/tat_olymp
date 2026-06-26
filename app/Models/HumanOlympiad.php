<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'student_id', 'olympiad_id', 'participation_grade',
    'barcode', 'score', 'result_status', 'inclusion_basis', 'scan_path',
    'prev_municipal_winner', 'prev_higher_stage_winner', 'teacher_name', 'teacher_workplace',
    'profile', 'practice_types', 'primary_score', 'appeal_addition',
    'question_scores', 'question_appeals',
])]
class HumanOlympiad extends Model
{
    // Таблица в единственном числе (не human_olympiads)
    protected $table = 'human_olympiad';

    protected function casts(): array
    {
        return [
            'participation_grade' => 'integer',
            'score' => 'float',
            'prev_municipal_winner' => 'boolean',
            'prev_higher_stage_winner' => 'boolean',
            'primary_score' => 'float',
            'appeal_addition' => 'float',
            'final_score' => 'float',
            'question_scores' => 'array',
            'question_appeals' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Итоговый балл МЭ = первичный + добавленное по апелляциям (считается автоматически).
        static::saving(function (HumanOlympiad $ho) {
            if ($ho->primary_score !== null || $ho->appeal_addition !== null) {
                $ho->final_score = (float) ($ho->primary_score ?? 0) + (float) ($ho->appeal_addition ?? 0);
            }
        });
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function olympiad(): BelongsTo
    {
        return $this->belongsTo(Olympiad::class);
    }
}
