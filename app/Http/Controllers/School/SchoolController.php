<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Реквизиты своей ОО для школьного оператора — только просмотр (правка в админке).
 */
class SchoolController extends Controller
{
    public function show(Request $request): Response
    {
        $school = $request->user()->school?->load(['msu:id,name', 'ate:id,name']);

        return Inertia::render('School/Info', [
            'school' => $school ? [
                'oo_code' => $school->oo_code,
                'short_name' => $school->short_name,
                'full_name' => $school->full_name,
                'education_level' => $school->education_level,
                'territorial_sign' => $school->territorial_sign,
                'ate' => $school->ate?->name,
                'ate_code' => $school->ate_code,
                'msu' => $school->msu?->name,
                'msu_code' => $school->msu_code,
            ] : null,
        ]);
    }
}
