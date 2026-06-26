<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'stage', 'subject_id'])]
class ProtocolTemplate extends Model
{
    public function columns(): HasMany
    {
        return $this->hasMany(ProtocolColumn::class)->orderBy('position');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** Шаблон под предмет; если нет — общий шаблон этапа (subject_id = null). */
    public static function forStageSubject(string $stage, ?int $subjectId): ?self
    {
        return static::with('columns')
            ->where('stage', $stage)
            ->where(fn ($q) => $q->where('subject_id', $subjectId)->orWhereNull('subject_id'))
            ->orderByRaw('subject_id IS NULL') // 0 (конкретный) раньше 1 (общий)
            ->first();
    }
}
