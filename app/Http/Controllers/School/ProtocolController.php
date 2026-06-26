<?php

namespace App\Http\Controllers\School;

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
 * Выгрузка протокола школьного этапа в XLSX по шаблону из конструктора (Вариант 3):
 * берётся шаблон под предмет, иначе общий шаблон этапа.
 */
class ProtocolController extends Controller
{
    public function schoolStage(Request $request, Olympiad $olympiad, ProtocolExporter $exporter): StreamedResponse|RedirectResponse
    {
        $schoolId = $request->user()->school_id;

        $template = ProtocolTemplate::forStageSubject('school', $olympiad->subject_id);
        if (! $template) {
            return back()->withErrors(['protocol' => 'Шаблон протокола школьного этапа не настроен администратором.']);
        }

        $rows = HumanOlympiad::query()
            ->where('olympiad_id', $olympiad->id)
            ->whereHas('student', fn ($s) => $s->where('school_id', $schoolId))
            ->with(['student.school', 'olympiad.maxScores'])
            ->orderBy('participation_grade')
            ->get();

        $spreadsheet = $exporter->build($template, $rows);
        $filename = 'protokol_SHE_'.preg_replace('/[^\w\-]+/u', '_', $olympiad->subject).'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
