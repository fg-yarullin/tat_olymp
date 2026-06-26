<?php

namespace App\Support;

use App\Models\HumanOlympiad;

/**
 * Реестр источников значений для колонок протокола (конструктор, Вариант 3).
 * Админ выбирает source_key из options(); экспорт зовёт resolve() для каждой ячейки.
 * Помимо фиксированных ключей поддержаны шаблоны «question:N» и «appeal:N»
 * (балл/добавка по вопросу N из JSON — для предметов с разбивкой по вопросам).
 */
class ProtocolSources
{
    /** Фиксированные источники: ключ => человекочитаемая подпись (для UI конструктора). */
    public const OPTIONS = [
        'row_number' => '№ (порядковый)',
        'school.full_name' => 'Название ОУ',
        'student.snils' => 'СНИЛС',
        'student.last_name' => 'Фамилия',
        'student.first_name' => 'Имя',
        'student.middle_name' => 'Отчество',
        'student.gender' => 'Пол',
        'student.birth_date' => 'Дата рождения',
        'student.ovz' => 'Участник с ОВЗ',
        'student.real_grade' => 'Класс',
        'ho.participation_grade' => 'Класс участия',
        'ho.score' => 'Балл (ШЭ)',
        'ho.primary_score' => 'Первичный балл',
        'ho.appeal_addition' => 'Добавлено по апелляциям',
        'ho.final_score' => 'Итоговый балл',
        'olympiad.max_score' => 'Макс. балл',
        'ho.status' => 'Статус',
        'ho.prev_municipal_winner' => 'Призёр МЭ прошлого года',
        'ho.prev_higher_stage_winner' => 'Призёр рег./респ. этапа прошлого года',
        'ho.teacher_name' => 'Учитель',
        'ho.teacher_workplace' => 'Место работы учителя',
        'ho.profile' => 'Профиль/Направление',
        'ho.practice_types' => 'Виды практик',
    ];

    private const STATUS_RU = [
        'prize_winner' => 'призер', 'winner' => 'победитель', 'participant' => '',
        'appealed' => 'апелляция', 'disqualified' => 'дисквалификация',
    ];

    /** Опции для UI: фиксированные + подсказки по вопросам. */
    public static function options(): array
    {
        return self::OPTIONS + [
            'question:N' => 'Балл по вопросу N (напр. question:1)',
            'appeal:N' => 'Добавка по апелляции к вопросу N (напр. appeal:1)',
        ];
    }

    /** Значение ячейки для данного участия. $rowNumber — порядковый номер строки. */
    public static function resolve(string $key, HumanOlympiad $ho, int $rowNumber): string
    {
        $student = $ho->student;
        [$last, $first, $middle] = self::splitFio($student?->fio ?? '');

        if (str_starts_with($key, 'question:')) {
            return self::jsonValue($ho->question_scores, substr($key, 9));
        }
        if (str_starts_with($key, 'appeal:')) {
            return self::jsonValue($ho->question_appeals, substr($key, 7));
        }

        return match ($key) {
            'row_number' => (string) $rowNumber,
            'school.full_name' => (string) ($student?->school?->full_name ?? ''),
            'student.snils' => (string) ($student?->snils ?? ''),
            'student.last_name' => $last,
            'student.first_name' => $first,
            'student.middle_name' => $middle,
            'student.gender' => ['male' => 'м', 'female' => 'ж'][$student?->gender] ?? '',
            'student.birth_date' => $student?->birth_date?->format('d.m.Y') ?? '',
            'student.ovz' => $student?->ovz ? 'да' : '',
            'student.real_grade' => (string) ($student?->real_grade ?? ''),
            'ho.participation_grade' => (string) $ho->participation_grade,
            'ho.score' => self::num($ho->score),
            'ho.primary_score' => self::num($ho->primary_score),
            'ho.appeal_addition' => self::num($ho->appeal_addition),
            'ho.final_score' => self::num($ho->final_score),
            // Макс. балл — по классу участия (legacy-ключ ho.max_score тоже сюда).
            'olympiad.max_score', 'ho.max_score' => self::num($ho->olympiad?->maxScoreFor($ho->participation_grade)),
            'ho.status' => self::STATUS_RU[$ho->result_status] ?? '',
            'ho.prev_municipal_winner' => $ho->prev_municipal_winner ? 'да' : '',
            'ho.prev_higher_stage_winner' => $ho->prev_higher_stage_winner ? 'да' : '',
            'ho.teacher_name' => (string) ($ho->teacher_name ?? ''),
            'ho.teacher_workplace' => (string) ($ho->teacher_workplace ?? ''),
            'ho.profile' => (string) ($ho->profile ?? ''),
            'ho.practice_types' => (string) ($ho->practice_types ?? ''),
            default => '',
        };
    }

    private static function jsonValue(?array $data, string $key): string
    {
        $value = $data[$key] ?? $data[(int) $key] ?? null;

        return $value === null ? '' : (string) $value;
    }

    private static function num(?float $v): string
    {
        return $v === null ? '' : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private static function splitFio(string $fio): array
    {
        $parts = preg_split('/\s+/', trim($fio), 3);

        return [$parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? ''];
    }
}
