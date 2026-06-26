<?php

namespace App\Http\Controllers\Roc;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\HumanOlympiad;
use App\Models\Olympiad;
use App\Models\ProtocolTemplate;
use App\Models\Subject;
use App\Models\User;
use App\Services\ProtocolExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Кабинет РОЦ РТ: представитель и координатор по предмету видят ШЭ/МЭ всех АТЕ, смотрят и
 * выгружают протоколы по фильтрам (предмет, АТЕ, класс, класс участия). Координатор —
 * только по назначенным ему предметам (rocSubjects); представитель — по всем.
 */
class ProtocolController extends Controller
{
    /** Разрешённые предметы: null — все (представитель), иначе массив id (координатор). */
    private function allowedSubjectIds(User $user): ?array
    {
        if ($user->role === \App\Enums\UserRole::RocRepresentative) {
            return null;
        }

        return $user->rocSubjects()->pluck('subjects.id')->all();
    }

    public function index(Request $request): Response
    {
        $allowed = $this->allowedSubjectIds($request->user());
        $currentYearId = AcademicYear::where('status', 'current')->value('id');
        $subjectFilter = $request->filled('subject') ? (int) $request->query('subject') : null;
        $stageFilter = in_array($request->query('stage'), ['school', 'municipal'], true) ? $request->query('stage') : null;

        $olympiads = Olympiad::query()
            ->whereIn('stage', ['school', 'municipal'])
            ->when($currentYearId, fn ($q) => $q->where('academic_year_id', $currentYearId))
            ->when($allowed !== null, fn ($q) => $q->whereIn('subject_id', $allowed))
            ->when($subjectFilter, fn ($q) => $q->where('subject_id', $subjectFilter))
            ->when($stageFilter, fn ($q) => $q->where('stage', $stageFilter))
            ->withCount('humanOlympiads as participants_count')
            ->orderBy('stage')->orderBy('subject')
            ->get()
            ->map(fn (Olympiad $o) => [
                'id' => $o->id,
                'subject' => $o->subject,
                'stage' => $o->stage,
                'grades' => $o->gradesArray(),
                'participants' => $o->participants_count,
            ]);

        $subjects = Subject::query()
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
            ->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Roc/Olympiads/Index', [
            'olympiads' => $olympiads,
            'subjects' => $subjects,
            'filters' => ['subject' => $subjectFilter, 'stage' => $stageFilter],
        ]);
    }

    public function show(Request $request, Olympiad $olympiad): Response
    {
        $this->authorizeSubject($request->user(), $olympiad);

        $ate = $request->filled('ate') ? (int) $request->query('ate') : null;
        $grade = $request->filled('grade') ? (int) $request->query('grade') : null;
        $pgrade = $request->filled('pgrade') ? (int) $request->query('pgrade') : null;
        $q = trim((string) $request->query('q', ''));

        $base = $this->participationsQuery($olympiad, $ate, $grade, $pgrade, $q);

        $ateOptions = Ate::whereIn('id', (clone $base)->select('schools.ate_id')->distinct()->pluck('schools.ate_id'))
            ->orderBy('name')->get(['id', 'name']);

        $rows = (clone $base)
            ->with(['student:id,fio,real_grade,school_id', 'student.school:id,short_name,ate_id'])
            ->select('human_olympiad.*')
            ->orderBy('human_olympiad.participation_grade')->orderBy('students.fio')
            ->paginate(30)->withQueryString()
            ->through(fn (HumanOlympiad $h) => [
                'fio' => $h->student?->fio,
                'school' => $h->student?->school?->short_name,
                'real_grade' => $h->student?->real_grade,
                'participation_grade' => $h->participation_grade,
                'score' => $olympiad->stage === 'municipal' ? $h->final_score : $h->score,
                'result_status' => $h->result_status,
            ]);

        return Inertia::render('Roc/Olympiads/Show', [
            'olympiad' => ['id' => $olympiad->id, 'subject' => $olympiad->subject, 'stage' => $olympiad->stage, 'grades' => $olympiad->gradesArray()],
            'rows' => $rows,
            'filters' => ['ate' => $ate, 'grade' => $grade, 'pgrade' => $pgrade, 'q' => $q],
            'ate_options' => $ateOptions,
            'has_template' => ProtocolTemplate::forStageSubject($olympiad->stage, $olympiad->subject_id) !== null,
        ]);
    }

    public function exportProtocol(Request $request, Olympiad $olympiad, ProtocolExporter $exporter): StreamedResponse|RedirectResponse
    {
        $this->authorizeSubject($request->user(), $olympiad);

        $template = ProtocolTemplate::forStageSubject($olympiad->stage, $olympiad->subject_id);
        if (! $template) {
            return back()->withErrors(['protocol' => 'Шаблон протокола для этого этапа не настроен администратором.']);
        }

        $ate = $request->filled('ate') ? (int) $request->query('ate') : null;
        $grade = $request->filled('grade') ? (int) $request->query('grade') : null;
        $pgrade = $request->filled('pgrade') ? (int) $request->query('pgrade') : null;

        $rows = $this->participationsQuery($olympiad, $ate, $grade, $pgrade, '')
            ->with(['student.school', 'olympiad.maxScores'])
            ->select('human_olympiad.*')
            ->orderBy('human_olympiad.participation_grade')->orderBy('students.fio')
            ->get();

        $spreadsheet = $exporter->build($template, $rows);
        $stageRu = $olympiad->stage === 'municipal' ? 'ME' : 'ShE';
        $filename = 'protokol_'.$stageRu.'_'.preg_replace('/[^\w\-]+/u', '_', $olympiad->subject).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Запрос участий олимпиады с фильтрами АТЕ/класс/класс участия/ФИО. */
    private function participationsQuery(Olympiad $olympiad, ?int $ate, ?int $grade, ?int $pgrade, string $q)
    {
        return HumanOlympiad::query()
            ->where('human_olympiad.olympiad_id', $olympiad->id)
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->when($ate !== null, fn ($x) => $x->where('schools.ate_id', $ate))
            ->when($grade !== null, fn ($x) => $x->where('students.real_grade', $grade))
            ->when($pgrade !== null, fn ($x) => $x->where('human_olympiad.participation_grade', $pgrade))
            ->when($q !== '', fn ($x) => $x->where('students.fio', 'like', "%{$q}%"));
    }

    private function authorizeSubject(User $user, Olympiad $olympiad): void
    {
        abort_unless(in_array($olympiad->stage, ['school', 'municipal'], true), 404);
        $allowed = $this->allowedSubjectIds($user);
        abort_unless($allowed === null || in_array($olympiad->subject_id, $allowed, true), 403);
    }
}
