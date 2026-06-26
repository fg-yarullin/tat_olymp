<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\HumanOlympiad;
use App\Models\Olympiad;
use App\Models\Student;
use App\Models\TechPractice;
use App\Support\OlympiadImportHeader;
use App\Support\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * Школьный этап (ТЗ 4.2): скачивание Excel/CSV-шаблона учеников ОО и загрузка баллов.
 * Пакетная выгрузка скан-копий своей ОО одним ZIP (ТЗ 4.6 вариант 2, бэклог §3).
 */
class OlympiadController extends Controller
{
    /** Колонки шаблона ввода баллов (ТЗ 4.2) + поля протокола школьного этапа. */
    private const TEMPLATE_HEADER = [
        'ID', 'ФИО', 'Дата рождения', 'Класс', 'Класс участия', 'Макс. балл', 'Балл',
        'Статус', 'Призер МЭ прошлого года', 'Учитель', 'Место работы учителя',
        'Профиль/Направление', 'Виды практик',
    ];

    /** Текстовый статус из протокола -> result_status. */
    private const STATUS_MAP = [
        'призер' => 'prize_winner', 'призёр' => 'prize_winner',
        'победитель' => 'winner', 'участник' => 'participant',
    ];

    /** Признак олимпиады по технологии (профиль/практики берутся из справочника). */
    private function isTechnology(Olympiad $olympiad): bool
    {
        return $olympiad->subject === 'Технология';
    }

    /**
     * Активные виды практик технологии: код (в нижнем регистре) => [профиль, практика].
     * Источник кодов для шаблона/импорта вместо свободного ввода названий.
     */
    private function techCodeMap(): array
    {
        $map = [];
        $practices = TechPractice::query()
            ->where('is_active', true)
            ->whereHas('profile', fn ($q) => $q->where('is_active', true))
            ->with('profile:id,name')
            ->get();
        foreach ($practices as $pr) {
            $code = trim((string) $pr->code);
            if ($code === '') {
                continue;
            }
            $map[mb_strtolower($code)] = [
                'code' => $code,
                'profile' => $pr->profile?->name,
                'practice' => $pr->label(),
                'practice_name' => $pr->name,
            ];
        }

        return $map;
    }
    public function downloadZipArchive(Request $request, Olympiad $olympiad): BinaryFileResponse|RedirectResponse
    {
        $schoolId = $request->user()->school_id;

        $validated = $request->validate([
            'student_ids' => ['array'],
            'student_ids.*' => ['integer'],
        ]);

        // Жёстко ограничиваем выборку участниками СВОЕЙ школы — даже если в запросе чужие id.
        $works = $olympiad->humanOlympiads()
            ->whereHas('student', fn ($q) => $q->where('school_id', $schoolId))
            ->when(! empty($validated['student_ids']), fn ($q) => $q->whereIn('student_id', $validated['student_ids']))
            ->whereNotNull('scan_path')
            ->with('student:id,fio')
            ->get();

        if ($works->isEmpty()) {
            return back()->withErrors(['student_ids' => 'Нет доступных скан-копий для выгрузки.']);
        }

        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }
        $zipPath = $tmpDir.'/scans_'.$olympiad->id.'_'.uniqid().'.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return back()->withErrors(['student_ids' => 'Не удалось создать архив.']);
        }

        foreach ($works as $work) {
            if (! Storage::exists($work->scan_path)) {
                continue;
            }
            $ext = pathinfo($work->scan_path, PATHINFO_EXTENSION) ?: 'pdf';
            $entryName = $this->sanitize(implode('_', [
                $work->barcode ?: 'no-barcode',
                $work->student->fio,
                $work->participation_grade,
            ])).'.'.$ext;

            $zip->addFromString($entryName, Storage::get($work->scan_path));
        }
        $zip->close();

        $downloadName = 'scans_'.$olympiad->subject.'_'.$olympiad->stage.'.zip';

        return response()
            ->download($zipPath, $this->sanitize($downloadName))
            ->deleteFileAfterSend(true);
    }

    private function sanitize(string $name): string
    {
        // Убираем разделители путей и управляющие символы из имени внутри архива.
        return str_replace(['/', '\\', "\0"], '_', trim($name));
    }

    /**
     * Шаблон ввода баллов (ТЗ 4.2): CSV со списком активных учеников своей ОО.
     * UTF-8 BOM + разделитель «;» — открывается напрямую в русском Excel.
     */
    public function downloadTemplate(Request $request, Olympiad $olympiad): StreamedResponse
    {
        $schoolId = $request->user()->school_id;

        // Выбор учащихся для шаблона: все / по класс-параллели / по классу обучения.
        $students = Student::query()
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->when($request->query('grade'), fn ($q, $g) => $q->where('real_grade', $g))
            ->when($request->query('letter'), fn ($q, $l) => $q->where('class_letter', $l))
            ->orderBy('real_grade')
            ->orderBy('fio')
            ->get();

        // Уже введённые баллы по этой олимпиаде — чтобы шаблон был «продолжаемым».
        $existing = $olympiad->humanOlympiads()
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->keyBy('student_id');

        // Технология: вместо свободных «профиль/практики» — один столбец «Код вида практики».
        $isTech = $this->isTechnology($olympiad);
        $codeMap = $isTech ? $this->techCodeMap() : [];
        $labelToCode = [];
        foreach ($codeMap as $row) {
            $labelToCode[$row['practice']] = $row['code'];
        }
        // Профиль/практика — только для технологии (отдельный столбец «Код вида практики»);
        // у остальных предметов этих колонок в шаблоне нет.
        $common = array_slice(self::TEMPLATE_HEADER, 0, 11);
        $header = $isTech ? [...$common, 'Код вида практики (см. справочник внизу)'] : $common;
        $maxMap = $olympiad->maxScoresMap();

        $olympiad->loadMissing('academicYear');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Результаты');

        // Шапка с названием и кодом олимпиады + строка заголовков колонок.
        $headerRow = OlympiadImportHeader::write($sheet, $olympiad);
        foreach ($header as $i => $title) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$headerRow, $title);
        }

        $statusRu = ['prize_winner' => 'призер', 'winner' => 'победитель', 'participant' => ''];
        $row = $headerRow + 1;
        foreach ($students as $student) {
            $ho = $existing->get($student->id);
            $pgrade = $ho->participation_grade ?? $student->real_grade;
            $base = [
                $student->id,
                $student->fio,
                $student->birth_date?->format('d.m.Y'),
                $student->real_grade,
                $pgrade,
                $maxMap[$pgrade] ?? '',
                $ho->score ?? '',
                $ho ? ($statusRu[$ho->result_status] ?? '') : '',
                $ho && $ho->prev_municipal_winner ? 'да' : '',
                $ho->teacher_name ?? '',
                $ho->teacher_workplace ?? '',
            ];
            $tail = $isTech
                ? [$ho ? ($labelToCode[$ho->practice_types] ?? '') : '']
                : [];
            foreach ([...$base, ...$tail] as $i => $v) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$row, (string) $v, DataType::TYPE_STRING);
            }
            $row++;
        }

        // Легенда кодов (строки с пустым ID импорт пропускает) — справочник для оператора.
        if ($isTech && $codeMap) {
            $row++;
            foreach (['', 'Справочник кодов — Код', 'Направление', 'Вид практики'] as $i => $t) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$row, $t);
            }
            $row++;
            foreach ($codeMap as $cm) {
                foreach (['', $cm['code'], $cm['profile'], $cm['practice_name']] as $i => $t) {
                    $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$row, (string) $t, DataType::TYPE_STRING);
                }
                $row++;
            }
        }
        foreach ($header as $i => $title) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setAutoSize(true);
        }

        // Если шаблон скачивается для конкретного класса — добавляем его в имя файла («…_7-А»).
        $grade = $request->query('grade');
        $letter = $request->query('letter');
        $classPart = $grade ? '_'.$grade.($letter ? '-'.$letter : '') : '';
        $filename = $this->sanitize('shablon_'.$olympiad->subject.'_'.$olympiad->stage.$classPart.'.xlsx');

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Загрузка заполненного шаблона (ТЗ 4.2). «Человеко-олимпиада» создаётся/обновляется
     * только для строк с заполненным баллом; пустые строки игнорируются.
     */
    public function importResults(Request $request, Olympiad $olympiad): RedirectResponse
    {
        $school = $request->user()->school;
        $schoolId = $school->id;

        if (! $olympiad->isEntryOpenFor($school)) {
            return back()->withErrors(['file' => 'Ввод баллов по этой олимпиаде закрыт.']);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,ods', 'max:5120'],
        ]);

        $file = $request->file('file');
        $parsed = OlympiadImportHeader::parse(
            SpreadsheetReader::rows($file->getRealPath(), $file->getClientOriginalExtension())
        );
        // Защита от загрузки в «не ту» олимпиаду: код в файле должен совпасть.
        if ($parsed['code'] !== null && $parsed['code'] !== $olympiad->id) {
            $other = Olympiad::find($parsed['code']);
            $name = $other ? "«{$other->subject}»" : "#{$parsed['code']}";

            return back()->withErrors(['file' => "Файл от другой олимпиады ({$name}), а вы загружаете в «{$olympiad->subject}». Скачайте шаблон нужной олимпиады."]);
        }

        // Номера строк для сообщений об ошибках — как в файле (1-based).
        $rows = [];
        foreach ($parsed['data'] as $k => $r) {
            $rows[$parsed['offset'] + $k + 1] = $r;
        }

        // Допустимые id учеников — только своей ОО (защита от подмены чужих id в файле).
        $ownStudents = Student::where('school_id', $schoolId)->pluck('real_grade', 'id');
        // Классы, по которым проводится олимпиада (ученик может участвовать за свой класс или выше).
        $allowedGrades = $olympiad->gradesArray();
        // Макс. баллы по классам (если заданы администратором) — для проверки превышения.
        $maxMap = $olympiad->maxScoresMap();
        // Технология: коды видов практик из справочника (вместо свободного ввода названий).
        $isTech = $this->isTechnology($olympiad);
        $codeMap = $isTech ? $this->techCodeMap() : [];

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $lineNo => $row) {
            $idRaw = trim((string) ($row[0] ?? ''));
            // Служебные строки (легенда кодов, пустые) — без ID, пропускаем без учёта.
            if (! preg_match('/^\d+$/', $idRaw)) {
                continue;
            }
            $studentId = (int) $idRaw;
            // Колонка «Макс. балл» (индекс 5) — справочная, оператор её не заполняет; балл — индекс 6.
            $scoreRaw = trim((string) ($row[6] ?? ''));

            if ($scoreRaw === '') {
                $skipped++;
                continue;
            }
            if (! $ownStudents->has($studentId)) {
                $errors[] = "строка $lineNo: ученик не найден в вашей ОО";
                continue;
            }

            $scoreNorm = str_replace(',', '.', $scoreRaw);
            if (! is_numeric($scoreNorm) || (float) $scoreNorm < 0) {
                $errors[] = "строка $lineNo: некорректный балл «{$scoreRaw}»";
                continue;
            }
            $score = round((float) $scoreNorm, 2); // балл — до 2 знаков после запятой

            $realGrade = (int) $ownStudents->get($studentId);
            $grade = (int) ($row[4] ?? 0) ?: $realGrade;

            // Ученик участвует за свой класс или выше, в пределах классов олимпиады.
            if ($grade < $realGrade) {
                $errors[] = "строка $lineNo: класс участия ниже класса обучения";
                continue;
            }
            if ($allowedGrades && ! in_array($grade, $allowedGrades, true)) {
                $errors[] = "строка $lineNo: класс $grade вне диапазона олимпиады";
                continue;
            }
            // Балл не может превышать макс. балл класса (если он задан администратором).
            if (isset($maxMap[$grade]) && $score > (float) $maxMap[$grade]) {
                $errors[] = "строка $lineNo: балл $scoreRaw превышает максимум ({$maxMap[$grade]}) для класса $grade";
                continue;
            }

            // Дополнительные поля протокола (необязательные). Макс. балл операторы не вводят.
            $extra = array_filter([
                'result_status' => self::STATUS_MAP[mb_strtolower(trim((string) ($row[7] ?? '')))] ?? null,
                'prev_municipal_winner' => in_array(mb_strtolower(trim((string) ($row[8] ?? ''))), ['да', '1', 'true'], true) ?: null,
                'teacher_name' => trim((string) ($row[9] ?? '')) ?: null,
                'teacher_workplace' => trim((string) ($row[10] ?? '')) ?: null,
            ], fn ($v) => $v !== null);

            // Профиль/вид практики — только для технологии (по коду из справочника).
            if ($isTech) {
                $codeRaw = trim((string) ($row[11] ?? ''));
                if ($codeRaw !== '') {
                    $entry = $codeMap[mb_strtolower($codeRaw)] ?? null;
                    if ($entry === null) {
                        $errors[] = "строка $lineNo: неизвестный код вида практики «{$codeRaw}»";
                        continue;
                    }
                    $extra['profile'] = $entry['profile'];
                    $extra['practice_types'] = $entry['practice'];
                }
            }

            // Ключ участия — (ученик, олимпиада, класс участия): один ученик может
            // участвовать за несколько классов (несколько человеко-олимпиад).
            $ho = HumanOlympiad::where('student_id', $studentId)
                ->where('olympiad_id', $olympiad->id)
                ->where('participation_grade', $grade)
                ->first();

            if ($ho) {
                $ho->update(['score' => $score] + $extra);
                $updated++;
            } else {
                HumanOlympiad::create([
                    'student_id' => $studentId,
                    'olympiad_id' => $olympiad->id,
                    'participation_grade' => $grade,
                    'score' => $score,
                    'result_status' => $extra['result_status'] ?? 'participant',
                ] + $extra);
                $created++;
            }
        }

        $summary = "Импорт завершён: создано $created, обновлено $updated, пропущено $skipped.";
        if ($errors) {
            // Предупреждение (не валидационная ошибка) — закрывается вручную, не блокирует.
            return back()
                ->with('success', $summary)
                ->with('warning', 'Ошибки в строках — '.implode('; ', array_slice($errors, 0, 10)));
        }

        return back()->with('success', $summary);
    }

}
