<?php

namespace Database\Seeders;

use App\Models\ProtocolTemplate;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Шаблоны протоколов «из коробки»: общий ШЭ, общий МЭ, технология (+профиль/практики),
 * астрономия (баллы по вопросам В1…В6). Нестандартные предметы донастраиваются в UI.
 * Идемпотентно по (этап, предмет).
 */
class ProtocolTemplateSeeder extends Seeder
{
    private const SCHOOL_COLUMNS = [
        ['№', 'row_number'],
        ['СНИЛС', 'student.snils'],
        ['Фамилия', 'student.last_name'],
        ['Имя', 'student.first_name'],
        ['Отчество', 'student.middle_name'],
        ['Пол', 'student.gender'],
        ['Дата рождения', 'student.birth_date'],
        ['Участник с ОВЗ', 'student.ovz'],
        ['Класс', 'student.real_grade'],
        ['Класс участия', 'ho.participation_grade'],
        ['Балл', 'ho.score'],
        ['Макс. балл', 'olympiad.max_score'],
        ['Статус', 'ho.status'],
        ['Призер МЭ прошлого года', 'ho.prev_municipal_winner'],
        ['Учитель', 'ho.teacher_name'],
        ['Место работы учителя', 'ho.teacher_workplace'],
    ];

    public function run(): void
    {
        $this->make('Протокол ШЭ (общий)', 'school', null, self::SCHOOL_COLUMNS);
        $this->make('Протокол МЭ (общий)', 'municipal', null, $this->municipalGeneral());

        if ($id = Subject::where('name', 'Технология')->value('id')) {
            $this->make('Протокол МЭ. Технология', 'municipal', $id, $this->technology());
        }
        if ($id = Subject::where('name', 'Астрономия')->value('id')) {
            $this->make('Протокол МЭ. Астрономия', 'municipal', $id, $this->astronomy());
        }
    }

    /** Общий протокол МЭ: первичный/апелляция/итоговый вместо одного балла + Название ОУ. */
    private function municipalGeneral(): array
    {
        return [
            ['№', 'row_number'],
            ['Название ОУ', 'school.full_name'],
            ['СНИЛС', 'student.snils'],
            ['Фамилия', 'student.last_name'],
            ['Имя', 'student.first_name'],
            ['Отчество', 'student.middle_name'],
            ['Пол', 'student.gender'],
            ['Дата рождения', 'student.birth_date'],
            ['Участник с ОВЗ', 'student.ovz'],
            ['Класс', 'student.real_grade'],
            ['Класс участия', 'ho.participation_grade'],
            ['Первичный балл', 'ho.primary_score'],
            ['Добавлено по итогам апелляций', 'ho.appeal_addition'],
            ['Итоговый балл', 'ho.final_score'],
            ['Макс. балл', 'olympiad.max_score'],
            ['Статус', 'ho.status'],
            ['Призер регионального / республиканского этапа прошлого года', 'ho.prev_higher_stage_winner'],
            ['Учитель', 'ho.teacher_name'],
            ['Место работы учителя', 'ho.teacher_workplace'],
        ];
    }

    private function technology(): array
    {
        return array_merge($this->municipalGeneral(), [
            ['Профиль/Направление', 'ho.profile'],
            ['Виды практик', 'ho.practice_types'],
        ]);
    }

    /** Астрономия: первичный балл и апелляции разбиты по вопросам В1…В6 (группы). */
    private function astronomy(): array
    {
        $cols = [
            ['№', 'row_number'],
            ['Название ОУ', 'school.full_name'],
            ['СНИЛС', 'student.snils'],
            ['Фамилия', 'student.last_name'],
            ['Имя', 'student.first_name'],
            ['Отчество', 'student.middle_name'],
            ['Пол', 'student.gender'],
            ['Дата рождения', 'student.birth_date'],
            ['Участник с ОВЗ', 'student.ovz'],
            ['Класс', 'student.real_grade'],
            ['Класс участия', 'ho.participation_grade'],
        ];
        for ($q = 1; $q <= 6; $q++) {
            $cols[] = ['В'.$q, 'question:'.$q, 'Вопросы'];
        }
        $cols[] = ['Первичный балл', 'ho.primary_score'];
        for ($q = 1; $q <= 6; $q++) {
            $cols[] = ['В'.$q, 'appeal:'.$q, 'Добавлено по итогам апелляций'];
        }

        return array_merge($cols, [
            ['Итоговый балл', 'ho.final_score'],
            ['Макс. балл', 'olympiad.max_score'],
            ['Статус', 'ho.status'],
            ['Призер регионального / республиканского этапа прошлого года', 'ho.prev_higher_stage_winner'],
            ['Учитель', 'ho.teacher_name'],
            ['Место работы учителя', 'ho.teacher_workplace'],
        ]);
    }

    /** @param array<int, array{0:string,1:string,2?:string}> $columns */
    private function make(string $name, string $stage, ?int $subjectId, array $columns): void
    {
        $template = ProtocolTemplate::firstOrCreate(
            ['stage' => $stage, 'subject_id' => $subjectId],
            ['name' => $name],
        );

        if ($template->columns()->exists()) {
            return;
        }

        foreach ($columns as $pos => $col) {
            $template->columns()->create([
                'position' => $pos + 1,
                'header' => $col[0],
                'source_key' => $col[1],
                'group_header' => $col[2] ?? null,
            ]);
        }
    }
}
