<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\HumanOlympiad;
use App\Models\Olympiad;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Школьный кабинет: список своих учащихся, приглашённых на муниципальный этап,
 * и его выгрузка в XLSX для оповещения участников внутри школы.
 */
class InvitationController extends Controller
{
    private const BASIS_RU = [
        'school_stage' => 'Призёр/победитель ШЭ',
        'prev_municipal' => 'Прошлогодний призёр МЭ',
        'petition' => 'По ходатайству',
    ];

    public function index(Request $request): Response
    {
        $schoolId = $request->user()->school_id;
        $currentYearId = AcademicYear::where('status', 'current')->value('id');

        $olympiads = Olympiad::query()
            ->where('stage', 'municipal')
            ->when($currentYearId, fn ($q) => $q->where('academic_year_id', $currentYearId))
            ->whereHas('humanOlympiads.student', fn ($s) => $s->where('school_id', $schoolId))
            ->withCount(['humanOlympiads as invited_count' => fn ($q) => $q
                ->whereHas('student', fn ($s) => $s->where('school_id', $schoolId))])
            ->orderBy('subject')
            ->get()
            ->map(fn (Olympiad $o) => [
                'id' => $o->id,
                'subject' => $o->subject,
                'level' => $o->level,
                'grades' => $o->gradesArray(),
                'date_held' => $o->date_held?->toDateString(),
                'invited' => $o->invited_count,
            ]);

        return Inertia::render('School/Invitations/Index', ['olympiads' => $olympiads]);
    }

    public function show(Request $request, Olympiad $olympiad): Response
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $schoolId = $request->user()->school_id;

        return Inertia::render('School/Invitations/Show', [
            'olympiad' => [
                'id' => $olympiad->id,
                'subject' => $olympiad->subject,
                'level' => $olympiad->level,
                'grades' => $olympiad->gradesArray(),
                'date_held' => $olympiad->date_held?->toDateString(),
            ],
            'participants' => $this->invited($olympiad, $schoolId)->map(fn (HumanOlympiad $h) => [
                'id' => $h->id,
                'fio' => $h->student?->fio,
                'class' => $this->classLabel($h),
                'participation_grade' => $h->participation_grade,
                'basis' => self::BASIS_RU[$h->inclusion_basis] ?? '',
            ]),
        ]);
    }

    public function xlsx(Request $request, Olympiad $olympiad): StreamedResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $schoolId = $request->user()->school_id;
        $participants = $this->invited($olympiad, $schoolId);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Приглашённые МЭ');
        $title = 'Приглашённые на муниципальный этап — '.$olympiad->subject
            .($olympiad->date_held ? ' ('.$olympiad->date_held->format('d.m.Y').')' : '');
        $sheet->setCellValue('A1', $title);

        $header = ['№', 'ФИО', 'Класс', 'Класс участия', 'Основание'];
        foreach ($header as $i => $col) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'2', $col);
        }

        $row = 3;
        foreach ($participants as $n => $h) {
            $values = [$n + 1, $h->student?->fio, $this->classLabel($h), $h->participation_grade, self::BASIS_RU[$h->inclusion_basis] ?? ''];
            foreach ($values as $i => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$row, $value);
            }
            $row++;
        }
        foreach ($header as $i => $col) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setAutoSize(true);
        }

        $filename = 'priglashennye_ME_'.preg_replace('/[^\w\-]+/u', '_', $olympiad->subject).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Приглашённые на МЭ участники своей школы, отсортированные по классу и ФИО. */
    private function invited(Olympiad $olympiad, int $schoolId)
    {
        return HumanOlympiad::query()
            ->where('human_olympiad.olympiad_id', $olympiad->id)
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->where('students.school_id', $schoolId)
            ->with('student:id,fio,real_grade,class_letter')
            ->select('human_olympiad.*')
            ->orderBy('students.real_grade')->orderBy('students.fio')
            ->get();
    }

    private function classLabel(HumanOlympiad $h): string
    {
        $grade = $h->student?->real_grade;
        $letter = $h->student?->class_letter;

        return $grade ? $grade.($letter ? '-'.$letter : '') : '';
    }
}
