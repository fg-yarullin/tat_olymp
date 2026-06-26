<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ate;
use App\Models\HumanOlympiad;
use App\Models\Msu;
use App\Models\School;
use App\Models\Subject;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Просмотр внесённых результатов по олимпиадам. Обязателен выбор предмета; далее
 * сужение по АТЕ → МСУ (если районов несколько) → школе, плюс поиск по участнику.
 */
class ResultController extends Controller
{
    public function index(Request $request): Response
    {
        $subjectId = $request->query('subject_id');
        $stage = $request->query('stage');
        $ateId = $request->query('ate_id');
        $msuId = $request->query('msu_id');
        $schoolId = $request->query('school_id');
        $q = $request->query('q');

        $results = null;
        if ($subjectId) {
            $results = HumanOlympiad::query()
                ->join('olympiads', 'olympiads.id', '=', 'human_olympiad.olympiad_id')
                ->join('students', 'students.id', '=', 'human_olympiad.student_id')
                ->join('schools', 'schools.id', '=', 'students.school_id')
                ->leftJoin('academic_years', 'academic_years.id', '=', 'olympiads.academic_year_id')
                ->leftJoin('ates', 'ates.id', '=', 'schools.ate_id')
                ->leftJoin('msus', 'msus.id', '=', 'schools.msu_id')
                ->where('olympiads.subject_id', $subjectId)
                ->when($stage, fn ($query) => $query->where('olympiads.stage', $stage))
                ->when($ateId, fn ($query) => $query->where('schools.ate_id', $ateId))
                ->when($msuId, fn ($query) => $query->where('schools.msu_id', $msuId))
                ->when($schoolId, fn ($query) => $query->where('students.school_id', $schoolId))
                ->when($q, fn ($query) => $query->where(fn ($w) => $w
                    ->where('students.fio', 'like', "%$q%")
                    ->orWhere('human_olympiad.barcode', 'like', "%$q%")))
                ->orderBy('schools.short_name')
                ->orderBy('students.fio')
                ->select([
                    'human_olympiad.id',
                    'students.fio',
                    'schools.short_name as school',
                    'ates.name as ate',
                    'msus.name as msu',
                    'human_olympiad.participation_grade',
                    'human_olympiad.score',
                    'human_olympiad.result_status',
                    'human_olympiad.barcode',
                    'human_olympiad.scan_path',
                    'olympiads.stage',
                    'olympiads.level',
                    'olympiads.grades',
                    'academic_years.name as year',
                ])
                ->paginate(20)
                ->withQueryString()
                ->through(fn ($r) => [
                    'id' => $r->id,
                    'fio' => $r->fio,
                    'school' => $r->school,
                    'ate' => $r->ate,
                    'msu' => $r->msu,
                    'year' => $r->year,
                    'stage' => $r->stage,
                    'level' => $r->level,
                    'grades' => $r->grades,
                    'participation_grade' => $r->participation_grade,
                    'score' => $r->score,
                    'result_status' => $r->result_status,
                    'barcode' => $r->barcode,
                    'has_scan' => (bool) $r->scan_path,
                ]);
        }

        return Inertia::render('Admin/Results/Index', [
            'results' => $results,
            'filters' => compact('subjectId', 'stage', 'ateId', 'msuId', 'schoolId', 'q'),
            'subjects' => Subject::orderBy('name')->get(['id', 'name']),
            'ates' => Ate::orderBy('name')->get(['id', 'name']),
            'msus' => Msu::orderBy('name')->get(['id', 'name', 'ate_id']),
            'schools' => School::orderBy('short_name')->get(['id', 'short_name', 'ate_id', 'msu_id']),
        ]);
    }
}
