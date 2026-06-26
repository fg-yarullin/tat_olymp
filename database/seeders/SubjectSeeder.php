<?php

namespace Database\Seeders;

use App\Models\Olympiad;
use App\Models\Subject;
use Illuminate\Database\Seeder;

/**
 * Стандартный перечень предметов всероссийской олимпиады школьников + региональные.
 * Дополнительно связывает уже существующие олимпиады (свободная строка) с справочником.
 */
class SubjectSeeder extends Seeder
{
    private const SUBJECTS = [
        'Математика', 'Русский язык', 'Литература', 'Физика', 'Химия', 'Биология',
        'География', 'История', 'Обществознание', 'Информатика', 'Английский язык',
        'Немецкий язык', 'Французский язык', 'Физическая культура', 'ОБЖ', 'Технология',
        'Экономика', 'Право', 'Экология', 'Астрономия', 'Искусство (МХК)',
        'Татарский язык', 'Татарская литература',
    ];

    public function run(): void
    {
        foreach (self::SUBJECTS as $name) {
            Subject::firstOrCreate(['name' => $name], ['is_active' => true]);
        }

        // Заводим предметы из ранее введённых строк и проставляем olympiads.subject_id.
        Olympiad::whereNull('subject_id')->whereNotNull('subject')
            ->distinct()->pluck('subject')
            ->each(function (string $name) {
                $subject = Subject::firstOrCreate(['name' => $name], ['is_active' => true]);
                Olympiad::where('subject', $name)->whereNull('subject_id')
                    ->update(['subject_id' => $subject->id]);
            });
    }
}
