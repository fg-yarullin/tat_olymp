<?php

namespace App\Http\Controllers\Municipal;

use App\Http\Controllers\Controller;
use App\Models\HumanOlympiad;
use App\Models\Olympiad;
use App\Models\ProtocolTemplate;
use App\Services\ProtocolExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Выгрузка протокола муниципального этапа в XLSX по шаблону из конструктора:
 * берётся шаблон под предмет, иначе общий шаблон этапа. Координатор выгружает
 * только участников своего АТЕ.
 */
class ProtocolController extends Controller
{
    public function municipalStage(Request $request, Olympiad $olympiad, ProtocolExporter $exporter): StreamedResponse|RedirectResponse
    {
        abort_unless($olympiad->stage === 'municipal', 404);
        $ateIds = $request->user()->municipalAteScope(); // зонтик Казани → районы; иначе [ate_id]

        $template = ProtocolTemplate::forStageSubject('municipal', $olympiad->subject_id);
        if (! $template) {
            return back()->withErrors(['protocol' => 'Шаблон протокола муниципального этапа не настроен администратором.']);
        }

        $rows = HumanOlympiad::query()
            ->where('human_olympiad.olympiad_id', $olympiad->id)
            ->join('students', 'students.id', '=', 'human_olympiad.student_id')
            ->join('schools', 'schools.id', '=', 'students.school_id')
            ->whereIn('schools.ate_id', $ateIds)
            ->with(['student.school', 'olympiad.maxScores'])
            ->select('human_olympiad.*')
            ->orderBy('human_olympiad.participation_grade')
            ->orderBy('students.fio')
            ->get();

        $spreadsheet = $exporter->build($template, $rows);
        $filename = 'protokol_ME_'.preg_replace('/[^\w\-]+/u', '_', $olympiad->subject).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
