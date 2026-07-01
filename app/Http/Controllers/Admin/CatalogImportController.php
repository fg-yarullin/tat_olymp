<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\Olympiad;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserImport;
use App\Support\Concerns\ParsesImportValues;
use App\Support\ImportResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Пакетный импорт из Excel/CSV (ТЗ 4.0) — справочники и данные.
 * Все импорты — upsert по естественному ключу (не затирают остальные записи).
 * Проблемные строки накапливаются с исходными ячейками и выгружаются отдельным CSV
 * (готовым к повторному импорту после правки).
 */
class CatalogImportController extends Controller
{
    use ParsesImportValues;

    private const STAGES = ['school', 'municipal', 'regional'];
    private const OLYMPIAD_STATUSES = ['planned', 'grading', 'appeal', 'published'];
    private const STUDENT_STATUSES = ['active', 'graduated', 'transferring'];
    private const SESSION_KEY = 'import_errors';

    /** Заголовок последнего загруженного файла (для выгрузки ошибок). */
    private array $header = [];

    public function index(): Response
    {
        $errors = session(self::SESSION_KEY);

        return Inertia::render('Admin/Imports/Index', [
            'counts' => [
                'ates' => Ate::count(),
                'msus' => Msu::count(),
                'schools' => School::count(),
                'subjects' => Subject::count(),
                'olympiads' => Olympiad::count(),
                'students' => Student::count(),
                'users' => User::count(),
            ],
            'coordinatorsCount' => User::whereIn('role', [
                'super_coordinator', 'municipal_coordinator', 'school_operator',
            ])->count(),
            'importErrors' => $errors
                ? ['label' => $errors['label'], 'count' => count($errors['failures'])]
                : null,
        ]);
    }

    /** Выгрузка строк с ошибками последнего импорта (исходные ячейки + столбец «Ошибка»). */
    public function downloadErrors(): StreamedResponse|RedirectResponse
    {
        $data = session()->pull(self::SESSION_KEY);
        if (! $data) {
            return redirect()->route('admin.imports.index');
        }

        $filename = 'import_errors_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM для Excel
            fputcsv($out, array_merge($data['header'] ?: [], ['Ошибка']), ';');
            foreach ($data['failures'] as $f) {
                fputcsv($out, array_merge($f['row'], [$f['reason']]), ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---- Справочники территории ----

    public function importAtes(Request $request): RedirectResponse
    {
        $rows = $this->validateAndRead($request);
        $result = new ImportResult();

        DB::transaction(function () use ($rows, $result) {
            foreach ($rows as $i => $r) {
                $line = $i + 2;
                $code = trim((string) ($r[1] ?? ''));
                $name = trim((string) ($r[2] ?? ''));
                if ($code === '' && $name === '') {
                    continue;
                }
                if ($code === '' || $name === '') {
                    $result->fail($line, 'пустой код или название', $r);
                    continue;
                }
                $ate = Ate::firstWhere('ate_code', $code);
                if ($ate) {
                    $ate->update(['name' => $name]);
                    $result->updated++;
                } else {
                    Ate::create(['ate_code' => $code, 'name' => $name, 'type' => 'isolated']);
                    $result->created++;
                }
            }
        });

        return $this->finish('АТЕ', $result);
    }

    public function importMsus(Request $request): RedirectResponse
    {
        $rows = $this->validateAndRead($request);
        $ateByCode = Ate::pluck('id', 'ate_code');
        $result = new ImportResult();

        DB::transaction(function () use ($rows, $ateByCode, $result) {
            foreach ($rows as $i => $r) {
                $line = $i + 2;
                $ateCode = trim((string) ($r[1] ?? ''));
                $code = trim((string) ($r[2] ?? ''));
                $name = trim((string) ($r[3] ?? ''));
                if ($ateCode === '' && $code === '' && $name === '') {
                    continue;
                }
                if (! $ateByCode->has($ateCode)) {
                    $result->fail($line, "неизвестный код АТЕ «{$ateCode}»", $r);
                    continue;
                }
                if ($code === '' || $name === '') {
                    $result->fail($line, 'пустой код или название МСУ', $r);
                    continue;
                }
                $msu = Msu::firstWhere('msu_code', $code);
                if ($msu) {
                    $msu->update(['name' => $name, 'ate_id' => $ateByCode[$ateCode]]);
                    $result->updated++;
                } else {
                    Msu::create(['msu_code' => $code, 'name' => $name, 'ate_id' => $ateByCode[$ateCode]]);
                    $result->created++;
                }
            }

            $counts = Msu::selectRaw('ate_id, COUNT(*) as c')->groupBy('ate_id')->pluck('c', 'ate_id');
            Ate::all()->each(fn (Ate $a) => $a->update([
                'type' => ($counts[$a->id] ?? 0) > 1 ? 'unified' : 'isolated',
            ]));
        });

        return $this->finish('МСУ', $result);
    }

    public function importSchools(Request $request): RedirectResponse
    {
        $rows = $this->validateAndRead($request);
        $msuMeta = Msu::with('ate:id,ate_code')->get()
            ->keyBy('msu_code')
            ->map(fn (Msu $m) => ['id' => $m->id, 'ate_id' => $m->ate_id, 'ate_code' => $m->ate?->ate_code]);
        $result = new ImportResult();

        DB::transaction(function () use ($rows, $msuMeta, $result) {
            foreach ($rows as $i => $r) {
                $line = $i + 2;
                $ooCode = trim((string) ($r[1] ?? ''));
                if ($ooCode === '') {
                    continue;
                }
                $msuCode = trim((string) ($r[6] ?? ''));
                if (! $msuMeta->has($msuCode)) {
                    $result->fail($line, "неизвестный код МСУ «{$msuCode}»", $r);
                    continue;
                }
                $meta = $msuMeta[$msuCode];

                $attributes = [
                    'short_name' => trim((string) ($r[3] ?? '')),
                    'full_name' => trim((string) ($r[2] ?? '')),
                    'education_level' => (int) trim((string) ($r[4] ?? 0)),
                    'territorial_sign' => trim((string) ($r[7] ?? '')) === '1' ? 'city' : 'rural',
                    'msu_id' => $meta['id'], 'msu_code' => $msuCode,
                    'ate_id' => $meta['ate_id'], 'ate_code' => $meta['ate_code'],
                ];

                $school = School::firstWhere('oo_code', $ooCode);
                if ($school) {
                    $school->update($attributes);
                    $result->updated++;
                } else {
                    School::create(['oo_code' => $ooCode] + $attributes);
                    $result->created++;
                }
            }
        });

        return $this->finish('Школы', $result);
    }

    // ---- Предметы ----

    public function importSubjects(Request $request): RedirectResponse
    {
        $rows = $this->validateAndRead($request);
        $result = new ImportResult();

        DB::transaction(function () use ($rows, $result) {
            foreach ($rows as $i => $r) {
                $name = trim((string) ($r[0] ?? ''));
                if ($name === '') {
                    continue;
                }
                $active = trim((string) ($r[1] ?? '1')) !== '0';

                $subject = Subject::firstWhere('name', $name);
                if ($subject) {
                    $subject->update(['is_active' => $active]);
                    $result->updated++;
                } else {
                    Subject::create(['name' => $name, 'is_active' => $active]);
                    $result->created++;
                }
            }
        });

        return $this->finish('Предметы', $result);
    }

    // ---- Олимпиады (upsert по год+предмет+этап) ----

    public function importOlympiads(Request $request): RedirectResponse
    {
        $rows = $this->validateAndRead($request);
        $yearByName = AcademicYear::pluck('id', 'name');
        $subjectByName = Subject::pluck('id', 'name');
        $result = new ImportResult();

        DB::transaction(function () use ($rows, $yearByName, $subjectByName, $result) {
            foreach ($rows as $i => $r) {
                $line = $i + 2;
                $yearName = trim((string) ($r[0] ?? ''));
                $subjectName = trim((string) ($r[1] ?? ''));
                $stage = trim((string) ($r[2] ?? ''));
                $status = trim((string) ($r[3] ?? 'planned')) ?: 'planned';
                $date = trim((string) ($r[4] ?? ''));
                if ($yearName === '' && $subjectName === '') {
                    continue;
                }
                if (! $yearByName->has($yearName)) {
                    $result->fail($line, "неизвестный учебный год «{$yearName}»", $r);
                    continue;
                }
                if (! $subjectByName->has($subjectName)) {
                    $result->fail($line, "неизвестный предмет «{$subjectName}»", $r);
                    continue;
                }
                if (! in_array($stage, self::STAGES, true)) {
                    $result->fail($line, "недопустимый этап «{$stage}»", $r);
                    continue;
                }
                if (! in_array($status, self::OLYMPIAD_STATUSES, true)) {
                    $result->fail($line, "недопустимый статус «{$status}»", $r);
                    continue;
                }
                if (($iso = $this->parseDate($date)) === null) {
                    $result->fail($line, "некорректная дата «{$date}»", $r);
                    continue;
                }
                $deadlineRaw = trim((string) ($r[5] ?? ''));
                $finalRaw = trim((string) ($r[6] ?? ''));
                $deadline = $deadlineRaw === '' ? null : $this->parseDateTime($deadlineRaw);
                $finalDeadline = $finalRaw === '' ? null : $this->parseDateTime($finalRaw);
                if (($deadlineRaw !== '' && $deadline === null) || ($finalRaw !== '' && $finalDeadline === null)) {
                    $result->fail($line, 'некорректный срок внесения результатов', $r);
                    continue;
                }

                $grades = Olympiad::parseGradesSpec((string) ($r[7] ?? ''));
                $olympiad = Olympiad::firstOrNew([
                    'academic_year_id' => $yearByName[$yearName],
                    'subject_id' => $subjectByName[$subjectName],
                    'stage' => $stage,
                    'grades' => $grades,
                ]);
                $exists = $olympiad->exists;

                $olympiad->subject = $subjectName;
                $olympiad->level = $this->parseLevel((string) ($r[8] ?? ''));
                $olympiad->date_held = $iso;
                $olympiad->results_deadline = $deadline;
                $olympiad->final_results_deadline = $finalDeadline;
                // Поле «status» в файле сохранено для совместимости; влияет только признак публикации.
                if ($status === 'published' && $olympiad->published_at === null) {
                    $olympiad->published_at = now();
                }
                $olympiad->save();

                $exists ? $result->updated++ : $result->created++;
            }
        });

        return $this->finish('Олимпиады', $result);
    }

    // ---- Учащиеся (upsert по ФИО+дата рождения) ----

    public function importStudents(Request $request): RedirectResponse
    {
        $rows = $this->validateAndRead($request);
        $schoolByCode = School::pluck('id', 'oo_code');
        $result = new ImportResult();

        DB::transaction(function () use ($rows, $schoolByCode, $result) {
            foreach ($rows as $i => $r) {
                $line = $i + 2;
                $fio = trim((string) ($r[0] ?? ''));
                $birthRaw = trim((string) ($r[1] ?? ''));
                $snils = trim((string) ($r[2] ?? '')) ?: null;
                $ooCode = trim((string) ($r[3] ?? ''));
                $grade = (int) trim((string) ($r[4] ?? 0));
                $status = trim((string) ($r[5] ?? 'active')) ?: 'active';
                $ovzRaw = trim((string) ($r[6] ?? ''));
                $ovz = $ovzRaw === '' ? null : $ovzRaw === '1';
                $gender = $this->parseGender((string) ($r[7] ?? ''));

                if ($fio === '' && $birthRaw === '') {
                    continue;
                }
                // Школы вносят дату как ДД.ММ.ГГГГ/ДД.ММ.ГГ — нормализуем к ISO.
                $birth = $this->parseDate($birthRaw);
                if ($fio === '' || $birth === null) {
                    $result->fail($line, "пустое ФИО или некорректная дата рождения «{$birthRaw}»", $r);
                    continue;
                }
                if (! $schoolByCode->has($ooCode)) {
                    $result->fail($line, "неизвестный код ОО «{$ooCode}»", $r);
                    continue;
                }
                if ($grade < 1 || $grade > 11) {
                    $result->fail($line, 'класс вне диапазона 1–11', $r);
                    continue;
                }
                if (! in_array($status, self::STUDENT_STATUSES, true)) {
                    $result->fail($line, "недопустимый статус «{$status}»", $r);
                    continue;
                }

                $student = Student::where('fio', $fio)->whereDate('birth_date', $birth)->first();
                $attributes = [
                    'gender' => $gender, 'snils' => $snils, 'school_id' => $schoolByCode[$ooCode],
                    'real_grade' => $grade, 'status' => $status, 'ovz' => $ovz,
                ];

                if ($student) {
                    if ($student->anonymized_at !== null) {
                        $result->fail($line, 'запись обезличена (ФЗ-152), пропущена', $r);
                        continue;
                    }
                    $student->update($attributes);
                    $result->updated++;
                } else {
                    Student::create(['fio' => $fio, 'birth_date' => $birth] + $attributes);
                    $result->created++;
                }
            }
        });

        return $this->finish('Учащиеся', $result);
    }

    // ---- Пользователи / координаторы ----

    public function importUsers(Request $request): JsonResponse
    {
        return $this->startUserImport($request, ['admin', 'super_coordinator', 'municipal_coordinator', 'school_operator'], 'Пользователи');
    }

    public function importCoordinators(Request $request): JsonResponse
    {
        return $this->startUserImport($request, ['super_coordinator', 'municipal_coordinator', 'school_operator'], 'Координаторы');
    }

    /** Загрузка файла → запись фонового импорта со строками. Обработка — чанками (без таймаута/воркера). */
    private function startUserImport(Request $request, array $allowedRoles, string $label): JsonResponse
    {
        $rows = $this->validateAndRead($request);

        $import = UserImport::create([
            'user_id' => $request->user()->id,
            'label' => $label,
            'allowed_roles' => $allowedRoles,
            'header' => $this->header,
            'rows' => array_values($rows),
            'total' => count($rows),
        ]);

        return response()->json(['id' => $import->id, 'total' => $import->total]);
    }

    /** Обработка очередной части строк (вызывается фронтендом в цикле); возвращает прогресс. */
    public function chunkUserImport(Request $request, UserImport $userImport): JsonResponse
    {
        abort_unless($userImport->user_id === $request->user()->id, 403);
        set_time_limit(0);

        if ($userImport->status !== 'done') {
            $size = 100;
            $slice = array_slice($userImport->rows, $userImport->processed, $size);
            $ateByCode = Ate::pluck('id', 'ate_code');
            $schoolByCode = School::pluck('id', 'oo_code');
            $result = new ImportResult();

            DB::transaction(function () use ($slice, $userImport, $ateByCode, $schoolByCode, $result) {
                foreach ($slice as $i => $r) {
                    $line = $userImport->processed + $i + 2; // +1 заголовок, +1 на 1-индекс
                    $this->applyUserRow((array) $r, $line, $userImport->allowed_roles, $ateByCode, $schoolByCode, $result);
                }
            });

            $userImport->created_count += $result->created;
            $userImport->updated_count += $result->updated;
            $userImport->failed_count += count($result->failures);
            $userImport->errors = array_merge($userImport->errors ?? [], $result->failures);
            $userImport->processed = min($userImport->processed + count($slice), $userImport->total);
            if ($userImport->processed >= $userImport->total) {
                $userImport->status = 'done';
            }
            $userImport->save();
        }

        return response()->json($this->importProgress($userImport));
    }

    private function importProgress(UserImport $i): array
    {
        return [
            'id' => $i->id, 'label' => $i->label, 'total' => $i->total, 'processed' => $i->processed,
            'created' => $i->created_count, 'updated' => $i->updated_count, 'failed' => $i->failed_count,
            'done' => $i->status === 'done',
        ];
    }

    /** Выгрузка проблемных строк фонового импорта пользователей (исходные ячейки + «Ошибка»). */
    public function userImportErrors(Request $request, UserImport $userImport): StreamedResponse|RedirectResponse
    {
        abort_unless($userImport->user_id === $request->user()->id, 403);
        $failures = $userImport->errors ?? [];
        if (! $failures) {
            return redirect()->route('admin.imports.index');
        }
        $header = $userImport->header ?? [];
        $filename = 'import_errors_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($failures, $header) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_merge($header, ['Ошибка']), ';');
            foreach ($failures as $f) {
                fputcsv($out, array_merge((array) $f['row'], [$f['reason']]), ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Обработка одной строки пользователя. Колонки: ФИО, email, роль, код_привязки, пароль. */
    private function applyUserRow(array $r, int $line, array $allowedRoles, $ateByCode, $schoolByCode, ImportResult $result): void
    {
        $fio = trim((string) ($r[0] ?? ''));
        $email = trim((string) ($r[1] ?? ''));
        $role = trim((string) ($r[2] ?? ''));
        $code = trim((string) ($r[3] ?? ''));
        $password = trim((string) ($r[4] ?? ''));

        if ($fio === '' && $email === '') {
            return;
        }
        if ($fio === '' || $email === '' || $role === '') {
            $result->fail($line, 'ФИО, email и роль обязательны', $r);

            return;
        }
        if (! in_array($role, $allowedRoles, true)) {
            $result->fail($line, "недопустимая роль «{$role}»", $r);

            return;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $result->fail($line, "некорректный email «{$email}»", $r);

            return;
        }

        $ateId = $schoolId = null;
        if ($role === 'school_operator') {
            if (! $schoolByCode->has($code)) {
                $result->fail($line, "неизвестный код ОО «{$code}»", $r);

                return;
            }
            $schoolId = $schoolByCode[$code];
        } elseif ($role !== 'admin') {
            if (! $ateByCode->has($code)) {
                $result->fail($line, "неизвестный код АТЕ «{$code}»", $r);

                return;
            }
            $ateId = $ateByCode[$code];
        }

        $user = User::firstWhere('email', $email);
        if ($user) {
            $attrs = ['name' => $fio, 'role' => $role, 'ate_id' => $ateId, 'school_id' => $schoolId];
            if ($password !== '') {
                if (strlen($password) < 8) {
                    $result->fail($line, 'пароль короче 8 символов', $r);

                    return;
                }
                $attrs['password'] = Hash::make($password);
            }
            $user->update($attrs);
            $result->updated++;
        } else {
            if (strlen($password) < 8) {
                $result->fail($line, 'для нового пользователя нужен пароль (мин. 8 символов)', $r);

                return;
            }
            User::create([
                'name' => $fio, 'email' => $email, 'role' => $role,
                'ate_id' => $ateId, 'school_id' => $schoolId,
                'password' => Hash::make($password), 'is_active' => true, 'email_verified_at' => now(),
            ]);
            $result->created++;
        }
    }

    // ---- Общая инфраструктура ----

    /**
     * Формирует flash-сводку, складывает ошибочные строки в сессию для выгрузки,
     * либо очищает её при чистом импорте.
     */
    private function finish(string $label, ImportResult $result): RedirectResponse
    {
        $msg = "$label: добавлено {$result->created}, обновлено {$result->updated}.";

        if (! $result->hasFailures()) {
            session()->forget(self::SESSION_KEY);

            return back()->with('success', $msg);
        }

        session()->put(self::SESSION_KEY, [
            'label' => $label,
            'header' => $this->header,
            'failures' => $result->failures,
        ]);

        $preview = array_map(
            fn ($f) => "строка {$f['line']}: {$f['reason']}",
            array_slice($result->failures, 0, 10),
        );

        return back()->with('success', $msg)->withErrors([
            'import' => 'Пропущено строк: '.count($result->failures).'. '.implode('; ', $preview),
        ]);
    }

    /** Валидирует загруженный файл, запоминает заголовок и возвращает строки данных. */
    private function validateAndRead(Request $request): array
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:20480'],
        ]);

        $rows = $this->readRows($request->file('file'));
        $this->header = $rows[0] ?? [];

        return array_slice($rows, 1);
    }

    /** Универсальное чтение: .xlsx через PhpSpreadsheet, .csv/.txt напрямую. */
    private function readRows(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        if ($ext === 'csv' || $ext === 'txt') {
            $rows = [];
            $handle = fopen($file->getRealPath(), 'r');
            $delimiter = null;
            while (($line = fgets($handle)) !== false) {
                if ($delimiter === null) {
                    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
                    $delimiter = substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
                }
                if (trim($line) === '') {
                    continue;
                }
                $rows[] = str_getcsv($line, $delimiter);
            }
            fclose($handle);

            return $rows;
        }

        return IOFactory::load($file->getRealPath())->getActiveSheet()->toArray();
    }
}
