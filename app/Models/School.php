<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'oo_code', 'short_name', 'full_name', 'education_level', 'school_type_id',
    'territorial_sign', 'msu_id', 'msu_code', 'ate_id', 'ate_code',
])]
class School extends Model
{
    protected function casts(): array
    {
        return [
            'education_level' => 'integer',
        ];
    }

    public function msu(): BelongsTo
    {
        return $this->belongsTo(Msu::class);
    }

    public function schoolType(): BelongsTo
    {
        return $this->belongsTo(SchoolType::class);
    }

    public function ate(): BelongsTo
    {
        return $this->belongsTo(Ate::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * Следующий код ОО: msu_code(2) + цифра типа(1) + порядковый(3). Порядковый — следующий за
     * наибольшим из последних трёх цифр среди всех школ этого МСУ (вне зависимости от типа).
     */
    public static function nextOoCode(int $msuId, string $msuCode, int $digit): string
    {
        $maxSeq = (int) static::where('msu_id', $msuId)
            ->whereRaw('CHAR_LENGTH(oo_code) = 6')
            ->selectRaw('MAX(CAST(SUBSTRING(oo_code, 4, 3) AS UNSIGNED)) m')
            ->value('m');

        $seq = $maxSeq + 1;
        do {
            $code = $msuCode.$digit.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
            $seq++;
        } while (static::where('oo_code', $code)->exists());

        return $code;
    }
}
