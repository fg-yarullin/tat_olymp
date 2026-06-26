<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Support\Concerns\ParsesImportValues;
use App\Support\SpreadsheetReader;
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
 * Учащиеся своей ОО для школьного оператора: списки, фильтры, создание/редактирование,
 * импорт. Класс — это число (real_grade) + литера (class_letter: прописные буквы и/или
 * цифры, либо пусто), вводятся вручную. СНИЛС необязателен и уникален в рамках ОО.
 */
class StudentController extends Controller
{
    use ParsesImportValues;

    private const TEMPLATE_HEADER = ['ФИО', 'Дата рождения', 'СНИЛС', 'Пол (м/ж)', 'ОВЗ (1/0)', 'Класс', 'Литера'];

    public function index(Request $request): Response
    {
        $schoolId = $request->user()->school_id;
        $q = $request->query('q');
        $grade = $request->query('grade');
        $letter = $request->query('letter');

        $students = Student::query()
            ->where('school_id', $schoolId)
            ->when($q, fn ($query) => $query->where(fn ($w) => $w
                ->where('fio', 'like', "%$q%")->orWhere('snils', 'like', "%$q%")))
            ->when($grade, fn ($query) => $query->where('real_grade', $grade))
            ->when($letter !== null && $letter !== '', fn ($query) => $query->where('class_letter', $letter))
            ->orderBy('real_grade')->orderBy('class_letter')->orderBy('fio')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Student $s) => [
                'id' => $s->id,
                'fio' => $s->fio,
                'birth_date' => $s->birth_date?->toDateString(),
                'gender' => $s->gender,
                'snils' => $s->snils,
                'ovz' => $s->ovz,
                'real_grade' => $s->real_grade,
                'class_letter' => $s->class_letter,
                'class_name' => $s->className(),
                'status' => $s->status,
                'transfer_settlement' => $s->transfer_settlement,
                'transfer_school' => $s->transfer_school,
                'departed_at' => $s->departed_at?->toDateString(),
            ]);

        // Доступные литеры школы — для фильтра.
        $letters = Student::where('school_id', $schoolId)
            ->whereNotNull('class_letter')->where('class_letter', '!=', '')
            ->distinct()->orderBy('class_letter')->pluck('class_letter');

        return Inertia::render('School/Students/Index', [
            'students' => $students,
            'letters' => $letters,
            'filters' => ['q' => $q, 'grade' => $grade, 'letter' => $letter],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $schoolId = $request->user()->school_id;
        Student::create($this->validateData($request, $schoolId, null) + ['school_id' => $schoolId, 'status' => 'active']);

        return back()->with('success', 'Учащийся добавлен.');
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $schoolId = $request->user()->school_id;
        abort_unless($student->school_id === $schoolId, 403);

        $student->update($this->validateData($request, $schoolId, $student->id));

        return back()->with('success', 'Данные учащегося обновлены.');
    }

    /** Отметка о выбытии ученика в другую ОО (с указанием куда). */
    public function markDeparted(Request $request, Student $student): RedirectResponse
    {
        abort_unless($student->school_id === $request->user()->school_id, 403);

        $validated = $request->validate([
            'transfer_settlement' => ['required', 'string', 'max:255'],
            'transfer_school' => ['required', 'string', 'max:255'],
            'departed_at' => ['nullable', 'date'],
        ]);

        $student->update([
            'status' => 'departed',
            'transfer_settlement' => $validated['transfer_settlement'],
            'transfer_school' => $validated['transfer_school'],
            'departed_at' => $validated['departed_at'] ?? now()->toDateString(),
        ]);

        return back()->with('success', 'Ученик отмечен как выбывший.');
    }

    /** Возврат выбывшего ученика в активные. */
    public function restore(Request $request, Student $student): RedirectResponse
    {
        abort_unless($student->school_id === $request->user()->school_id, 403);

        $student->update([
            'status' => 'active', 'transfer_settlement' => null,
            'transfer_school' => null, 'departed_at' => null,
        ]);

        return back()->with('success', 'Ученик возвращён в активные.');
    }

    /** Шаблон CSV со списком учащихся школы для пакетного ввода. */
    public function downloadTemplate(Request $request): StreamedResponse
    {
        $students = Student::where('school_id', $request->user()->school_id)
            ->orderBy('real_grade')->orderBy('class_letter')->orderBy('fio')->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Учащиеся');
        foreach (self::TEMPLATE_HEADER as $i => $title) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).'1', $title);
        }
        $row = 2;
        foreach ($students as $s) {
            $values = [
                $s->fio,
                $s->birth_date?->format('d.m.Y'),
                $s->snils,
                ['male' => 'м', 'female' => 'ж'][$s->gender] ?? '',
                $s->ovz ? '1' : '',
                $s->real_grade,
                $s->class_letter,
            ];
            foreach ($values as $i => $v) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1).$row, (string) $v, DataType::TYPE_STRING);
            }
            $row++;
        }
        foreach (self::TEMPLATE_HEADER as $i => $title) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'shablon_uchashchiesya.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Пакетный импорт учащихся в свою школу. */
    public function import(Request $request): RedirectResponse
    {
        $schoolId = $request->user()->school_id;
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx,ods', 'max:5120']]);

        $file = $request->file('file');
        $rows = array_slice(SpreadsheetReader::rows($file->getRealPath(), $file->getClientOriginalExtension()), 1);
        $created = $updated = 0;
        $errors = [];

        foreach ($rows as $i => $r) {
            $line = $i + 2;
            $fio = trim((string) ($r[0] ?? ''));
            $birth = $this->parseDate((string) ($r[1] ?? ''));
            $snils = trim((string) ($r[2] ?? '')) ?: null;
            $gender = $this->parseGender((string) ($r[3] ?? ''));
            $ovzRaw = trim((string) ($r[4] ?? ''));
            $grade = (int) trim((string) ($r[5] ?? 0));
            $letter = $this->normalizeLetter((string) ($r[6] ?? ''));

            if ($fio === '' && $birth === null) {
                continue;
            }
            if ($fio === '' || $birth === null || $grade < 1 || $grade > 11) {
                $errors[] = "строка $line: пустое ФИО, некорректная дата или класс";
                continue;
            }

            $attributes = [
                'school_id' => $schoolId, 'fio' => $fio, 'gender' => $gender,
                'real_grade' => $grade, 'class_letter' => $letter ?: null,
                'ovz' => $ovzRaw === '' ? null : ($ovzRaw === '1'),
            ];

            // Дедуп: по СНИЛС в рамках ОО, иначе по ФИО+дате.
            $key = $snils
                ? ['school_id' => $schoolId, 'snils' => $snils]
                : ['school_id' => $schoolId, 'fio' => $fio, 'birth_date' => $birth];

            $existing = Student::where($key)->first();
            if ($existing) {
                $existing->update($attributes + ['birth_date' => $birth, 'snils' => $snils]);
                $updated++;
            } else {
                Student::create($attributes + ['birth_date' => $birth, 'snils' => $snils, 'status' => 'active']);
                $created++;
            }
        }

        $summary = "Импорт: добавлено $created, обновлено $updated.";
        if ($errors) {
            return back()->with('success', $summary)
                ->withErrors(['file' => 'Пропущены строки — '.implode('; ', array_slice($errors, 0, 10))]);
        }

        return back()->with('success', $summary);
    }

    private function validateData(Request $request, int $schoolId, ?int $ignoreId): array
    {
        $data = $request->validate([
            'fio' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date'],
            'gender' => ['nullable', 'in:male,female'],
            'snils' => [
                'nullable', 'string', 'max:14',
                Rule::unique('students', 'snils')->where(fn ($q) => $q->where('school_id', $schoolId))->ignore($ignoreId),
            ],
            'ovz' => ['nullable', 'boolean'],
            'real_grade' => ['required', 'integer', 'between:1,11'],
            'class_letter' => ['nullable', 'string', 'max:10'],
        ]);

        $data['class_letter'] = $this->normalizeLetter($data['class_letter'] ?? '') ?: null;

        return $data;
    }

    /** Литера: прописные буквы и/или цифры, без лишних символов; пусто допустимо. */
    private function normalizeLetter(string $raw): string
    {
        return mb_strtoupper(trim($raw));
    }
}
