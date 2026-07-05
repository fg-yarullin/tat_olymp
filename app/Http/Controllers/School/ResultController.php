<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\HumanOlympiad;
use App\Models\Olympiad;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ввод результатов школьного этапа: ручной (по одному участию) и массовый (файл/шаблон).
 * Ученик может участвовать за свой класс или выше и за несколько классов — поэтому ключ
 * участия (ученик + олимпиада + класс участия), а не (ученик + олимпиада).
 */
class ResultController extends Controller
{
    public function index(Request $request): Response
    {
        $school = $request->user()->school;
        $schoolId = $school->id;
        $currentYearId = AcademicYear::where('status', 'current')->value('id');

        $olympiads = Olympiad::query()
            ->where('stage', 'school')
            ->when($currentYearId, fn ($q) => $q->where('academic_year_id', $currentYearId))
            ->with('entryExtensions')
            ->withCount(['humanOlympiads as participations_count' => fn ($q) => $q
                ->whereHas('student', fn ($s) => $s->where('school_id', $schoolId))])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Olympiad $o) => [
                'id' => $o->id,
                'subject' => $o->subject,
                'level' => $o->level,
                'grades' => $o->gradesArray(),
                'import_open' => $o->isEntryOpenFor($school),
                'entry_deadline' => $o->entryDeadlineFor($school)?->toIso8601String(),
                'participations' => $o->participations_count,
            ]);

        return Inertia::render('School/Results/Index', ['olympiads' => $olympiads]);
    }

    /** Колонки, по которым допустима сортировка (ключ из URL → колонка БД). */
    private const SORTABLE = [
        'fio' => 'students.fio',
        'real_grade' => 'students.real_grade',
        'participation_grade' => 'human_olympiad.participation_grade',
        'score' => 'human_olympiad.score',
        'result_status' => 'human_olympiad.result_status',
    ];

    public function show(Request $request, Olympiad $olympiad): Response|RedirectResponse
    {
        $school = $request->user()->school;
        $schoolId = $school->id;
        $sessionKey = "school.results.view.{$olympiad->id}";
        $viewKeys = ['q', 'sort', 'dir', 'page', 'grade', 'pgrade', 'over'];

        // Возврат на страницу без параметров — восстанавливаем последний вид из сессии.
        if (! $request->hasAny($viewKeys) && $request->session()->has($sessionKey)) {
            return redirect()->route('school.results.show',
                array_merge(['olympiad' => $olympiad->id], $request->session()->get($sessionKey)));
        }

        $q = trim((string) $request->query('q', ''));
        $sort = array_key_exists($request->query('sort'), self::SORTABLE) ? $request->query('sort') : 'default';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $grade = $request->filled('grade') ? (int) $request->query('grade') : null;
        $pgrade = $request->filled('pgrade') ? (int) $request->query('pgrade') : null;
        $over = $request->boolean('over'); // только строки с баллом выше максимума

        // Запоминаем вид в сессии (для восстановления при возврате).
        if ($request->hasAny($viewKeys)) {
            $request->session()->put($sessionKey, array_filter([
                'q' => $q !== '' ? $q : null,
                'sort' => $sort !== 'default' ? $sort : null,
                'dir' => $sort !== 'default' ? $dir : null,
                'page' => $request->query('page'),
                'grade' => $grade,
                'pgrade' => $pgrade,
                'over' => $over ?: null,
            ]));
        }

        // Карта макс. баллов по классам — для проверки превышений (флаг/фильтр/счётчик).
        $maxMap = $olympiad->maxScoresMap();
        $joinMax = function ($j) use ($olympiad) {
            $j->on('oms.grade', '=', 'human_olympiad.participation_grade')
                ->where('oms.olympiad_id', '=', $olympiad->id);
        };
        $overMaxCount = HumanOlympiad::query()
            ->where('human_olympiad.olympiad_id', $olympiad->id)
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->where('students.school_id', $schoolId)
            ->join('olympiad_max_scores as oms', $joinMax)
            ->whereColumn('human_olympiad.score', '>', 'oms.max_score')
            ->count();

        // Доступные классы для фильтров (по всем участиям этой олимпиады в школе).
        $base = HumanOlympiad::query()
            ->where('human_olympiad.olympiad_id', $olympiad->id)
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->where('students.school_id', $schoolId);
        $gradeOptions = (clone $base)->distinct()->orderBy('students.real_grade')->pluck('students.real_grade');
        $pgradeOptions = (clone $base)->distinct()->orderBy('human_olympiad.participation_grade')->pluck('human_olympiad.participation_grade');

        $query = $this->filteredParticipations($olympiad, $schoolId, $q, $grade, $pgrade, $over)
            ->with('student:id,fio,real_grade,class_letter')
            ->select('human_olympiad.*');

        // По умолчанию: класс обучения, затем ФИО. Иначе — выбранная колонка + ФИО вторичным.
        if ($sort !== 'default') {
            $query->orderBy(self::SORTABLE[$sort], $dir)->orderBy('students.fio');
        } else {
            $query->orderBy('students.real_grade')->orderBy('students.fio');
        }

        $participations = $query->paginate(25)->withQueryString()
            ->through(fn (HumanOlympiad $h) => [
                'id' => $h->id,
                'student_id' => $h->student_id,
                'fio' => $h->student?->fio,
                'real_grade' => $h->student?->real_grade,
                'class_letter' => $h->student?->class_letter,
                'participation_grade' => $h->participation_grade,
                'score' => $h->score,
                'max_score' => $maxMap[$h->participation_grade] ?? null,
                'over_max' => isset($maxMap[$h->participation_grade]) && $h->score !== null
                    && (float) $h->score > (float) $maxMap[$h->participation_grade],
                'result_status' => $h->result_status,
                'prev_municipal_winner' => $h->prev_municipal_winner,
                'teacher_name' => $h->teacher_name,
                'teacher_workplace' => $h->teacher_workplace,
                'profile' => $h->profile,
                'practice_types' => $h->practice_types,
                'has_scan' => (bool) $h->scan_path,
            ]);

        $students = Student::where('school_id', $schoolId)->where('status', 'active')
            ->orderBy('real_grade')->orderBy('class_letter')->orderBy('fio')
            ->get(['id', 'fio', 'real_grade', 'class_letter'])
            ->map(fn ($s) => [
                'id' => $s->id, 'fio' => $s->fio,
                'real_grade' => $s->real_grade, 'class_letter' => $s->class_letter ?? '',
            ]);

        // Доступные литеры школы — для фильтра шаблона.
        $letters = Student::where('school_id', $schoolId)
            ->whereNotNull('class_letter')->where('class_letter', '!=', '')
            ->distinct()->orderBy('class_letter')->pluck('class_letter');

        // Технология: справочник направлений и видов практик для выбора при вводе.
        $isTechnology = $olympiad->isTechnologySubject();
        $techProfiles = $isTechnology ? $this->techProfiles() : [];

        return Inertia::render('School/Results/Show', [
            'olympiad' => [
                'id' => $olympiad->id,
                'subject' => $olympiad->subject,
                'stage' => $olympiad->stage,
                'level' => $olympiad->level,
                'grades' => $olympiad->gradesArray(),
                'import_open' => $olympiad->isEntryOpenFor($school),
                'entry_deadline' => $olympiad->entryDeadlineFor($school)?->toIso8601String(),
                // Макс. балл по классам (только для показа; задаёт администратор).
                'max_scores' => (object) $olympiad->maxScoresMap(),
            ],
            // Авто-статусы: режим (operator/admin) и пороги по классам (задаёт администратор).
            'auto_status' => [
                'mode' => $olympiad->auto_status_mode,
                'thresholds' => (object) $olympiad->thresholdsMap(),
            ],
            'participations' => $participations,
            'filters' => ['q' => $q, 'sort' => $sort, 'dir' => $dir, 'grade' => $grade, 'pgrade' => $pgrade, 'over' => $over],
            'grade_options' => $gradeOptions,
            'pgrade_options' => $pgradeOptions,
            'over_max_count' => $overMaxCount,
            'students' => $students,
            'letters' => $letters,
            'is_technology' => $isTechnology,
            'tech_profiles' => $techProfiles,
            'school_name' => \App\Models\School::whereKey($schoolId)->value('full_name'),
            'teachers' => $this->teacherDirectory($schoolId),
        ]);
    }

    /** Участия школы по этой олимпиаде с теми же фильтрами, что и таблица показа (без select/пагинации). */
    private function filteredParticipations(Olympiad $olympiad, int $schoolId, string $q, ?int $grade, ?int $pgrade, bool $over)
    {
        $joinMax = function ($j) use ($olympiad) {
            $j->on('oms.grade', '=', 'human_olympiad.participation_grade')
                ->where('oms.olympiad_id', '=', $olympiad->id);
        };

        return HumanOlympiad::query()
            ->where('human_olympiad.olympiad_id', $olympiad->id)
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->where('students.school_id', $schoolId)
            ->when($q !== '', fn ($qq) => $qq->where('students.fio', 'like', "%{$q}%"))
            ->when($grade !== null, fn ($qq) => $qq->where('students.real_grade', $grade))
            ->when($pgrade !== null, fn ($qq) => $qq->where('human_olympiad.participation_grade', $pgrade))
            ->leftJoin('olympiad_max_scores as oms', $joinMax)
            ->when($over, fn ($qq) => $qq->whereColumn('human_olympiad.score', '>', 'oms.max_score'));
    }

    /**
     * Массовое удаление участий: по выбранным ID, по текущему фильтру таблицы,
     * либо полностью все результаты школы по этой олимпиаде.
     */
    public function bulkDestroy(Request $request, Olympiad $olympiad): RedirectResponse
    {
        $school = $request->user()->school;
        $schoolId = $school->id;

        if (! $olympiad->isEntryOpenFor($school)) {
            return back()->withErrors(['participation' => 'Ввод результатов по этой олимпиаде закрыт.']);
        }

        $validated = $request->validate([
            'mode' => ['required', Rule::in(['selected', 'filtered', 'all'])],
            'ids' => ['required_if:mode,selected', 'array'],
            'ids.*' => ['integer'],
        ]);

        if ($validated['mode'] === 'selected') {
            $count = HumanOlympiad::query()
                ->where('human_olympiad.olympiad_id', $olympiad->id)
                ->join('students', 'students.id', '=', 'human_olympiad.student_id')
                ->where('students.school_id', $schoolId)
                ->whereIn('human_olympiad.id', $validated['ids'])
                ->delete();
        } elseif ($validated['mode'] === 'filtered') {
            $q = trim((string) $request->input('q', ''));
            $grade = $request->filled('grade') ? (int) $request->input('grade') : null;
            $pgrade = $request->filled('pgrade') ? (int) $request->input('pgrade') : null;
            $over = $request->boolean('over');
            $count = $this->filteredParticipations($olympiad, $schoolId, $q, $grade, $pgrade, $over)->delete();
        } else {
            $count = HumanOlympiad::query()
                ->where('human_olympiad.olympiad_id', $olympiad->id)
                ->join('students', 'students.id', '=', 'human_olympiad.student_id')
                ->where('students.school_id', $schoolId)
                ->delete();
        }

        // Если удалили все строки текущей страницы — переходим на последнюю существующую
        // страницу того же вида, иначе оператор увидит пустой список при живой пагинации.
        $q = trim((string) $request->input('q', ''));
        $sort = array_key_exists($request->input('sort'), self::SORTABLE) ? $request->input('sort') : 'default';
        $dir = $request->input('dir') === 'desc' ? 'desc' : 'asc';
        $grade = $request->filled('grade') ? (int) $request->input('grade') : null;
        $pgrade = $request->filled('pgrade') ? (int) $request->input('pgrade') : null;
        $over = $request->boolean('over');
        $remaining = $this->filteredParticipations($olympiad, $schoolId, $q, $grade, $pgrade, $over)->count();
        $lastPage = max(1, (int) ceil($remaining / 25));
        $requestedPage = max(1, (int) $request->input('page', 1));
        $targetPage = min($requestedPage, $lastPage);

        return redirect()->route('school.results.show', array_filter([
            'olympiad' => $olympiad->id,
            'q' => $q !== '' ? $q : null,
            'sort' => $sort !== 'default' ? $sort : null,
            'dir' => $sort !== 'default' ? $dir : null,
            'grade' => $grade,
            'pgrade' => $pgrade,
            'over' => $over ?: null,
            'page' => $targetPage > 1 ? $targetPage : null,
        ], fn ($v) => $v !== null))->with('success', "Удалено участий: {$count}.");
    }

    /**
     * Справочник тренеров школы по всем олимпиадам: для каждого ФИО — последнее
     * введённое место работы (автоподсказки + автозаполнение места при вводе результата).
     */
    private function teacherDirectory(int $schoolId): array
    {
        $rows = HumanOlympiad::query()
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->where('students.school_id', $schoolId)
            ->whereNotNull('human_olympiad.teacher_name')
            ->where('human_olympiad.teacher_name', '!=', '')
            ->orderByDesc('human_olympiad.id')
            ->get(['human_olympiad.teacher_name', 'human_olympiad.teacher_workplace']);

        $byName = [];
        foreach ($rows as $r) {
            $name = trim($r->teacher_name);
            if ($name === '' || array_key_exists($name, $byName)) {
                continue; // первая запись = самая свежая (orderByDesc id)
            }
            $byName[$name] = $r->teacher_workplace;
        }

        return collect($byName)->map(fn ($wp, $name) => ['name' => $name, 'workplace' => $wp])->values()->all();
    }

    /** Активные направления технологии с активными видами практик (для выпадающих списков). */
    private function techProfiles(): array
    {
        return \App\Models\TechProfile::query()
            ->where('is_active', true)
            ->with(['practices' => fn ($q) => $q->where('is_active', true)->ordered()])
            ->ordered()->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'practices' => $p->practices->map(fn ($pr) => [
                    'id' => $pr->id,
                    'label' => $pr->label(),
                ]),
            ])->all();
    }

    /** Ручное добавление/обновление одного участия (ученик + класс участия). */
    public function store(Request $request, Olympiad $olympiad): RedirectResponse
    {
        $school = $request->user()->school;
        $schoolId = $school->id;

        if (! $olympiad->isEntryOpenFor($school)) {
            return back()->withErrors(['score' => 'Ввод результатов по этой олимпиаде закрыт.']);
        }

        // Балл может быть дробным (до 2 знаков); принимаем и запятую, и точку.
        if ($request->filled('score')) {
            $request->merge(['score' => str_replace(',', '.', (string) $request->input('score'))]);
        }

        $validated = $request->validate([
            'student_id' => ['required', Rule::exists('students', 'id')->where('school_id', $schoolId)],
            'participation_grade' => ['required', 'integer', Rule::in($olympiad->gradesArray())],
            'score' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'result_status' => ['nullable', Rule::in(['participant', 'prize_winner', 'winner'])],
            'prev_municipal_winner' => ['boolean'],
            'teacher_name' => ['nullable', 'string', 'max:255'],
            'teacher_workplace' => ['nullable', 'string', 'max:255'],
            // Технология: заполняется школьным оператором, наследуется в МЭ.
            'profile' => ['nullable', 'string', 'max:255'],
            'practice_types' => ['nullable', 'string', 'max:255'],
        ]);

        $student = Student::find($validated['student_id']);
        if ($validated['participation_grade'] < $student->real_grade) {
            return back()->withErrors(['participation_grade' => 'Класс участия не может быть ниже класса обучения.']);
        }

        // Балл не может превышать макс. балл класса (если он задан администратором).
        $max = $olympiad->maxScoreFor((int) $validated['participation_grade']);
        if ($max !== null && isset($validated['score']) && (float) $validated['score'] > $max) {
            $maxRu = rtrim(rtrim(number_format($max, 2, ',', ''), '0'), ',');
            return back()->withErrors(['score' => "Балл не может превышать максимальный ({$maxRu}) для класса участия {$validated['participation_grade']}."]);
        }

        HumanOlympiad::updateOrCreate(
            [
                'student_id' => $validated['student_id'],
                'olympiad_id' => $olympiad->id,
                'participation_grade' => $validated['participation_grade'],
            ],
            [
                'score' => isset($validated['score']) ? round((float) $validated['score'], 2) : null,
                'result_status' => $validated['result_status'] ?? 'participant',
                'prev_municipal_winner' => $request->boolean('prev_municipal_winner'),
                'teacher_name' => $validated['teacher_name'] ?? null,
                'teacher_workplace' => $validated['teacher_workplace'] ?? null,
                'profile' => $validated['profile'] ?? null,
                'practice_types' => $validated['practice_types'] ?? null,
            ],
        );

        return back()->with('success', 'Результат сохранён.');
    }

    /** Автосохранение только балла существующего участия (инлайн-ячейка), без правки прочих полей. */
    public function updateScore(Request $request, HumanOlympiad $participation): RedirectResponse
    {
        $school = $request->user()->school;
        abort_unless($participation->student?->school_id === $school->id, 403);
        $olympiad = $participation->olympiad;
        abort_unless($olympiad->stage === 'school', 404);

        if (! $olympiad->isEntryOpenFor($school)) {
            return back()->withErrors(['score' => 'Ввод результатов по этой олимпиаде закрыт.']);
        }

        if ($request->filled('score')) {
            $request->merge(['score' => str_replace(',', '.', (string) $request->input('score'))]);
        }
        $validated = $request->validate(['score' => ['nullable', 'numeric', 'min:0', 'decimal:0,2']]);

        $max = $olympiad->maxScoreFor((int) $participation->participation_grade);
        if ($max !== null && isset($validated['score']) && (float) $validated['score'] > $max) {
            $maxRu = rtrim(rtrim(number_format($max, 2, ',', ''), '0'), ',');

            return back()->withErrors(['score' => "Балл не может превышать максимальный ({$maxRu}) для класса участия {$participation->participation_grade}."]);
        }

        $participation->update(['score' => isset($validated['score']) ? round((float) $validated['score'], 2) : null]);

        return back()->with('success', 'Балл сохранён.');
    }

    /** Авто-расстановка статусов по порогам администратора (режим «школьный оператор»). */
    public function autoStatus(Request $request, Olympiad $olympiad): RedirectResponse
    {
        $school = $request->user()->school;
        $schoolId = $school->id;

        if ($olympiad->auto_status_mode !== 'operator') {
            return back()->withErrors(['auto_status' => 'Статусы по этой олимпиаде расставляет администратор.']);
        }
        if (! $olympiad->isEntryOpenFor($school)) {
            return back()->withErrors(['auto_status' => 'Ввод результатов по этой олимпиаде закрыт.']);
        }
        if (empty($olympiad->thresholdsMap())) {
            return back()->withErrors(['auto_status' => 'Пороги статусов ещё не заданы администратором.']);
        }

        $count = \App\Support\StatusAssigner::apply($olympiad, $schoolId);

        return back()->with('success', "Статусы расставлены. Обновлено участий: {$count}.");
    }

    public function destroy(Request $request, HumanOlympiad $participation): RedirectResponse
    {
        $school = $request->user()->school;
        abort_unless($participation->student?->school_id === $school->id, 403);

        if (! $participation->olympiad->isEntryOpenFor($school)) {
            return back()->withErrors(['participation' => 'Ввод результатов по этой олимпиаде закрыт.']);
        }

        $participation->delete();

        return back()->with('success', 'Участие удалено.');
    }
}
