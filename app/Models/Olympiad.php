<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'academic_year_id', 'subject', 'subject_id', 'stage', 'level', 'grades', 'question_count', 'date_held', 'published_at',
    'auto_status_mode', 'results_deadline', 'final_results_deadline',
])]
class Olympiad extends Model
{
    /** Полный набор классов (значение «классов участия» по умолчанию). */
    public const ALL_GRADES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];

    /** Максимум продления — часов от срока закрытия (results_deadline). */
    public const MAX_EXTENSION_HOURS = 48;

    /** Название предмета «Технология» (переименован в «Труд (технология)», включая модули «1. ...»/«2. ...»). */
    public const TECHNOLOGY_SUBJECT_PREFIX = 'Труд (технология)';

    /** Признак олимпиады по технологии (профиль/практики берутся из справочника). */
    public function isTechnologySubject(): bool
    {
        return str_starts_with((string) $this->subject, self::TECHNOLOGY_SUBJECT_PREFIX);
    }

    /** Опубликованы ли результаты (открыт онлайн-показ, ввод заблокирован). */
    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    protected function casts(): array
    {
        return [
            'date_held' => 'date',
            'question_count' => 'integer',
            'published_at' => 'datetime',
            'results_deadline' => 'datetime',
            'final_results_deadline' => 'datetime',
        ];
    }

    /**
     * Эффективный срок закрытия ввода для фазы: максимум из базового срока фазы
     * (primary → results_deadline, appeal → final_results_deadline) и применимых
     * продлений этой фазы. $applies решает применимость продления к контексту.
     */
    public function entryDeadline(string $phase, \Closure $applies): ?\Illuminate\Support\Carbon
    {
        $base = $phase === 'appeal' ? $this->final_results_deadline : $this->results_deadline;
        if (! $base) {
            return null;
        }
        $deadline = $base;
        foreach ($this->entryExtensions as $ext) {
            if (($ext->phase ?? 'primary') === $phase && $applies($ext) && $ext->extended_until->gt($deadline)) {
                $deadline = $ext->extended_until;
            }
        }

        return $deadline;
    }

    /**
     * Открыта ли фаза ввода. Первичная: результаты не опубликованы и срок (если задан) не прошёл.
     * Апелляционная: первичный ввод УЖЕ закрыт (по сроку), не опубликовано, и срок апелляций
     * (если задан) не прошёл. Апелляции начинаются сразу после закрытия первичного ввода.
     */
    private function phaseOpen(string $phase, ?\Illuminate\Support\Carbon $phaseDeadline, ?\Illuminate\Support\Carbon $primaryDeadline): bool
    {
        $primaryOpen = ! $this->isPublished()
            && ($primaryDeadline === null || now()->lte($primaryDeadline));

        if ($phase === 'appeal') {
            if ($this->isPublished() || $primaryOpen) {
                return false;
            }

            return $phaseDeadline === null || now()->lte($phaseDeadline);
        }

        return $primaryOpen;
    }

    /** Открыт ли ввод для произвольного контекста (применимость продлений задаёт $applies). */
    public function isEntryOpen(string $phase, \Closure $applies): bool
    {
        return $this->phaseOpen($phase,
            $this->entryDeadline($phase, $applies),
            $this->entryDeadline('primary', $applies));
    }

    /** Срок ввода для школы (контекст ШЭ). */
    public function entryDeadlineFor(School $school, string $phase = 'primary'): ?\Illuminate\Support\Carbon
    {
        return $this->entryDeadline($phase, fn ($ext) => $ext->appliesTo($school));
    }

    /** Открыт ли ввод для школы (фаза primary/appeal). */
    public function isEntryOpenFor(School $school, string $phase = 'primary'): bool
    {
        return $this->isEntryOpen($phase, fn ($ext) => $ext->appliesTo($school));
    }

    /** Срок ввода для АТЕ (контекст МЭ — координатор работает по своему АТЕ). */
    public function entryDeadlineForAte(int $ateId, string $phase = 'primary'): ?\Illuminate\Support\Carbon
    {
        return $this->entryDeadline($phase, fn ($ext) => $ext->appliesToAte($ateId));
    }

    /** Открыт ли ввод для АТЕ (фаза primary/appeal). */
    public function isEntryOpenForAte(int $ateId, string $phase = 'primary'): bool
    {
        return $this->isEntryOpen($phase, fn ($ext) => $ext->appliesToAte($ateId));
    }

    /** Открыт ли ввод по олимпиаде в целом (для председателя комиссии): база + продления scope=all. */
    public function isEntryOpenGlobal(string $phase = 'primary'): bool
    {
        return $this->isEntryOpen($phase, fn ($e) => $e->scope === 'all');
    }

    /** Максимальный балл для конкретного класса участия (из справочника макс. баллов). */
    public function maxScoreFor(int $grade): ?float
    {
        return $this->maxScores->firstWhere('grade', $grade)?->max_score;
    }

    /** Карта «класс → макс. балл» для отдачи в интерфейс. */
    public function maxScoresMap(): array
    {
        return $this->maxScores->pluck('max_score', 'grade')->all();
    }

    /** Карта «класс → [prize_from]» порогов статуса призёра. */
    public function thresholdsMap(): array
    {
        return $this->statusThresholds
            ->mapWithKeys(fn (OlympiadStatusThreshold $t) => [
                $t->grade => ['prize_from' => $t->prize_from],
            ])->all();
    }

    /** Классы участия как массив int. */
    public function gradesArray(): array
    {
        return array_map('intval', array_filter(explode(',', (string) $this->grades)));
    }

    /** Нормализует массив классов к канонической строке «4,5,6». Пусто → все классы. */
    public static function canonicalGrades(array $grades): string
    {
        $clean = array_values(array_unique(array_filter(
            array_map('intval', $grades),
            fn ($g) => $g >= 1 && $g <= 11,
        )));
        sort($clean);

        return implode(',', $clean ?: self::ALL_GRADES);
    }

    /** Разбирает запись классов из файла: «4-6», «4,5,6», «все»/пусто → все классы. */
    public static function parseGradesSpec(string $raw): string
    {
        $raw = trim(mb_strtolower($raw));
        if ($raw === '' || $raw === 'все' || $raw === 'all') {
            return self::canonicalGrades([]);
        }

        $grades = [];
        foreach (preg_split('/[,;]+/', $raw) as $part) {
            $part = trim($part);
            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                for ($g = (int) $m[1]; $g <= (int) $m[2]; $g++) {
                    $grades[] = $g;
                }
            } elseif (is_numeric($part)) {
                $grades[] = (int) $part;
            }
        }

        return self::canonicalGrades($grades);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function subjectRef(): BelongsTo
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function humanOlympiads(): HasMany
    {
        return $this->hasMany(HumanOlympiad::class);
    }

    public function maxScores(): HasMany
    {
        return $this->hasMany(OlympiadMaxScore::class);
    }

    public function statusThresholds(): HasMany
    {
        return $this->hasMany(OlympiadStatusThreshold::class);
    }

    public function entryExtensions(): HasMany
    {
        return $this->hasMany(OlympiadEntryExtension::class);
    }

    /** Председатели комиссии этой олимпиады. */
    public function commissionChairs(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'commission_chair_olympiad');
    }
}
