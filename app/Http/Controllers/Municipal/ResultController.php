<?php

namespace App\Http\Controllers\Municipal;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\BulkImport;
use App\Models\HumanOlympiad;
use App\Models\MunicipalInvitationThreshold;
use App\Models\Olympiad;
use App\Models\ProtocolTemplate;
use App\Models\School;
use App\Models\Student;
use App\Support\ChunkedImportService;
use App\Support\ImportResult;
use App\Support\OlympiadImportHeader;
use App\Support\ScanArchiveImporter;
use App\Support\SpreadsheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Кабинет муниципального координатора (АТЕ): олимпиады муниципального этапа
 * и формирование состава участников. Координатор работает только со школами своего ate_id.
 * Ввод баллов и протокол — отдельные шаги; здесь только состав.
 */
class ResultController extends Controller
{
    /** Результаты ШЭ/прошлого МЭ, дающие право участвовать в МЭ. */
    private const QUALIFYING = ['winner', 'prize_winner'];

    /** Колонки шаблона массового ввода первичных баллов МЭ. */
    private const SCORE_TEMPLATE_HEADER = ['ID', 'ФИО', 'Школа', 'Класс', 'Класс участия', 'Макс. балл', 'Балл'];

    public function index(Request $request): Response
    {
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope(); // зонтик Казани → набор районов; иначе [ate_id]
        $currentYearId = AcademicYear::where('status', 'current')->value('id');
        $subjectScope = $request->user()->municipalSubjectScope(); // null — все; иначе предметы Казани

        $olympiads = Olympiad::query()
            ->where('stage', 'municipal')
            ->when($currentYearId, fn ($q) => $q->where('academic_year_id', $currentYearId))
            ->when($subjectScope !== null, fn ($q) => $q->whereIn('subject_id', $subjectScope))
            ->with('entryExtensions')
            ->withCount(['humanOlympiads as participants_count' => fn ($q) => $q
                ->whereHas('student.school', fn ($s) => $s->whereIn('ate_id', $ateIds))])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Olympiad $o) => [
                'id' => $o->id,
                'subject' => $o->subject,
                'level' => $o->level,
                'grades' => $o->gradesArray(),
                // Состав открыт, пока открыт первичный ввод (по сроку).
                'compose_open' => $o->isEntryOpenForAte($ateId, 'primary'),
                'entry_open' => $o->isEntryOpenForAte($ateId, 'primary'),
                'appeal_open' => $o->isEntryOpenForAte($ateId, 'appeal'),
                'participants' => $o->participants_count,
            ]);

        return Inertia::render('Municipal/Results/Index', ['olympiads' => $olympiads]);
    }

    /**
     * Список участников МЭ этого АТЕ с поиском/фильтрами/пагинацией (общий для обеих страниц).
     * $onlyScored — оставить только участников с введённым первичным баллом (на странице
     * результатов после закрытия ввода скрываем неявившихся).
     */
    private function participantList(Request $request, Olympiad $olympiad, array $ateIds, bool $onlyScored = false): array
    {
        $q = trim((string) $request->query('q', ''));
        $grade = $request->filled('grade') ? (int) $request->query('grade') : null;
        $pgrade = $request->filled('pgrade') ? (int) $request->query('pgrade') : null;
        $schoolFilter = $request->filled('school') ? (int) $request->query('school') : null;

        $base = $this->participantBaseQuery($olympiad, $ateIds)
            ->when($onlyScored, fn ($qq) => $qq->whereNotNull('human_olympiad.primary_score'));

        $gradeOptions = (clone $base)->distinct()->orderBy('students.real_grade')->pluck('students.real_grade');
        $pgradeOptions = (clone $base)->distinct()->orderBy('human_olympiad.participation_grade')->pluck('human_olympiad.participation_grade');
        $schoolOptions = (clone $base)->distinct()->orderBy('schools.short_name')
            ->pluck('schools.short_name', 'schools.id')
            ->map(fn ($name, $id) => ['id' => $id, 'short_name' => $name])->values();

        $participants = $this->applyParticipantFilters(clone $base, $q, $grade, $pgrade, $schoolFilter)
            ->with(['student:id,fio,real_grade,school_id,from_other_region,origin_region', 'student.school:id,short_name'])
            ->select('human_olympiad.*')
            ->orderBy('human_olympiad.participation_grade')->orderBy('students.fio')
            ->paginate(25)->withQueryString()
            ->through(fn (HumanOlympiad $h) => [
                'id' => $h->id,
                'student_id' => $h->student_id,
                'fio' => $h->student?->fio,
                'school' => $h->student?->school?->short_name,
                'real_grade' => $h->student?->real_grade,
                'participation_grade' => $h->participation_grade,
                'cipher' => $h->barcode,
                'primary_score' => $h->primary_score,
                'appeal_addition' => $h->appeal_addition,
                'final_score' => $h->final_score,
                'question_scores' => (object) ($h->question_scores ?? []),
                'question_appeals' => (object) ($h->question_appeals ?? []),
                'inclusion_basis' => $h->inclusion_basis,
                'from_other_region' => (bool) $h->student?->from_other_region,
                'origin_region' => $h->student?->origin_region,
            ]);

        return [
            'participants' => $participants,
            'filters' => ['q' => $q, 'grade' => $grade, 'pgrade' => $pgrade, 'school' => $schoolFilter],
            'grade_options' => $gradeOptions,
            'pgrade_options' => $pgradeOptions,
            'school_options' => $schoolOptions,
        ];
    }

    /** Применяет фильтры списка участников (поиск/классы/школа) к переданному билдеру. */
    private function applyParticipantFilters($query, string $q, ?int $grade, ?int $pgrade, ?int $schoolFilter)
    {
        return $query
            ->when($q !== '', fn ($qq) => $qq->where('students.fio', 'like', "%{$q}%"))
            ->when($grade !== null, fn ($qq) => $qq->where('students.real_grade', $grade))
            ->when($pgrade !== null, fn ($qq) => $qq->where('human_olympiad.participation_grade', $pgrade))
            ->when($schoolFilter !== null, fn ($qq) => $qq->where('students.school_id', $schoolFilter));
    }

    /** Базовый запрос участников МЭ этого АТЕ по олимпиаде (без фильтров/пагинации). */
    private function participantBaseQuery(Olympiad $olympiad, array $ateIds)
    {
        return HumanOlympiad::query()
            ->where('human_olympiad.olympiad_id', $olympiad->id)
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->whereIn('schools.ate_id', $ateIds);
    }

    /**
     * Массовое удаление участников МЭ: по выбранным ID, по текущему фильтру состава,
     * либо полностью весь состав по этой олимпиаде (в рамках зоны координатора).
     */
    public function bulkDestroy(Request $request, Olympiad $olympiad): RedirectResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        if (! $olympiad->isEntryOpenForAte($ateId, 'primary')) {
            return back()->withErrors(['participation' => 'Формирование состава по этой олимпиаде закрыто.']);
        }

        $validated = $request->validate([
            'mode' => ['required', Rule::in(['selected', 'filtered', 'all'])],
            'ids' => ['required_if:mode,selected', 'array'],
            'ids.*' => ['integer'],
        ]);

        if ($validated['mode'] === 'selected') {
            $count = $this->participantBaseQuery($olympiad, $ateIds)
                ->whereIn('human_olympiad.id', $validated['ids'])
                ->delete();
        } elseif ($validated['mode'] === 'filtered') {
            $q = trim((string) $request->input('q', ''));
            $grade = $request->filled('grade') ? (int) $request->input('grade') : null;
            $pgrade = $request->filled('pgrade') ? (int) $request->input('pgrade') : null;
            $schoolFilter = $request->filled('school') ? (int) $request->input('school') : null;
            $count = $this->applyParticipantFilters(
                $this->participantBaseQuery($olympiad, $ateIds), $q, $grade, $pgrade, $schoolFilter
            )->delete();
        } else {
            $count = $this->participantBaseQuery($olympiad, $ateIds)->delete();
        }

        // Если удалили все строки текущей страницы — переходим на последнюю существующую
        // страницу того же вида, иначе координатор увидит пустой список при живой пагинации.
        $q = trim((string) $request->input('q', ''));
        $grade = $request->filled('grade') ? (int) $request->input('grade') : null;
        $pgrade = $request->filled('pgrade') ? (int) $request->input('pgrade') : null;
        $schoolFilter = $request->filled('school') ? (int) $request->input('school') : null;
        $remaining = $this->applyParticipantFilters(
            $this->participantBaseQuery($olympiad, $ateIds), $q, $grade, $pgrade, $schoolFilter
        )->count();
        $lastPage = max(1, (int) ceil($remaining / 25));
        $requestedPage = max(1, (int) $request->input('page', 1));
        $targetPage = min($requestedPage, $lastPage);

        return redirect()->route('municipal.results.show', array_filter([
            'olympiad' => $olympiad->id,
            'q' => $q !== '' ? $q : null,
            'grade' => $grade,
            'pgrade' => $pgrade,
            'school' => $schoolFilter,
            'page' => $targetPage > 1 ? $targetPage : null,
        ], fn ($v) => $v !== null))->with('success', "Удалено участников: {$count}.");
    }

    /** Страница «Состав МЭ» — формирование списка приглашённых. */
    public function show(Request $request, Olympiad $olympiad): Response
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        // Ученики школ этого АТЕ — для ручного добавления.
        $students = Student::query()
            ->where('status', 'active')
            ->whereHas('school', fn ($s) => $s->whereIn('ate_id', $ateIds))
            ->with('school:id,short_name')
            ->orderBy('real_grade')->orderBy('fio')
            ->get(['id', 'fio', 'real_grade', 'school_id'])
            ->map(fn (Student $s) => [
                'id' => $s->id,
                'fio' => $s->fio,
                'real_grade' => $s->real_grade,
                'school' => $s->school?->short_name,
            ]);

        // Данные ШЭ своего АТЕ для реактивных счётчиков режимов формирования:
        // total по классу участия (для top-N) и баллы призёров/победителей (для порога).
        $sheRows = $this->schoolStageBaseQuery($olympiad, $ateIds)
            ->whereNotNull('human_olympiad.score')
            ->select('human_olympiad.participation_grade', 'human_olympiad.score', 'human_olympiad.result_status', 'students.school_id')
            ->get();
        $sheTotalByGrade = [];
        $sheQualByGrade = [];
        $sheBySchoolGrade = [];
        foreach ($sheRows as $r) {
            $g = (int) $r->participation_grade;
            $sheTotalByGrade[$g] = ($sheTotalByGrade[$g] ?? 0) + 1;
            if (in_array($r->result_status, ['winner', 'prize_winner'], true)) {
                $sheQualByGrade[$g][] = (float) $r->score;
            }
            $sc = (int) $r->school_id;
            $sheBySchoolGrade[$sc][$g] = ($sheBySchoolGrade[$sc][$g] ?? 0) + 1;
        }
        $sheCountsBySchoolGrade = [];
        foreach ($sheBySchoolGrade as $sc => $byGrade) {
            $sheCountsBySchoolGrade[$sc] = (object) $byGrade;
        }

        return Inertia::render('Municipal/Results/Show', array_merge(
            $this->participantList($request, $olympiad, $ateIds),
            [
                'olympiad' => [
                    'id' => $olympiad->id,
                    'subject' => $olympiad->subject,
                    'level' => $olympiad->level,
                    'grades' => $olympiad->gradesArray(),
                    'compose_open' => $olympiad->isEntryOpenForAte($ateId, 'primary'),
                    // Шифры можно править, пока олимпиада не опубликована.
                    'cipher_editable' => ! $olympiad->isPublished(),
                ],
                'invitation_thresholds' => (object) (MunicipalInvitationThreshold::where('olympiad_id', $olympiad->id)
                    ->where('ate_id', $ateId)->value('min_scores') ?? []),
                'she_max_scores' => (object) $this->sheMaxScores($olympiad),
                'she_total_by_grade' => (object) $sheTotalByGrade,
                'she_qualifying_scores_by_grade' => (object) $sheQualByGrade,
                'she_counts_by_school_grade' => (object) $sheCountsBySchoolGrade,
                'students' => $students,
                'schools' => School::whereIn('ate_id', $ateIds)->orderBy('short_name')->get(['id', 'short_name']),
            ],
        ));
    }

    /** Страница «Результаты МЭ» — ввод первичных баллов и добавок по апелляциям. */
    public function entry(Request $request, Olympiad $olympiad): Response
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        // После закрытия первичного ввода скрываем неявившихся (без первичного балла).
        $primaryOpen = $olympiad->isEntryOpenForAte($ateId, 'primary');

        return Inertia::render('Municipal/Results/Entry', array_merge(
            $this->participantList($request, $olympiad, $ateIds, ! $primaryOpen),
            [
                'olympiad' => [
                    'id' => $olympiad->id,
                    'subject' => $olympiad->subject,
                    'level' => $olympiad->level,
                    'grades' => $olympiad->gradesArray(),
                    'question_count' => $olympiad->question_count,
                    'entry_open' => $olympiad->isEntryOpenForAte($ateId, 'primary'),
                    'entry_deadline' => $olympiad->entryDeadlineForAte($ateId, 'primary')?->toIso8601String(),
                    'appeal_open' => $olympiad->isEntryOpenForAte($ateId, 'appeal'),
                    'appeal_deadline' => $olympiad->entryDeadlineForAte($ateId, 'appeal')?->toIso8601String(),
                    'max_scores' => (object) $olympiad->maxScoresMap(),
                    'has_protocol_template' => ProtocolTemplate::forStageSubject('municipal', $olympiad->subject_id) !== null,
                ],
            ],
        ));
    }

    private const BASIS_RU = [
        'school_stage' => 'Призёр/победитель ШЭ',
        'prev_municipal' => 'Прошлогодний призёр МЭ',
        'petition' => 'По ходатайству',
    ];

    /** Выгрузка списка приглашённых МЭ этого АТЕ в XLSX. */
    public function invitedXlsx(Request $request, Olympiad $olympiad): StreamedResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        $participants = HumanOlympiad::query()
            ->where('human_olympiad.olympiad_id', $olympiad->id)
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->whereIn('schools.ate_id', $ateIds)
            ->with(['student:id,fio,birth_date,real_grade,school_id,from_other_region,origin_region', 'student.school:id,short_name'])
            ->select('human_olympiad.*')
            ->orderBy('human_olympiad.participation_grade')->orderBy('students.fio')
            ->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Приглашённые МЭ');
        $header = ['№', 'ФИО', 'Дата рождения', 'Школа', 'Класс', 'Класс участия', 'Основание', 'Из другого региона'];
        foreach ($header as $i => $title) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $title);
        }

        $row = 2;
        foreach ($participants as $n => $h) {
            $s = $h->student;
            $external = $s?->from_other_region ? ($s->origin_region ?: 'да') : '';
            $values = [
                $n + 1,
                $s?->fio,
                $s?->birth_date?->format('d.m.Y'),
                $s?->school?->short_name,
                $s?->real_grade,
                $h->participation_grade,
                self::BASIS_RU[$h->inclusion_basis] ?? '',
                $external,
            ];
            foreach ($values as $i => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$row, $value);
            }
            $row++;
        }
        foreach ($header as $i => $title) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setAutoSize(true);
        }

        $filename = 'priglashennye_ME_'.preg_replace('/[^\w\-]+/u', '_', $olympiad->subject).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Макс. баллы ШЭ того же предмета и года (карта класс→балл) — подсказка для порога. */
    private function sheMaxScores(Olympiad $olympiad): array
    {
        $map = [];
        $sheOlympiads = Olympiad::where('subject_id', $olympiad->subject_id)->where('stage', 'school')
            ->where('academic_year_id', $olympiad->academic_year_id)->with('maxScores')->get();
        foreach ($sheOlympiads as $o) {
            foreach ($o->maxScoresMap() as $g => $v) {
                $map[$g] = $v;
            }
        }

        return $map;
    }

    /**
     * Формирование состава МЭ. Приоритет: 1) призёры МЭ прошлого года (флаг
     * prev_municipal_winner на ШЭ + реальные прошлогодние записи) — включаются всегда;
     * 2) победители/призёры ШЭ текущего года с баллом ≥ порога (порог задаёт координатор АТЕ).
     */
    public function composeFromStages(Request $request, Olympiad $olympiad): RedirectResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        if (! $olympiad->isEntryOpenForAte($ateId, 'primary')) {
            return back()->withErrors(['compose' => 'Формирование состава по этой олимпиаде закрыто.']);
        }

        // Порог приглашения (мин. балл ШЭ по классам) — задаёт координатор, сохраняем.
        $request->validate([
            'thresholds' => ['nullable', 'array'],
            'thresholds.*' => ['nullable', 'numeric', 'min:0'],
        ]);
        $thresholds = [];
        foreach ((array) $request->input('thresholds', []) as $g => $v) {
            if (in_array((int) $g, $olympiad->gradesArray(), true) && $v !== null && $v !== '') {
                $thresholds[(int) $g] = (float) $v;
            }
        }
        MunicipalInvitationThreshold::updateOrCreate(
            ['olympiad_id' => $olympiad->id, 'ate_id' => $ateId],
            ['min_scores' => $thresholds ?: null],
        );

        // Источники: ШЭ текущего года и МЭ прошлых лет того же предмета.
        $sheIds = Olympiad::where('subject_id', $olympiad->subject_id)->where('stage', 'school')
            ->where('academic_year_id', $olympiad->academic_year_id)->pluck('id');
        $prevMunIds = Olympiad::where('subject_id', $olympiad->subject_id)->where('stage', 'municipal')
            ->where('academic_year_id', '<', $olympiad->academic_year_id)->pluck('id');

        $existing = HumanOlympiad::where('olympiad_id', $olympiad->id)
            ->get(['student_id', 'participation_grade'])
            ->map(fn ($h) => $h->student_id.':'.$h->participation_grade)->flip();

        $added = 0;
        $add = function (int $studentId, int $grade, string $basis) use (&$existing, &$added, $olympiad) {
            if (! in_array($grade, $olympiad->gradesArray(), true) || $existing->has($studentId.':'.$grade)) {
                return;
            }
            HumanOlympiad::create([
                'student_id' => $studentId, 'olympiad_id' => $olympiad->id,
                'participation_grade' => $grade, 'result_status' => 'participant', 'inclusion_basis' => $basis,
            ]);
            $existing->put($studentId.':'.$grade, true);
            $added++;
        };

        // 1а. Первый приоритет: флаг «призёр МЭ прошлого года» на записях ШЭ (любой результат ШЭ).
        $flagged = HumanOlympiad::whereIn('olympiad_id', $sheIds)
            ->where('prev_municipal_winner', true)
            ->whereHas('student.school', fn ($s) => $s->whereIn('ate_id', $ateIds))
            ->get(['student_id', 'participation_grade']);
        foreach ($flagged as $c) {
            $add($c->student_id, (int) $c->participation_grade, 'prev_municipal');
        }
        // 1б. Реальные записи прошлогоднего МЭ (победители/призёры).
        $prev = HumanOlympiad::whereIn('olympiad_id', $prevMunIds)
            ->whereIn('result_status', self::QUALIFYING)
            ->whereHas('student.school', fn ($s) => $s->whereIn('ate_id', $ateIds))
            ->get(['student_id', 'participation_grade']);
        foreach ($prev as $c) {
            $add($c->student_id, (int) $c->participation_grade, 'prev_municipal');
        }
        // 2. Победители/призёры ШЭ текущего года с баллом ≥ порога (если порог задан для класса).
        $sheWinners = HumanOlympiad::whereIn('olympiad_id', $sheIds)
            ->whereIn('result_status', self::QUALIFYING)
            ->whereHas('student.school', fn ($s) => $s->whereIn('ate_id', $ateIds))
            ->get(['student_id', 'participation_grade', 'score']);
        foreach ($sheWinners as $c) {
            $grade = (int) $c->participation_grade;
            $min = $thresholds[$grade] ?? null;
            if ($min !== null && ($c->score === null || (float) $c->score < $min)) {
                continue; // ниже порога — не приглашаем
            }
            $add($c->student_id, $grade, 'school_stage');
        }

        return back()->with('success', $added > 0
            ? "Добавлено участников: {$added}."
            : 'Новых участников по критериям не найдено.');
    }

    /**
     * Формирование состава «первые N по группам классов»: координатор задаёт группы классов
     * участия (напр. 7-8 и 9-11) и N на группу. В каждой группе берутся N участников ШЭ своего
     * АТЕ с наибольшим баллом (рейтинг по убыванию). Основание — призёр/победитель ШЭ.
     */
    public function composeTopN(Request $request, Olympiad $olympiad): RedirectResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        if (! $olympiad->isEntryOpenForAte($ateId, 'primary')) {
            return back()->withErrors(['compose' => 'Формирование состава по этой олимпиаде закрыто.']);
        }

        $request->validate([
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.n' => ['required', 'integer', 'min:1'],
            'groups.*.classes' => ['required', 'array', 'min:1'],
            'groups.*.classes.*' => ['integer', Rule::in($olympiad->gradesArray())],
        ]);

        $sheIds = Olympiad::where('subject_id', $olympiad->subject_id)->where('stage', 'school')
            ->where('academic_year_id', $olympiad->academic_year_id)->pluck('id');

        $existing = HumanOlympiad::where('olympiad_id', $olympiad->id)->get(['student_id', 'participation_grade'])
            ->map(fn ($h) => $h->student_id.':'.$h->participation_grade)->flip();

        $added = 0;
        foreach ($request->input('groups') as $group) {
            $classes = array_map('intval', $group['classes']);
            $n = (int) $group['n'];

            $top = HumanOlympiad::query()
                ->whereIn('human_olympiad.olympiad_id', $sheIds)
                ->whereIn('human_olympiad.participation_grade', $classes)
                ->whereNotNull('human_olympiad.score')
                ->whereHas('student.school', fn ($s) => $s->whereIn('ate_id', $ateIds))
                ->orderByDesc('human_olympiad.score')
                ->orderBy('human_olympiad.id')
                ->limit($n)
                ->get(['student_id', 'participation_grade']);

            foreach ($top as $c) {
                $key = $c->student_id.':'.$c->participation_grade;
                if ($existing->has($key)) {
                    continue;
                }
                HumanOlympiad::create([
                    'student_id' => $c->student_id, 'olympiad_id' => $olympiad->id,
                    'participation_grade' => $c->participation_grade, 'result_status' => 'participant',
                    'inclusion_basis' => 'school_stage',
                ]);
                $existing->put($key, true);
                $added++;
            }
        }

        return back()->with('success', $added > 0
            ? "Добавлено приглашённых: {$added}."
            : 'Новых участников по критериям не найдено.');
    }

    /**
     * Формирование состава «из каждой школы по N по группам классов»: в каждой группе классов
     * участия из КАЖДОЙ школы АТЕ берутся N участников ШЭ с наибольшим баллом. Основание — ШЭ.
     */
    public function composeTopNPerSchool(Request $request, Olympiad $olympiad): RedirectResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        if (! $olympiad->isEntryOpenForAte($ateId, 'primary')) {
            return back()->withErrors(['compose' => 'Формирование состава по этой олимпиаде закрыто.']);
        }

        $request->validate([
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.n' => ['required', 'integer', 'min:1'],
            'groups.*.classes' => ['required', 'array', 'min:1'],
            'groups.*.classes.*' => ['integer', Rule::in($olympiad->gradesArray())],
        ]);

        $sheIds = Olympiad::where('subject_id', $olympiad->subject_id)->where('stage', 'school')
            ->where('academic_year_id', $olympiad->academic_year_id)->pluck('id');

        $existing = HumanOlympiad::where('olympiad_id', $olympiad->id)->get(['student_id', 'participation_grade'])
            ->map(fn ($h) => $h->student_id.':'.$h->participation_grade)->flip();

        $added = 0;
        foreach ($request->input('groups') as $group) {
            $classes = array_map('intval', $group['classes']);
            $n = (int) $group['n'];

            // Все участники ШЭ группы, упорядочены по школе, затем по баллу (убыв.) — берём первые N в школе.
            $rows = HumanOlympiad::query()
                ->whereIn('human_olympiad.olympiad_id', $sheIds)
                ->whereIn('human_olympiad.participation_grade', $classes)
                ->whereNotNull('human_olympiad.score')
                ->join('students', 'students.id', '=', 'human_olympiad.student_id')
                ->join('schools', 'schools.id', '=', 'students.school_id')
                ->whereIn('schools.ate_id', $ateIds)
                ->orderBy('schools.id')->orderByDesc('human_olympiad.score')->orderBy('human_olympiad.id')
                ->select('human_olympiad.student_id', 'human_olympiad.participation_grade', 'students.school_id')
                ->get();

            $perSchool = [];
            foreach ($rows as $r) {
                $sc = $r->school_id;
                if (($perSchool[$sc] ?? 0) >= $n) {
                    continue;
                }
                $perSchool[$sc] = ($perSchool[$sc] ?? 0) + 1;

                $key = $r->student_id.':'.$r->participation_grade;
                if ($existing->has($key)) {
                    continue;
                }
                HumanOlympiad::create([
                    'student_id' => $r->student_id, 'olympiad_id' => $olympiad->id,
                    'participation_grade' => $r->participation_grade, 'result_status' => 'participant',
                    'inclusion_basis' => 'school_stage',
                ]);
                $existing->put($key, true);
                $added++;
            }
        }

        return back()->with('success', $added > 0
            ? "Добавлено приглашённых: {$added}."
            : 'Новых участников по критериям не найдено.');
    }

    /** Базовый запрос результатов ШЭ своих АТЕ по предмету этой МЭ-олимпиады (тот же год). */
    private function schoolStageBaseQuery(Olympiad $olympiad, array $ateIds)
    {
        $sheIds = Olympiad::where('subject_id', $olympiad->subject_id)->where('stage', 'school')
            ->where('academic_year_id', $olympiad->academic_year_id)->pluck('id');

        return HumanOlympiad::query()
            ->whereIn('human_olympiad.olympiad_id', $sheIds)
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->whereIn('schools.ate_id', $ateIds);
    }

    /** Страница «Результаты ШЭ» — просмотр результатов школьного этапа своего АТЕ + выгрузка/импорт. */
    public function schoolStageResults(Request $request, Olympiad $olympiad): Response
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        $q = trim((string) $request->query('q', ''));
        $grade = $request->filled('grade') ? (int) $request->query('grade') : null;
        $school = $request->filled('school') ? (int) $request->query('school') : null;
        $status = $request->query('status') ?: null;

        $base = $this->schoolStageBaseQuery($olympiad, $ateIds);
        $gradeOptions = (clone $base)->distinct()->orderBy('human_olympiad.participation_grade')
            ->pluck('human_olympiad.participation_grade');
        $schoolOptions = (clone $base)->distinct()->orderBy('schools.short_name')
            ->pluck('schools.short_name', 'schools.id')
            ->map(fn ($name, $id) => ['id' => $id, 'short_name' => $name])->values();

        $rows = (clone $base)
            ->when($q !== '', fn ($x) => $x->where('students.fio', 'like', "%{$q}%"))
            ->when($grade !== null, fn ($x) => $x->where('human_olympiad.participation_grade', $grade))
            ->when($school !== null, fn ($x) => $x->where('students.school_id', $school))
            ->when($status, fn ($x) => $x->where('human_olympiad.result_status', $status))
            ->with(['student:id,fio,real_grade,school_id', 'student.school:id,short_name'])
            ->select('human_olympiad.*')
            ->orderBy('human_olympiad.participation_grade')->orderByDesc('human_olympiad.score')
            ->paginate(30)->withQueryString()
            ->through(fn (HumanOlympiad $h) => [
                'student_id' => $h->student_id,
                'fio' => $h->student?->fio,
                'school' => $h->student?->school?->short_name,
                'real_grade' => $h->student?->real_grade,
                'participation_grade' => $h->participation_grade,
                'score' => $h->score,
                'result_status' => $h->result_status,
                'prev_municipal_winner' => (bool) $h->prev_municipal_winner,
            ]);

        return Inertia::render('Municipal/SchoolResults/Index', [
            'olympiad' => [
                'id' => $olympiad->id,
                'subject' => $olympiad->subject,
                'level' => $olympiad->level,
                'grades' => $olympiad->gradesArray(),
                'compose_open' => $olympiad->isEntryOpenForAte($ateId, 'primary'),
            ],
            'rows' => $rows,
            'filters' => ['q' => $q, 'grade' => $grade, 'school' => $school, 'status' => $status],
            'grade_options' => $gradeOptions,
            'school_options' => $schoolOptions,
        ]);
    }

    /** Выгрузка протокола результатов ШЭ своего АТЕ (XLSX) — основа для списка приглашённых. */
    public function exportSchoolStage(Request $request, Olympiad $olympiad): StreamedResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        $works = $this->schoolStageBaseQuery($olympiad, $ateIds)
            ->with(['student:id,fio,real_grade,school_id', 'student.school:id,short_name'])
            ->select('human_olympiad.*')
            ->orderBy('human_olympiad.participation_grade')->orderByDesc('human_olympiad.score')
            ->get();

        $olympiad->loadMissing('academicYear');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Результаты ШЭ');
        $header = ['ID', 'ФИО', 'Школа', 'Класс', 'Класс участия', 'Балл ШЭ', 'Статус ШЭ', 'Призёр МЭ прошлого года'];
        $headerRow = OlympiadImportHeader::write($sheet, $olympiad);
        foreach ($header as $i => $title) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$headerRow, $title);
        }

        $statusRu = ['winner' => 'победитель', 'prize_winner' => 'призёр', 'participant' => 'участник'];
        $row = $headerRow + 1;
        foreach ($works as $h) {
            $values = [
                $h->student_id,
                $h->student?->fio,
                $h->student?->school?->short_name,
                $h->student?->real_grade,
                $h->participation_grade,
                $h->score,
                $statusRu[$h->result_status] ?? '',
                $h->prev_municipal_winner ? 'да' : '',
            ];
            foreach ($values as $i => $v) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$row, (string) $v, DataType::TYPE_STRING);
            }
            $row++;
        }
        foreach ($header as $i => $title) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setAutoSize(true);
        }

        $filename = 'rezultaty_ShE_'.preg_replace('/[^\w\-]+/u', '_', $olympiad->subject).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Импорт списка приглашённых на МЭ из подготовленного файла (на основе выгрузки ШЭ).
     * В файле оставлены только приглашённые — каждая строка добавляется в состав МЭ. Колонки:
     * ID ученика (1-я), Класс участия (5-я). Проверки: ученик своего АТЕ, класс в диапазоне олимпиады,
     * код олимпиады в шапке. Дедуп по (ученик, класс участия). Основание — «призёр/победитель ШЭ».
     */
    public function importInvited(Request $request, Olympiad $olympiad): RedirectResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        if (! $olympiad->isEntryOpenForAte($ateId, 'primary')) {
            return back()->withErrors(['file' => 'Формирование состава по этой олимпиаде закрыто.']);
        }

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,ods', 'max:10240']]);

        $file = $request->file('file');
        $parsed = OlympiadImportHeader::parse(
            SpreadsheetReader::rows($file->getRealPath(), $file->getClientOriginalExtension())
        );
        if ($parsed['code'] !== null && $parsed['code'] !== $olympiad->id) {
            $other = Olympiad::find($parsed['code']);
            $name = $other ? "«{$other->subject}»" : "#{$parsed['code']}";

            return back()->withErrors(['file' => "Файл от другой олимпиады ({$name}), а вы загружаете в «{$olympiad->subject}». Скачайте выгрузку нужной олимпиады."]);
        }

        $ownGrades = Student::whereHas('school', fn ($s) => $s->whereIn('ate_id', $ateIds))->pluck('real_grade', 'id');
        $grades = $olympiad->gradesArray();
        $existing = HumanOlympiad::where('olympiad_id', $olympiad->id)->get(['student_id', 'participation_grade'])
            ->map(fn ($h) => $h->student_id.':'.$h->participation_grade)->flip();

        $added = 0;
        $skipped = [];
        $seen = [];

        foreach ($parsed['data'] as $r) {
            $sid = (int) ($r[0] ?? 0);
            if ($sid === 0) {
                continue;
            }
            if (! $ownGrades->has($sid)) {
                $skipped[] = "ID {$sid}: ученик не из вашего АТЕ";
                continue;
            }
            $realGrade = (int) $ownGrades[$sid];
            $grade = (int) ($r[4] ?? 0) ?: $realGrade;
            if ($grade < $realGrade) {
                $skipped[] = "ID {$sid}: класс участия ниже класса обучения";
                continue;
            }
            if ($grades && ! in_array($grade, $grades, true)) {
                $skipped[] = "ID {$sid}: класс {$grade} вне диапазона олимпиады";
                continue;
            }
            $key = $sid.':'.$grade;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($existing->has($key)) {
                $skipped[] = "ID {$sid}: уже в составе";
                continue;
            }

            HumanOlympiad::create([
                'student_id' => $sid, 'olympiad_id' => $olympiad->id,
                'participation_grade' => $grade, 'result_status' => 'participant', 'inclusion_basis' => 'school_stage',
            ]);
            $existing->put($key, true);
            $added++;
        }

        $message = "Добавлено приглашённых: {$added}.";
        if ($skipped !== []) {
            return back()->with('success', $message.' Пропущено: '.count($skipped).'.')
                ->with('import_skipped', $skipped);
        }

        return back()->with('success', $message);
    }

    /** Ручное добавление одного участника МЭ. */
    public function store(Request $request, Olympiad $olympiad): RedirectResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        if (! $olympiad->isEntryOpenForAte($ateId, 'primary')) {
            return back()->withErrors(['student_id' => 'Формирование состава по этой олимпиаде закрыто.']);
        }

        $validated = $request->validate([
            'student_id' => [
                'required',
                Rule::exists('students', 'id')->whereIn(
                    'school_id',
                    School::whereIn('ate_id', $ateIds)->pluck('id'),
                ),
            ],
            'participation_grade' => ['required', 'integer', Rule::in($olympiad->gradesArray())],
        ]);

        $student = Student::find($validated['student_id']);
        if ($validated['participation_grade'] < $student->real_grade) {
            return back()->withErrors(['participation_grade' => 'Класс участия не может быть ниже класса обучения.']);
        }

        HumanOlympiad::firstOrCreate(
            [
                'student_id' => $validated['student_id'],
                'olympiad_id' => $olympiad->id,
                'participation_grade' => $validated['participation_grade'],
            ],
            ['result_status' => 'participant', 'inclusion_basis' => 'petition'],
        );

        return back()->with('success', 'Участник добавлен (по ходатайству).');
    }

    /** Добавление участника из другого региона: создаётся карточка при школе-ходатае. */
    public function external(Request $request, Olympiad $olympiad): RedirectResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        if (! $olympiad->isEntryOpenForAte($ateId, 'primary')) {
            return back()->withErrors(['fio' => 'Формирование состава по этой олимпиаде закрыто.']);
        }

        $schoolIds = School::whereIn('ate_id', $ateIds)->pluck('id');
        $data = $request->validate([
            'school_id' => ['required', Rule::in($schoolIds->all())],
            'fio' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'snils' => ['nullable', 'string', 'max:20'],
            'real_grade' => ['required', 'integer', 'between:1,11'],
            'origin_region' => ['nullable', 'string', 'max:255'],
            'participation_grade' => ['required', 'integer', Rule::in($olympiad->gradesArray())],
        ]);

        if ($data['participation_grade'] < $data['real_grade']) {
            return back()->withErrors(['participation_grade' => 'Класс участия не может быть ниже класса обучения.']);
        }

        $student = Student::create([
            'fio' => $data['fio'],
            'birth_date' => $data['birth_date'],
            'gender' => $data['gender'] ?? null,
            'snils' => ($data['snils'] ?? '') !== '' ? $data['snils'] : null,
            'school_id' => $data['school_id'],
            'real_grade' => $data['real_grade'],
            'status' => 'active',
            'from_other_region' => true,
            'origin_region' => $data['origin_region'] ?? null,
        ]);

        HumanOlympiad::create([
            'student_id' => $student->id,
            'olympiad_id' => $olympiad->id,
            'participation_grade' => $data['participation_grade'],
            'result_status' => 'participant',
            'inclusion_basis' => 'petition',
        ]);

        return back()->with('success', 'Участник из другого региона добавлен.');
    }

    public function destroy(Request $request, HumanOlympiad $participation): RedirectResponse
    {
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();
        abort_unless(in_array($participation->student?->school?->ate_id, $ateIds, true), 403);

        if (! $participation->olympiad->isEntryOpenForAte($ateId, 'primary')) {
            return back()->withErrors(['participation' => 'Формирование состава по этой олимпиаде закрыто.']);
        }

        $participation->delete();

        return back()->with('success', 'Участник удалён.');
    }

    /**
     * Сумма баллов по заданиям из запроса (поле-массив 1..N). Нормализует запятые,
     * валидирует, возвращает [сумма|null, карта {номер→балл}|null].
     */
    private function questionSum(Request $request, string $field, int $count): array
    {
        $raw = (array) $request->input($field, []);
        $clean = [];
        foreach ($raw as $k => $v) {
            $clean[$k] = ($v === null || $v === '') ? null : str_replace(',', '.', (string) $v);
        }
        $request->merge([$field => $clean]);
        $request->validate([
            $field => ['array'],
            $field.'.*' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $map = [];
        $sum = 0.0;
        $any = false;
        for ($i = 1; $i <= $count; $i++) {
            $v = $clean[$i] ?? $clean[(string) $i] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $val = round((float) $v, 2);
            $map[$i] = $val;
            $sum += $val;
            $any = true;
        }

        return [$any ? round($sum, 2) : null, $map ?: null];
    }

    private function maxRu(float $max): string
    {
        return rtrim(rtrim(number_format($max, 2, ',', ''), '0'), ',');
    }

    /** Присвоение/изменение шифра участнику МЭ (вручную координатором). */
    public function storeCipher(Request $request, HumanOlympiad $participation): RedirectResponse
    {
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();
        abort_unless(in_array($participation->student?->school?->ate_id, $ateIds, true), 403);
        $olympiad = $participation->olympiad;
        abort_unless($olympiad->stage === 'municipal', 404);

        if ($olympiad->isPublished()) {
            return back()->withErrors(['cipher' => 'Олимпиада опубликована — шифр изменять нельзя.']);
        }

        $data = $request->validate([
            'cipher' => [
                'nullable', 'string', 'max:50',
                Rule::unique('human_olympiad', 'barcode')->where('olympiad_id', $olympiad->id)->ignore($participation->id),
            ],
        ]);

        $participation->barcode = ($data['cipher'] ?? '') !== '' ? $data['cipher'] : null;
        $participation->save();

        return back()->with('success', 'Шифр сохранён.');
    }

    /**
     * Загрузка сканов работ своего АТЕ ZIP-архивом (для онлайн-показа). Файлы именуются шифром;
     * сопоставляются только с работами своего АТЕ в этой олимпиаде. Чужие/неизвестные шифры
     * пропускаются. Координатор работает по своему `ate_id`.
     */
    public function uploadScans(Request $request, Olympiad $olympiad): RedirectResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        $request->validate(['file' => ['required', 'file', 'mimes:zip', 'max:262144']]);

        $byBarcode = HumanOlympiad::where('olympiad_id', $olympiad->id)
            ->whereNotNull('barcode')
            ->whereHas('student.school', fn ($s) => $s->whereIn('ate_id', $ateIds))
            ->get()->keyBy('barcode');
        if ($byBarcode->isEmpty()) {
            return back()->withErrors(['file' => 'У участников вашего АТЕ не заданы шифры — сопоставить сканы не с чем.']);
        }

        $res = ScanArchiveImporter::import($olympiad->id, $request->file('file')->getRealPath(), $byBarcode);

        $message = "Загружено сканов: {$res['applied']}.";
        if ($res['skipped'] !== []) {
            return back()->with('success', $message.' Пропущено: '.count($res['skipped']).'.')
                ->with('import_skipped', $res['skipped']);
        }

        return back()->with('success', $message);
    }

    /** Шаблон для присвоения шифров: состав этого АТЕ с колонками ID·ФИО·класс·класс участия·шифр. */
    public function cipherTemplateXlsx(Request $request, Olympiad $olympiad): StreamedResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        $participants = HumanOlympiad::query()
            ->where('human_olympiad.olympiad_id', $olympiad->id)
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->whereIn('schools.ate_id', $ateIds)
            ->with(['student:id,fio,real_grade,school_id', 'student.school:id,short_name'])
            ->select('human_olympiad.*')
            ->orderBy('human_olympiad.participation_grade')->orderBy('students.fio')
            ->get();

        $olympiad->loadMissing('academicYear');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Шифры');
        $header = ['ID', 'ФИО', 'Школа', 'Класс', 'Класс участия', 'Шифр'];
        $headerRow = OlympiadImportHeader::write($sheet, $olympiad);
        foreach ($header as $i => $title) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$headerRow, $title);
        }

        $row = $headerRow + 1;
        foreach ($participants as $h) {
            $values = [
                $h->id,
                $h->student?->fio,
                $h->student?->school?->short_name,
                $h->student?->real_grade,
                $h->participation_grade,
                $h->barcode,
            ];
            foreach ($values as $i => $value) {
                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($i + 1).$row,
                    (string) $value,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                );
            }
            $row++;
        }
        foreach ($header as $i => $title) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setAutoSize(true);
        }

        $filename = 'shifry_'.preg_replace('/[^\w\-]+/u', '_', $olympiad->subject).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Массовое присвоение шифров из CSV «ID;шифр» (ID — из шаблона). Привязывает шифр к участию
     * своего АТЕ в этой олимпиаде; построчно: дубль ID/шифра в файле, участие не из вашего состава,
     * пустой шифр, шифр занят другим участником → пропуск с причиной. Пока статус ≠ published.
     */
    public function importCiphers(Request $request, Olympiad $olympiad): RedirectResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();

        if ($olympiad->isPublished()) {
            return back()->withErrors(['file' => 'Олимпиада опубликована — шифры изменять нельзя.']);
        }

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,ods', 'max:10240']]);

        $file = $request->file('file');
        $parsed = OlympiadImportHeader::parse(
            SpreadsheetReader::rows($file->getRealPath(), $file->getClientOriginalExtension())
        );
        if ($parsed['code'] !== null && $parsed['code'] !== $olympiad->id) {
            $other = Olympiad::find($parsed['code']);
            $name = $other ? "«{$other->subject}»" : "#{$parsed['code']}";

            return back()->withErrors(['file' => "Файл от другой олимпиады ({$name}), а вы загружаете в «{$olympiad->subject}». Скачайте шаблон нужной олимпиады."]);
        }
        $rows = $parsed['data'];

        // Участия своего АТЕ в этой олимпиаде, по id.
        $works = HumanOlympiad::query()
            ->where('olympiad_id', $olympiad->id)
            ->whereHas('student.school', fn ($s) => $s->whereIn('ate_id', $ateIds))
            ->get()
            ->keyBy('id');

        // Карта занятых шифров в олимпиаде (любой АТЕ) → id участия, для контроля коллизий.
        $taken = HumanOlympiad::where('olympiad_id', $olympiad->id)
            ->whereNotNull('barcode')->pluck('id', 'barcode')->all();

        $applied = 0;
        $skipped = [];
        $seenIds = [];
        $seenCiphers = [];

        foreach ($rows as $row) {
            $id = (int) ($row[0] ?? 0);
            // Шифр — последняя колонка (в шаблоне «Шифр», в простом файле «ID;шифр» — вторая).
            $cipher = trim((string) ($row[count($row) - 1] ?? ''));
            if ($id === 0) {
                continue;
            }
            if (isset($seenIds[$id])) {
                $skipped[] = "ID {$id}: дубль строки в файле";
                continue;
            }
            $seenIds[$id] = true;

            $work = $works->get($id);
            if (! $work) {
                $skipped[] = "ID {$id}: участие не из состава вашего АТЕ";
                continue;
            }
            if ($cipher === '') {
                $skipped[] = "ID {$id}: пустой шифр";
                continue;
            }
            if (isset($seenCiphers[$cipher])) {
                $skipped[] = "ID {$id}: дубль шифра «{$cipher}» в файле";
                continue;
            }
            if (isset($taken[$cipher]) && (int) $taken[$cipher] !== $id) {
                $skipped[] = "ID {$id}: шифр «{$cipher}» занят другим участником";
                continue;
            }

            if ($work->barcode !== null) {
                unset($taken[$work->barcode]);
            }
            $work->barcode = $cipher;
            $work->save();
            $taken[$cipher] = $id;
            $seenCiphers[$cipher] = true;
            $applied++;
        }

        $message = "Присвоено шифров: {$applied}.";
        if ($skipped !== []) {
            $message .= ' Пропущено: '.count($skipped).'.';

            return back()->with('success', $message)->with('import_skipped', $skipped);
        }

        return back()->with('success', $message);
    }

    /** Шаблон массового ввода первичных баллов МЭ: состав в области видимости координатора. */
    public function scoreTemplateXlsx(Request $request, Olympiad $olympiad): StreamedResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateIds = $request->user()->municipalAteScope();

        $participants = HumanOlympiad::query()
            ->where('human_olympiad.olympiad_id', $olympiad->id)
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->whereIn('schools.ate_id', $ateIds)
            ->with(['student:id,fio,real_grade,school_id', 'student.school:id,short_name'])
            ->select('human_olympiad.*')
            ->orderBy('human_olympiad.participation_grade')->orderBy('students.fio')
            ->get();

        $olympiad->loadMissing('academicYear');
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Баллы МЭ');
        $header = self::SCORE_TEMPLATE_HEADER;
        $headerRow = OlympiadImportHeader::write($sheet, $olympiad);
        foreach ($header as $i => $title) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$headerRow, $title);
        }

        $row = $headerRow + 1;
        foreach ($participants as $h) {
            $s = $h->student;
            $values = [$h->id, $s?->fio, $s?->school?->short_name, $s?->real_grade, $h->participation_grade, $olympiad->maxScoreFor((int) $h->participation_grade), $h->primary_score];
            foreach ($values as $i => $v) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$row, (string) $v, DataType::TYPE_STRING);
            }
            $row++;
        }
        foreach ($header as $i => $title) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setAutoSize(true);
        }

        $filename = 'bally_ME_'.preg_replace('/[^\w\-]+/u', '_', $olympiad->subject).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Запуск фонового импорта первичных баллов МЭ по ID участия (по частям, с прогресс-баром). */
    public function importScores(Request $request, Olympiad $olympiad, ChunkedImportService $importer): JsonResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateId = $request->user()->ate_id;

        if (! $olympiad->isEntryOpenForAte($ateId, 'primary')) {
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
            $request->user()->id, 'municipal_primary_scores', 'Первичные баллы МЭ',
            ['olympiad_id' => $olympiad->id, 'line_offset' => $parsed['offset']],
            self::SCORE_TEMPLATE_HEADER, $parsed['data'],
        );

        return response()->json(['id' => $import->id, 'total' => $import->total]);
    }

    /** Обработка очередной части строк импорта баллов МЭ. */
    public function importScoresChunk(Request $request, BulkImport $bulkImport, ChunkedImportService $importer): JsonResponse
    {
        $this->authorizeScoreImport($request, $bulkImport);

        $olympiad = Olympiad::findOrFail($bulkImport->context['olympiad_id']);
        $ateIds = $request->user()->municipalAteScope();

        // Карта ID участия → участие (только в области видимости координатора).
        $works = HumanOlympiad::query()
            ->where('human_olympiad.olympiad_id', $olympiad->id)
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->whereIn('schools.ate_id', $ateIds)
            ->select('human_olympiad.*')
            ->get()
            ->keyBy('id');

        $progress = $importer->processChunk($bulkImport, function (array $row, int $line, ImportResult $result) use ($olympiad, $works) {
            $this->applyMunicipalScoreRow($row, $line, $olympiad, $works, $result);
        });

        return response()->json($progress);
    }

    /** Выгрузка строк с ошибками фонового импорта баллов МЭ. */
    public function importScoresErrors(Request $request, BulkImport $bulkImport, ChunkedImportService $importer): StreamedResponse
    {
        $this->authorizeScoreImport($request, $bulkImport);

        return $importer->errorsCsv($bulkImport);
    }

    private function authorizeScoreImport(Request $request, BulkImport $bulkImport): void
    {
        abort_unless($bulkImport->type === 'municipal_primary_scores' && $bulkImport->user_id === $request->user()->id, 403);
    }

    /** Обработка одной строки импорта балла МЭ (по ID участия; балл — последняя колонка). */
    private function applyMunicipalScoreRow(array $row, int $line, Olympiad $olympiad, $works, ImportResult $result): void
    {
        $idRaw = trim((string) ($row[0] ?? ''));
        if (! preg_match('/^\d+$/', $idRaw)) {
            return; // служебные/пустые строки без ID
        }
        $participationId = (int) $idRaw;
        $rawScore = trim((string) ($row[count($row) - 1] ?? ''));

        $work = $works->get($participationId);
        if (! $work) {
            $result->fail($line, 'участие не найдено в области видимости', $row);

            return;
        }
        if ($rawScore === '') {
            $result->skipped++;

            return;
        }
        $norm = str_replace(',', '.', $rawScore);
        if (! is_numeric($norm) || (float) $norm < 0) {
            $result->fail($line, "некорректный балл «{$rawScore}»", $row);

            return;
        }
        $score = round((float) $norm, 2);
        $max = $olympiad->maxScoreFor((int) $work->participation_grade);
        if ($max !== null && $score > $max) {
            $result->fail($line, "балл {$rawScore} превышает максимальный ({$max}) для класса {$work->participation_grade}", $row);

            return;
        }

        $work->primary_score = $score;
        $work->question_scores = null;
        $work->save();
        $result->updated++;
    }

    /** Ввод первичного балла МЭ (единым числом или по заданиям — тогда сумма); итог авто. */
    public function storePrimary(Request $request, HumanOlympiad $participation): RedirectResponse
    {
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();
        abort_unless(in_array($participation->student?->school?->ate_id, $ateIds, true), 403);
        $olympiad = $participation->olympiad;
        abort_unless($olympiad->stage === 'municipal', 404);

        if (! $olympiad->isEntryOpenForAte($ateId, 'primary')) {
            return back()->withErrors(['primary_score' => 'Ввод первичных результатов закрыт.']);
        }

        $max = $olympiad->maxScoreFor((int) $participation->participation_grade);
        $count = (int) $olympiad->question_count;

        if ($count > 0) {
            [$primary, $map] = $this->questionSum($request, 'scores', $count);
            if ($max !== null && $primary !== null && $primary > $max) {
                return back()->withErrors(['scores' => "Сумма баллов по заданиям не может превышать максимальный ({$this->maxRu($max)})."]);
            }
            $participation->question_scores = $map;
            $participation->primary_score = $primary;
            $participation->save();

            return back()->with('success', 'Баллы по заданиям сохранены.');
        }

        if ($request->filled('primary_score')) {
            $request->merge(['primary_score' => str_replace(',', '.', (string) $request->input('primary_score'))]);
        }
        $validated = $request->validate([
            'primary_score' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        if ($max !== null && isset($validated['primary_score']) && (float) $validated['primary_score'] > $max) {
            return back()->withErrors(['primary_score' => "Балл не может превышать максимальный ({$this->maxRu($max)})."]);
        }
        $participation->primary_score = isset($validated['primary_score']) ? round((float) $validated['primary_score'], 2) : null;
        $participation->save();

        return back()->with('success', 'Первичный балл сохранён.');
    }

    /** Ввод добавки по апелляции (единым числом или по заданиям — тогда сумма); итог авто. */
    public function storeAppeal(Request $request, HumanOlympiad $participation): RedirectResponse
    {
        $ateId = $request->user()->ate_id;
        $ateIds = $request->user()->municipalAteScope();
        abort_unless(in_array($participation->student?->school?->ate_id, $ateIds, true), 403);
        $olympiad = $participation->olympiad;
        abort_unless($olympiad->stage === 'municipal', 404);

        if (! $olympiad->isEntryOpenForAte($ateId, 'appeal')) {
            return back()->withErrors(['appeal_addition' => 'Ввод добавочных баллов по апелляциям закрыт.']);
        }

        $max = $olympiad->maxScoreFor((int) $participation->participation_grade);
        $count = (int) $olympiad->question_count;

        if ($count > 0) {
            [$addition, $map] = $this->questionSum($request, 'appeals', $count);
            if ($max !== null && ((float) $participation->primary_score + (float) $addition) > $max) {
                return back()->withErrors(['appeals' => "Итоговый балл не может превышать максимальный ({$this->maxRu($max)})."]);
            }
            $participation->question_appeals = $map;
            $participation->appeal_addition = $addition;
            $participation->save();

            return back()->with('success', 'Добавки по заданиям сохранены.');
        }

        if ($request->filled('appeal_addition')) {
            $request->merge(['appeal_addition' => str_replace(',', '.', (string) $request->input('appeal_addition'))]);
        }
        $validated = $request->validate([
            'appeal_addition' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        $addition = isset($validated['appeal_addition']) ? round((float) $validated['appeal_addition'], 2) : null;
        if ($max !== null && ((float) $participation->primary_score + (float) $addition) > $max) {
            return back()->withErrors(['appeal_addition' => "Итоговый балл не может превышать максимальный ({$this->maxRu($max)})."]);
        }
        $participation->appeal_addition = $addition;
        $participation->save();

        return back()->with('success', 'Добавочный балл по апелляции сохранён.');
    }
}
