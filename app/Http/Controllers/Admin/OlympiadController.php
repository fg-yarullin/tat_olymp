<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\HumanOlympiad;
use App\Models\Msu;
use App\Models\Olympiad;
use App\Models\OlympiadEntryExtension;
use App\Models\School;
use App\Models\Subject;
use App\Support\ScanArchiveImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Управление олимпиадами + публикация результатов. Публикация (status=published,
 * published_at) открывает 48-часовое окно гостевого онлайн-показа (ТЗ 4.6).
 * subject_id — нормализованная ссылка; строка subject пишется из справочника для отчётов.
 */
class OlympiadController extends Controller
{
    private const STAGES = ['school', 'municipal', 'regional'];
    private const STATUS_MODES = ['operator', 'admin'];
    private const EXT_SCOPES = ['all', 'ate', 'msu', 'school'];

    public function index(Request $request): Response
    {
        $yearId = $request->query('year');
        $q = trim((string) $request->query('q', ''));
        $level = in_array($request->query('level'), ['regional', 'republican'], true) ? $request->query('level') : null;

        // Скрытие этапов чекбоксами; состояние держим в настройках пользователя (переживает выход).
        $user = $request->user();
        $prefs = $user->ui_preferences ?? [];
        $changed = false;
        foreach (['hide_school', 'hide_municipal'] as $key) {
            if ($request->has($key)) {
                $prefs["admin_olympiads_$key"] = $request->boolean($key);
                $changed = true;
            }
        }
        if ($changed) {
            $user->ui_preferences = $prefs;
            $user->save();
        }
        $hideSchool = (bool) ($prefs['admin_olympiads_hide_school'] ?? false);
        $hideMunicipal = (bool) ($prefs['admin_olympiads_hide_municipal'] ?? false);

        $olympiads = Olympiad::query()
            ->with(['academicYear:id,name', 'subjectRef:id,name', 'maxScores:id,olympiad_id,grade,max_score',
                'statusThresholds:id,olympiad_id,grade,prize_from',
                'entryExtensions.ate:id,name', 'entryExtensions.msu:id,name', 'entryExtensions.school:id,short_name'])
            ->withCount('humanOlympiads')
            ->when($yearId, fn ($qq) => $qq->where('academic_year_id', $yearId))
            ->when($q !== '', fn ($qq) => $qq->where(fn ($w) => $w
                ->where('subject', 'like', "%{$q}%")
                ->orWhereHas('subjectRef', fn ($s) => $s->where('name', 'like', "%{$q}%"))))
            ->when($hideSchool, fn ($qq) => $qq->where('stage', '!=', 'school'))
            ->when($hideMunicipal, fn ($qq) => $qq->where('stage', '!=', 'municipal'))
            ->when($level, fn ($qq) => $qq->where('level', $level))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Olympiad $o) => [
                'id' => $o->id,
                'year' => $o->academicYear?->name,
                'subject' => $o->subjectRef?->name ?? $o->subject,
                'stage' => $o->stage,
                'level' => $o->level,
                'grades' => $o->gradesArray(),
                'question_count' => $o->question_count,
                'max_scores' => (object) $o->maxScoresMap(),
                'thresholds' => (object) $o->thresholdsMap(),
                'auto_status_mode' => $o->auto_status_mode,
                'date_held' => $o->date_held?->toDateString(),
                'published' => $o->isPublished(),
                'published_at' => $o->published_at?->toDateTimeString(),
                // Формат под <input type="datetime-local">
                'results_deadline' => $o->results_deadline?->format('Y-m-d\TH:i'),
                'final_results_deadline' => $o->final_results_deadline?->format('Y-m-d\TH:i'),
                'participants' => $o->human_olympiads_count,
                'extensions' => $o->entryExtensions->map(fn (OlympiadEntryExtension $e) => [
                    'id' => $e->id,
                    'phase' => $e->phase ?? 'primary',
                    'scope' => $e->scope,
                    'target' => match ($e->scope) {
                        'ate' => $e->ate?->name,
                        'msu' => $e->msu?->name,
                        'school' => $e->school?->short_name,
                        default => 'все школы',
                    },
                    'extended_until' => $e->extended_until->format('Y-m-d H:i'),
                    'active' => $e->extended_until->isFuture(),
                ])->values(),
            ]);

        // Олимпиады ШЭ текущего года — источник для создания МЭ; отмечаем, по каким МЭ уже есть.
        $currentYearId = AcademicYear::where('status', 'current')->value('id');
        $munSubjectIds = $currentYearId
            ? Olympiad::where('academic_year_id', $currentYearId)->where('stage', 'municipal')->pluck('subject_id')->filter()->map(fn ($v) => (int) $v)->all()
            : [];
        $schoolForMunicipal = $currentYearId
            ? Olympiad::where('academic_year_id', $currentYearId)->where('stage', 'school')
                ->with('subjectRef:id,name')->orderBy('subject')->get()
                ->map(fn (Olympiad $o) => [
                    'id' => $o->id,
                    'subject' => $o->subjectRef?->name ?? $o->subject,
                    'date_held' => $o->date_held?->toDateString(),
                    'grades' => $o->gradesArray(),
                    'has_municipal' => in_array((int) $o->subject_id, $munSubjectIds, true),
                ])->values()
            : collect();

        return Inertia::render('Admin/Olympiads/Index', [
            'olympiads' => $olympiads,
            'school_for_municipal' => $schoolForMunicipal,
            'filters' => ['year' => $yearId, 'q' => $q, 'level' => $level, 'hide_school' => $hideSchool, 'hide_municipal' => $hideMunicipal],
            'years' => AcademicYear::orderByDesc('name')->get(['id', 'name']),
            'subjects' => Subject::where('is_active', true)->ordered()->get(['id', 'name']),
            'stages' => self::STAGES,
            'levels' => ['regional', 'republican'],
            'status_modes' => self::STATUS_MODES,
            'ates' => Ate::orderBy('name')->get(['id', 'name']),
            'msus' => Msu::orderBy('name')->get(['id', 'name', 'ate_id']),
            'max_extension_hours' => Olympiad::MAX_EXTENSION_HOURS,
        ]);
    }

    /** Школы выбранного АТЕ — для пикера продления (scope=school), грузятся по требованию. */
    public function schools(Request $request): JsonResponse
    {
        $ateId = (int) $request->query('ate_id');

        return response()->json(
            School::when($ateId, fn ($q) => $q->where('ate_id', $ateId))
                ->orderBy('short_name')->limit(3000)->get(['id', 'short_name'])
        );
    }

    /** Продление ввода (ШЭ / МЭ) для скоупа и фазы на N часов от текущего момента. */
    public function extend(Request $request, Olympiad $olympiad): RedirectResponse
    {
        if (! in_array($olympiad->stage, ['school', 'municipal'], true)) {
            return back()->withErrors(['extend' => 'Продление доступно для школьного и муниципального этапов.']);
        }

        $phase = in_array($request->input('phase'), ['primary', 'appeal'], true) ? $request->input('phase') : 'primary';
        $base = $phase === 'appeal' ? $olympiad->final_results_deadline : $olympiad->results_deadline;
        if (! $base) {
            return back()->withErrors(['extend' => 'Сначала задайте срок для этой фазы.']);
        }

        $scope = $request->input('scope');
        $data = $request->validate([
            'scope' => ['required', Rule::in(self::EXT_SCOPES)],
            'ate_id' => [Rule::requiredIf($scope === 'ate'), 'nullable', 'exists:ates,id'],
            'msu_id' => [Rule::requiredIf($scope === 'msu'), 'nullable', 'exists:msus,id'],
            'school_id' => [Rule::requiredIf($scope === 'school'), 'nullable', 'exists:schools,id'],
            'hours' => ['required', 'integer', 'min:1', 'max:'.Olympiad::MAX_EXTENSION_HOURS],
            'phase' => ['nullable', Rule::in(['primary', 'appeal'])],
        ]);

        // Потолок: срок закрытия фазы + 48 ч. Продление считаем от «сейчас», но не дальше потолка.
        $cap = $base->copy()->addHours(Olympiad::MAX_EXTENSION_HOURS);
        if ($cap->isPast()) {
            return back()->withErrors(['extend' => 'Продление невозможно: прошло более 48 часов с момента закрытия.']);
        }
        $until = now()->addHours((int) $data['hours']);
        if ($until->gt($cap)) {
            $until = $cap;
        }

        $olympiad->entryExtensions()->create([
            'phase' => $phase,
            'scope' => $scope,
            'ate_id' => $scope === 'ate' ? $data['ate_id'] : null,
            'msu_id' => $scope === 'msu' ? $data['msu_id'] : null,
            'school_id' => $scope === 'school' ? $data['school_id'] : null,
            'extended_until' => $until,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Ввод продлён до '.$until->format('d.m.Y H:i').'.');
    }

    public function revokeExtension(OlympiadEntryExtension $extension): RedirectResponse
    {
        $extension->delete();

        return back()->with('success', 'Продление отменено.');
    }

    /**
     * Создание олимпиад МЭ на основе выбранных олимпиад ШЭ текущего года. Сроки считаются от
     * даты проведения ШЭ: МЭ проводится +1 месяц; первичка +1 мес +5 дн 16:00; итог +1 мес +8 дн 16:00.
     * Копируются классы и уровень. Предметы, по которым МЭ уже есть, пропускаются.
     */
    public function createMunicipalFromSchool(Request $request): RedirectResponse
    {
        $currentYearId = AcademicYear::where('status', 'current')->value('id');
        if (! $currentYearId) {
            return back()->withErrors(['olympiad' => 'Не задан текущий учебный год.']);
        }

        $data = $request->validate([
            'school_olympiad_ids' => ['required', 'array', 'min:1'],
            'school_olympiad_ids.*' => ['integer'],
        ]);

        $sheList = Olympiad::where('academic_year_id', $currentYearId)
            ->where('stage', 'school')
            ->whereIn('id', $data['school_olympiad_ids'])
            ->orderBy('subject')
            ->get();

        // Предметы, по которым МЭ этого года уже существует — пропускаем (включая создаваемые в этом проходе).
        $existingMunSubjects = Olympiad::where('academic_year_id', $currentYearId)
            ->where('stage', 'municipal')->pluck('subject_id')->filter()->map(fn ($v) => (int) $v)->all();

        $created = 0;
        $skipped = [];
        foreach ($sheList as $she) {
            if (! $she->date_held) {
                $skipped[] = "{$she->subject} — не задана дата проведения ШЭ";

                continue;
            }
            if (in_array((int) $she->subject_id, $existingMunSubjects, true)) {
                $skipped[] = "{$she->subject} — МЭ уже существует";

                continue;
            }

            $base = $she->date_held->copy();
            Olympiad::create([
                'academic_year_id' => $currentYearId,
                'subject' => $she->subject,
                'subject_id' => $she->subject_id,
                'stage' => 'municipal',
                'level' => $she->level,
                'grades' => $she->grades,
                'question_count' => 0,
                'auto_status_mode' => 'operator',
                'date_held' => $base->copy()->addMonth()->toDateString(),
                'results_deadline' => $base->copy()->addMonth()->addDays(5)->setTime(16, 0),
                'final_results_deadline' => $base->copy()->addMonth()->addDays(8)->setTime(16, 0),
            ]);
            $existingMunSubjects[] = (int) $she->subject_id;
            $created++;
        }

        return back()
            ->with('success', "Создано олимпиад МЭ: {$created}.".($skipped ? ' Пропущено: '.count($skipped).'.' : ''))
            ->with('mun_create_skipped', $skipped);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request, null);
        $maxScores = $data['max_scores'];
        $thresholds = $data['thresholds'];
        unset($data['max_scores'], $data['thresholds']);

        $olympiad = Olympiad::create($data);
        $this->syncMaxScores($olympiad, $maxScores);
        $this->syncThresholds($olympiad, $thresholds);

        return back()->with('success', 'Олимпиада создана.');
    }

    public function update(Request $request, Olympiad $olympiad): RedirectResponse
    {
        $data = $this->validateData($request, $olympiad);
        $maxScores = $data['max_scores'];
        $thresholds = $data['thresholds'];
        unset($data['max_scores'], $data['thresholds']);

        $olympiad->update($data);
        $this->syncMaxScores($olympiad, $maxScores);
        $this->syncThresholds($olympiad, $thresholds);

        return back()->with('success', 'Олимпиада обновлена.');
    }

    /** Применяет авто-статусы ко всем участиям олимпиады (действие администратора). */
    public function autoStatus(Olympiad $olympiad): RedirectResponse
    {
        if (empty($olympiad->thresholdsMap())) {
            return back()->withErrors(['olympiad' => 'Сначала задайте пороги статусов для классов участия.']);
        }

        $count = \App\Support\StatusAssigner::apply($olympiad);

        return back()->with('success', "Статусы расставлены. Обновлено участий: {$count}.");
    }

    /** Приводит справочник макс. баллов олимпиады к карте «класс → балл». */
    private function syncMaxScores(Olympiad $olympiad, array $map): void
    {
        $olympiad->maxScores()
            ->when($map, fn ($q) => $q->whereNotIn('grade', array_keys($map)))
            ->delete();

        foreach ($map as $grade => $value) {
            $olympiad->maxScores()->updateOrCreate(['grade' => $grade], ['max_score' => $value]);
        }
    }

    /** Приводит пороги статусов к карте «класс → [prize_from]». */
    private function syncThresholds(Olympiad $olympiad, array $map): void
    {
        $olympiad->statusThresholds()
            ->when($map, fn ($q) => $q->whereNotIn('grade', array_keys($map)))
            ->delete();

        foreach ($map as $grade => $row) {
            $olympiad->statusThresholds()->updateOrCreate(['grade' => $grade], $row);
        }
    }

    public function publish(Olympiad $olympiad): RedirectResponse
    {
        // Окно показа отсчитывается от первой публикации — не сбрасываем при повторной.
        $olympiad->update(['published_at' => $olympiad->published_at ?? now()]);

        return back()->with('success', 'Результаты опубликованы — открыт онлайн-показ работ.');
    }

    /**
     * Загрузка сканов работ муниципального этапа ZIP-архивом. Файлы внутри архива именуются
     * шифром участника (напр. «A-014.pdf»); каждый сопоставляется с работой этой олимпиады по
     * `barcode` и сохраняется для онлайн-показа. Имена без совпадения по шифру пропускаются.
     */
    public function uploadScans(Request $request, Olympiad $olympiad): RedirectResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);

        // Админ грузит сканы по запросу конкретного АТЕ — обязательно выбирает АТЕ.
        $request->validate([
            'file' => ['required', 'file', 'mimes:zip', 'max:262144'],
            'ate_id' => ['required', 'integer', Rule::exists('ates', 'id')],
        ]);

        // Шифр → работа (только зашифрованные работы выбранного АТЕ).
        $byBarcode = HumanOlympiad::where('olympiad_id', $olympiad->id)
            ->whereNotNull('barcode')
            ->whereHas('student.school', fn ($s) => $s->where('ate_id', $request->integer('ate_id')))
            ->get()->keyBy('barcode');
        if ($byBarcode->isEmpty()) {
            return back()->withErrors(['file' => 'У участников выбранного АТЕ не заданы шифры — сопоставить сканы не с чем.']);
        }

        $res = ScanArchiveImporter::import($olympiad->id, $request->file('file')->getRealPath(), $byBarcode);

        $message = "Загружено сканов: {$res['applied']}.";
        if ($res['skipped'] !== []) {
            return back()->with('success', $message.' Пропущено: '.count($res['skipped']).'.')
                ->with('import_skipped', $res['skipped']);
        }

        return back()->with('success', $message);
    }

    public function destroy(Olympiad $olympiad): RedirectResponse
    {
        if ($olympiad->humanOlympiads()->exists()) {
            return back()->withErrors(['olympiad' => 'Нельзя удалить: есть привязанные участия (человеко-олимпиады).']);
        }

        $olympiad->delete();

        return back()->with('success', 'Олимпиада удалена.');
    }

    private function validateData(Request $request, ?Olympiad $olympiad): array
    {
        $data = $request->validate([
            'academic_year_id' => ['required', 'exists:academic_years,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'stage' => ['required', Rule::in(self::STAGES)],
            'level' => ['nullable', Rule::in(['regional', 'republican'])],
            'grades' => ['array'],
            'grades.*' => ['integer', 'between:1,11'],
            // Количество заданий (для ввода баллов по заданиям); 0 — единый балл.
            'question_count' => ['nullable', 'integer', 'between:0,60'],
            // Макс. балл — карта «класс → балл»; задаётся после проведения олимпиады.
            'max_scores' => ['nullable', 'array'],
            'max_scores.*' => ['nullable', 'numeric', 'min:0'],
            // Порог призёра по классам участия (абсолютный балл) + режим расстановки.
            'thresholds' => ['nullable', 'array'],
            'thresholds.*.prize_from' => ['nullable', 'numeric', 'min:0'],
            'auto_status_mode' => ['nullable', Rule::in(self::STATUS_MODES)],
            'date_held' => ['required', 'date'],
            'results_deadline' => ['nullable', 'date'],
            // Итоговый срок (после апелляций) не может быть раньше первичного.
            'final_results_deadline' => ['nullable', 'date', 'after_or_equal:results_deadline'],
        ]);

        // Денормализуем название предмета и канонизируем классы участия.
        $data['subject'] = Subject::whereKey($data['subject_id'])->value('name');
        $data['level'] = $request->input('level', 'regional');
        $data['auto_status_mode'] = $request->input('auto_status_mode', 'operator');
        $data['question_count'] = (int) $request->input('question_count', 0);
        $data['grades'] = Olympiad::canonicalGrades($request->input('grades', []));

        // Оставляем макс. баллы только для классов олимпиады, отбрасываем пустые.
        $validGrades = array_map('intval', array_filter(explode(',', $data['grades'])));
        $maxScores = [];
        foreach ((array) ($data['max_scores'] ?? []) as $grade => $value) {
            if (in_array((int) $grade, $validGrades, true) && $value !== null && $value !== '') {
                $maxScores[(int) $grade] = (float) $value;
            }
        }
        ksort($maxScores);
        $data['max_scores'] = $maxScores;

        // Порог призёра: только для классов олимпиады; требует заданного макс. балла.
        $thresholds = [];
        foreach ((array) ($request->input('thresholds', [])) as $grade => $row) {
            $g = (int) $grade;
            if (! in_array($g, $validGrades, true)) {
                continue;
            }
            $prize = ($row['prize_from'] ?? '') !== '' ? (float) $row['prize_from'] : null;
            if ($prize === null) {
                continue;
            }
            if (! isset($maxScores[$g])) {
                throw ValidationException::withMessages([
                    'thresholds' => "Для класса {$g} сначала задайте максимальный балл.",
                ]);
            }
            $thresholds[$g] = ['prize_from' => $prize];
        }
        ksort($thresholds);
        $data['thresholds'] = $thresholds;

        // Уникальность: год + предмет + этап + классы.
        $dup = Olympiad::where('academic_year_id', $data['academic_year_id'])
            ->where('subject_id', $data['subject_id'])
            ->where('stage', $data['stage'])
            ->where('grades', $data['grades'])
            ->when($olympiad, fn ($q) => $q->whereKeyNot($olympiad->id))
            ->exists();
        if ($dup) {
            throw ValidationException::withMessages([
                'grades' => 'Олимпиада с таким годом, предметом, этапом и набором классов уже существует.',
            ]);
        }

        return $data;
    }
}
