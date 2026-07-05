<?php

namespace App\Http\Controllers\Commission;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\BulkImport;
use App\Models\HumanOlympiad;
use App\Models\Olympiad;
use App\Support\ChunkedImportService;
use App\Support\ImportResult;
use App\Support\OlympiadImportHeader;
use App\Support\SpreadsheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Кабинет председателя предметной комиссии МЭ: обезличенный ввод первичных результатов
 * по шифру. Доступны только назначенные олимпиады; видны только шифр, класс, класс участия
 * и первичный балл (без ФИО, школы, АТЕ).
 */
class ResultController extends Controller
{
    /** Колонки шаблона массового ввода баллов по шифру. */
    private const SCORE_TEMPLATE_HEADER = ['Шифр', 'Класс участия', 'Макс. балл', 'Балл'];

    public function index(Request $request): Response
    {
        $currentYearId = AcademicYear::where('status', 'current')->value('id');
        $ateIds = $request->user()->municipalAteScope(); // зонтик Казани → районы; иначе [ate_id]

        $olympiads = $request->user()->chairedOlympiads()
            ->where('stage', 'municipal')
            ->when($currentYearId, fn ($q) => $q->where('academic_year_id', $currentYearId))
            ->with('entryExtensions')
            ->withCount(['humanOlympiads as ciphered_count' => fn ($q) => $q->whereNotNull('barcode')
                ->whereHas('student.school', fn ($s) => $s->whereIn('ate_id', $ateIds))])
            ->orderBy('subject')
            ->get()
            ->map(fn (Olympiad $o) => [
                'id' => $o->id,
                'subject' => $o->subject,
                'level' => $o->level,
                'grades' => $o->gradesArray(),
                'entry_open' => $o->isEntryOpenGlobal('primary'),
                'works' => $o->ciphered_count,
            ]);

        return Inertia::render('Commission/Results/Index', ['olympiads' => $olympiads]);
    }

    public function show(Request $request, Olympiad $olympiad): Response
    {
        $this->authorizeChair($request, $olympiad);
        $ateIds = $request->user()->municipalAteScope(); // зонтик Казани → районы; иначе [ate_id]

        $q = trim((string) $request->query('q', ''));
        $grade = $request->filled('grade') ? (int) $request->query('grade') : null;
        $pgrade = $request->filled('pgrade') ? (int) $request->query('pgrade') : null;

        // Только зашифрованные работы своего АТЕ — обезличенно.
        $base = HumanOlympiad::query()
            ->where('olympiad_id', $olympiad->id)
            ->whereNotNull('barcode')
            ->whereHas('student.school', fn ($s) => $s->whereIn('ate_id', $ateIds));

        $gradeOptions = (clone $base)->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->distinct()->orderBy('students.real_grade')->pluck('students.real_grade');
        $pgradeOptions = (clone $base)->distinct()->orderBy('participation_grade')->pluck('participation_grade');

        $works = (clone $base)
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->when($q !== '', fn ($qq) => $qq->where('human_olympiad.barcode', 'like', "%{$q}%"))
            ->when($grade !== null, fn ($qq) => $qq->where('students.real_grade', $grade))
            ->when($pgrade !== null, fn ($qq) => $qq->where('human_olympiad.participation_grade', $pgrade))
            ->orderBy('human_olympiad.barcode')
            ->select('human_olympiad.*', 'students.real_grade')
            ->paginate(30)->withQueryString()
            ->through(fn (HumanOlympiad $h) => [
                'id' => $h->id,
                'cipher' => $h->barcode,
                'real_grade' => $h->real_grade,
                'participation_grade' => $h->participation_grade,
                'primary_score' => $h->primary_score,
                'question_scores' => (object) ($h->question_scores ?? []),
            ]);

        return Inertia::render('Commission/Results/Show', [
            'olympiad' => [
                'id' => $olympiad->id,
                'subject' => $olympiad->subject,
                'level' => $olympiad->level,
                'grades' => $olympiad->gradesArray(),
                'question_count' => $olympiad->question_count,
                'entry_open' => $olympiad->isEntryOpenGlobal('primary'),
                'entry_deadline' => $olympiad->entryDeadline('primary', fn ($e) => $e->scope === 'all')?->toIso8601String(),
                'max_scores' => (object) $olympiad->maxScoresMap(),
            ],
            'works' => $works,
            'filters' => ['q' => $q, 'grade' => $grade, 'pgrade' => $pgrade],
            'grade_options' => $gradeOptions,
            'pgrade_options' => $pgradeOptions,
        ]);
    }

    /** Обезличенный ввод первичного балла по шифру (единым числом или по заданиям — сумма). */
    public function storePrimary(Request $request, HumanOlympiad $participation): RedirectResponse
    {
        $olympiad = $participation->olympiad;
        $this->authorizeChair($request, $olympiad);
        // Работа должна быть зашифрована и принадлежать АТЕ председателя.
        abort_unless($participation->barcode !== null, 404);
        abort_unless(in_array($participation->student?->school?->ate_id, $request->user()->municipalAteScope(), true), 403);

        if (! $olympiad->isEntryOpenGlobal('primary')) {
            return back()->withErrors(['primary_score' => 'Ввод первичных результатов закрыт.']);
        }

        $max = $olympiad->maxScoreFor((int) $participation->participation_grade);
        $count = (int) $olympiad->question_count;

        if ($count > 0) {
            $raw = (array) $request->input('scores', []);
            $clean = [];
            foreach ($raw as $k => $v) {
                $clean[$k] = ($v === null || $v === '') ? null : str_replace(',', '.', (string) $v);
            }
            $request->merge(['scores' => $clean]);
            $request->validate(['scores' => ['array'], 'scores.*' => ['nullable', 'numeric', 'min:0', 'decimal:0,2']]);

            $map = [];
            $sum = 0.0;
            $any = false;
            for ($i = 1; $i <= $count; $i++) {
                $v = $clean[$i] ?? $clean[(string) $i] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                $map[$i] = round((float) $v, 2);
                $sum += $map[$i];
                $any = true;
            }
            $primary = $any ? round($sum, 2) : null;
            if ($max !== null && $primary !== null && $primary > $max) {
                return back()->withErrors(['scores' => 'Сумма баллов по заданиям превышает максимальный балл.']);
            }
            $participation->question_scores = $map ?: null;
            $participation->primary_score = $primary;
            $participation->save();

            return back()->with('success', 'Баллы по заданиям сохранены.');
        }

        if ($request->filled('primary_score')) {
            $request->merge(['primary_score' => str_replace(',', '.', (string) $request->input('primary_score'))]);
        }
        $validated = $request->validate(['primary_score' => ['nullable', 'numeric', 'min:0', 'decimal:0,2']]);
        if ($max !== null && isset($validated['primary_score']) && (float) $validated['primary_score'] > $max) {
            return back()->withErrors(['primary_score' => 'Балл превышает максимальный.']);
        }
        $participation->primary_score = isset($validated['primary_score']) ? round((float) $validated['primary_score'], 2) : null;
        $participation->save();

        return back()->with('success', 'Первичный балл сохранён.');
    }

    /**
     * Массовый импорт первичных баллов из CSV «шифр;балл». Сопоставляет шифр с зашифрованной
     * работой своего АТЕ в назначенной олимпиаде; балл валидируется построчно (числовой, ≤ макс.
     * балла класса участия). Дубли шифров в файле и неизвестные/чужие шифры пропускаются с причиной.
     * При покомандном вводе (question_count>0) балл из файла пишется как итоговый primary_score,
     * покомандная разбивка очищается.
     */
    /** Запуск фонового импорта первичных баллов по шифру (по частям, с прогресс-баром). */
    public function importPrimary(Request $request, Olympiad $olympiad, ChunkedImportService $importer): JsonResponse
    {
        $this->authorizeChair($request, $olympiad);

        if (! $olympiad->isEntryOpenGlobal('primary')) {
            return response()->json(['errors' => ['file' => ['Ввод первичных результатов закрыт.']]], 422);
        }

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,ods', 'max:20480']]);

        $file = $request->file('file');
        $parsed = OlympiadImportHeader::parse(
            SpreadsheetReader::rows($file->getRealPath(), $file->getClientOriginalExtension())
        );
        if ($parsed['code'] !== null && $parsed['code'] !== $olympiad->id) {
            $other = Olympiad::find($parsed['code']);
            $name = $other ? "«{$other->subject}»" : "#{$parsed['code']}";

            return response()->json(['errors' => ['file' => ["Файл от другой олимпиады ({$name}), а вы загружаете в «{$olympiad->subject}». Скачайте шаблон нужной олимпиады."]]], 422);
        }

        $import = $importer->start(
            $request->user()->id, 'commission_primary_scores', 'Первичные баллы (по шифру)',
            ['olympiad_id' => $olympiad->id, 'line_offset' => $parsed['offset']],
            self::SCORE_TEMPLATE_HEADER, $parsed['data'],
        );

        return response()->json(['id' => $import->id, 'total' => $import->total]);
    }

    /** Обработка очередной части строк импорта баллов по шифру. */
    public function importPrimaryChunk(Request $request, BulkImport $bulkImport, ChunkedImportService $importer): JsonResponse
    {
        $this->authorizeImport($request, $bulkImport);

        $olympiad = Olympiad::findOrFail($bulkImport->context['olympiad_id']);
        $ateIds = $request->user()->municipalAteScope(); // зонтик Казани → районы; иначе [ate_id]

        // Карта шифр → работа (только зашифрованные работы своего АТЕ в этой олимпиаде).
        $works = HumanOlympiad::query()
            ->where('olympiad_id', $olympiad->id)
            ->whereNotNull('barcode')
            ->whereHas('student.school', fn ($s) => $s->whereIn('ate_id', $ateIds))
            ->get()
            ->keyBy('barcode');

        // Дубль шифра ловится в пределах одного чанка (типичный файл целиком укладывается в один чанк).
        $seen = [];
        $progress = $importer->processChunk($bulkImport, function (array $row, int $line, ImportResult $result) use ($olympiad, $works, &$seen) {
            $this->applyPrimaryScoreRow($row, $line, $olympiad, $works, $seen, $result);
        });

        return response()->json($progress);
    }

    /** Выгрузка строк с ошибками фонового импорта баллов по шифру. */
    public function importPrimaryErrors(Request $request, BulkImport $bulkImport, ChunkedImportService $importer): StreamedResponse
    {
        $this->authorizeImport($request, $bulkImport);

        return $importer->errorsCsv($bulkImport);
    }

    private function authorizeImport(Request $request, BulkImport $bulkImport): void
    {
        abort_unless($bulkImport->type === 'commission_primary_scores' && $bulkImport->user_id === $request->user()->id, 403);
    }

    /** Обработка одной строки импорта балла по шифру (шифр;…;балл — балл в последней колонке). */
    private function applyPrimaryScoreRow(array $row, int $line, Olympiad $olympiad, $works, array &$seen, ImportResult $result): void
    {
        $cipher = trim((string) ($row[0] ?? ''));
        // Балл — последняя колонка (в шаблоне «Балл», в простом файле «шифр;балл» — вторая).
        $rawScore = trim((string) ($row[count($row) - 1] ?? ''));
        if ($cipher === '') {
            return;
        }
        if (isset($seen[$cipher])) {
            $result->fail($line, 'дубль шифра в файле', $row);

            return;
        }
        $seen[$cipher] = true;

        $work = $works->get($cipher);
        if (! $work) {
            $result->fail($line, 'шифр не найден среди работ вашего АТЕ', $row);

            return;
        }
        $norm = str_replace(',', '.', $rawScore);
        if ($rawScore === '' || ! is_numeric($norm) || (float) $norm < 0) {
            $result->fail($line, "некорректный балл «{$rawScore}»", $row);

            return;
        }
        $score = round((float) $norm, 2);
        $max = $olympiad->maxScoreFor((int) $work->participation_grade);
        if ($max !== null && $score > $max) {
            $result->fail($line, "балл {$rawScore} превышает максимальный ({$max})", $row);

            return;
        }

        $work->primary_score = $score;
        $work->question_scores = null;
        $work->save();
        $result->updated++;
    }

    /** Читает CSV «шифр;балл»: снимает BOM, автоопределяет разделитель, отбрасывает строку-заголовок. */
    /** Шаблон для массового ввода баллов: обезличенные работы своего АТЕ (шифр·класс·класс участия) + пустой «Балл». */
    public function scoreTemplateXlsx(Request $request, Olympiad $olympiad): StreamedResponse
    {
        $this->authorizeChair($request, $olympiad);
        $ateIds = $request->user()->municipalAteScope(); // зонтик Казани → районы; иначе [ate_id]

        $works = HumanOlympiad::query()
            ->where('human_olympiad.olympiad_id', $olympiad->id)
            ->whereNotNull('human_olympiad.barcode')
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->whereIn('schools.ate_id', $ateIds)
            ->orderBy('human_olympiad.barcode')
            ->select('human_olympiad.*')
            ->get();

        $olympiad->loadMissing('academicYear');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Баллы');
        $header = self::SCORE_TEMPLATE_HEADER;
        $headerRow = OlympiadImportHeader::write($sheet, $olympiad);
        foreach ($header as $i => $title) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$headerRow, $title);
        }

        $row = $headerRow + 1;
        foreach ($works as $h) {
            foreach ([$h->barcode, $h->participation_grade, $olympiad->maxScoreFor((int) $h->participation_grade), $h->primary_score] as $i => $v) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$row, (string) $v, DataType::TYPE_STRING);
            }
            $row++;
        }
        foreach ($header as $i => $title) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setAutoSize(true);
        }

        $filename = 'bally_'.preg_replace('/[^\w\-]+/u', '_', $olympiad->subject).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function authorizeChair(Request $request, Olympiad $olympiad): void
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        abort_unless($request->user()->chairedOlympiads()->whereKey($olympiad->id)->exists(), 403);
    }
}
