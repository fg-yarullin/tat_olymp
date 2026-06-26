# ТЕХНИЧЕСКИЙ БЭКЛОГ РАЗРАБОТКИ (ЗАДАЧИ К РЕАЛИЗАЦИИ)

## 1. РЕАЛИЗАЦИЯ ИНТЕГРАЦИИ INERTIA SHARED DATA

Реализовать прокидывание гостевого и авторизованного контекста на фронтенд React без дополнительных API-запросов.

```php
// app/Http/Middleware/HandleInertiaRequests.php
public function share(Request \$request): array
{
    return array_merge(parent::share(\$request), [
        'auth' => [
            'user' => \$request->user() ? [
                'id' => \$request->user()->id,
                'fio' => \$request->user()->fio,
                'role' => \$request->user()->role,
            ] : null,
            'guest' => \$request->session()->get('guest_student_id') ? [
                'student_id' => \$request->session()->get('guest_student_id'),
            ] : null,
        ],
    ]);
}
```

## 2. КОНТРОЛЛЕР УПРОЩЕННОЙ ГОСТЕВОЙ АВТОРИЗАЦИИ (п. 4.6)

```php
// app/Http/Controllers/Guest/AuthController.php
namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Student;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function login(Request \$request)
    {
        validated = request->validate([
            'fio' => 'required|string|max:255',
            'birth_date' => 'required|date',
        ]);

        // Поиск по составному индексу (fio, birth_date)
        student = Student::where('fio', validated['fio'])
            ->where('birth_date', \$validated['birth_date'])
            ->where('status', 'active')
            ->first();

        if (!\$student) {
            return back()->withErrors([
                'fio' => 'Участник не найден. Проверьте ФИО и дату рождения.'
            ]);
        }

        // Запись маркеров в сессию Web-контура
        session([
            'guest_student_id' => \$student->id,
            'auth_type' => 'guest_student'
        ]);

        return Inertia::render('Guest/AvailableWorksList'); 
    }
}
```

## 3. МОДУЛЬ ПАКЕТНОЙ СБОРКИ ZIP-АРХИВА СКАНОВ (п. 4.6. Вариант 2)

```php
// app/Http/Controllers/School/OlympiadController.php
namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Olympiad;
use App\Models\HumanOlympiad;
use ZipArchive;
use Illuminate\Support\Facades\Storage;

class OlympiadController extends Controller
{
    public function downloadZipArchive(Request request, Olympiad olympiad)
    {
        operator = request->user();
        studentIds = request->input('student_ids'); // Массив ID или null для всех

        \(query = HumanOlympiad::where('olympiad_id',\)olympiad->id)
            ->whereHas('student', function(q) use (operator) {
                \(q->where('school_id',\)operator->school_id);
            });

        if (\(studentIds && is_array(\)studentIds)) {
            \(query->whereIn('student_id',\)studentIds);
        }

        participations = query->with('student')->get();

        if (\$participations->isEmpty()) {
            return back()->withErrors(['error' => 'Нет скан-копий для архивации.']);
        }

        \$zipFileName = "scans_school_{\(operator->school_id}_olympiad_{\)olympiad->id}.zip";
        \(zipPath = storage_path("app/tmp/" . \)zipFileName);

        \$zip = new ZipArchive;
        if (zip->open(zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            foreach (participations as part) {
                if (\(part->scan_path && Storage::exists(\)part->scan_path)) {
                    ext = pathinfo(part->scan_path, PATHINFO_EXTENSION);
                    // Имя файла по ТЗ: [Штрихкод]_[ФИО]_[Класс_участия].pdf
                    \$archiveName = "{\(part->barcode}_{\)part->student->fio}_{\(part->participation_grade}.{\)ext}";
                    \$zip->addFromString(archiveName, Storage::get(part->scan_path));
                }
            }
            \$zip->close();
        }

        return response()->download(\$zipPath)->deleteFileAfterSend(true);
    }
}
```

## 4. ФОНОВЫЙ СКРИПТ ОЧИСТКИ БАЗЫ ДАННЫХ СТАРШЕ 3 ЛЕТ (п. 4.9)

```php
// app/Console/Commands/PurgeOldDataCommand.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AcademicYear;
use App\Models\HumanOlympiad;
use App\Models\HistoricalStat;
use App\Models\Student;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class PurgeOldDataCommand extends Command
{
    protected \$signature = 'app:data-lifecycle-purge';
    protected \$description = 'Агрегирует статистику и стирает ПДн/сканы старше 3 лет';

    public function handle()
    {
        \$threeYearsAgo = now()->subYears(3);
        \(archiveYears = AcademicYear::where('created_at', '<',\)threeYearsAgo)->get();

        foreach (archiveYears as year) {
            DB::transaction(function () use (\$year) {
                
                // 1. Агрегация исторических данных в статичную таблицу (п. 4.9.3 ТЗ)
                \$stats = DB::table('human_olympiad')
                    ->join('olympiads', 'human_olympiad.olympiad_id', '=', 'olympiads.id')
                    ->join('students', 'human_olympiad.student_id', '=', 'students.id')
                    ->join('schools', 'students.school_id', '=', 'schools.id')
                    ->where('olympiads.academic_year_id', \$year->id)
                    ->select(
                        DB::raw("'{\$year->name}' as year_name"),
                        'schools.ate_code', 'schools.msu_code', 'schools.oo_code',
                        'olympiads.subject', 'olympiads.stage',
                        DB::raw('count(human_olympiad.id) as total_participants'),
                        DB::raw("sum(case when human_olympiad.result_status = 'prize_winner' then 1 else 0 end) as total_prizewinner_diplomas"),
                        DB::raw("sum(case when human_olympiad.result_status = 'winner' then 1 else 0 end) as total_winner_diplomas")
                    )
                    ->groupBy('schools.ate_code', 'schools.msu_code', 'schools.oo_code', 'olympiads.subject', 'olympiads.stage')
                    ->get();

                foreach (stats as stat) {
                    HistoricalStat::create((array)\$stat);
                }

                // 2. Удаление медиафайлов сканов с диска
                \$participations = HumanOlympiad::whereHas('olympiad', function (q) use (year) {
                    \(q->where('academic_year_id',\)year->id);
                })->get();

                foreach (participations as part) {
                    if (\(part->scan_path && Storage::exists(\)part->scan_path)) {
                        Storage::delete(\$part->scan_path);
                    }
                    \(part->scan_path = 'deleted_by_timeout';\)part->save();
                }

                // 3. Уничтожение ПДн (ФЗ-152)
                \$studentIds = Student::whereHas('humanOlympiads.olympiad', function(q) use (year) {
                    \(q->where('academic_year_id',\)year->id);
                })->pluck('id');

                HumanOlympiad::whereHas('olympiad', function (q) use (year) {
                    \(q->where('academic_year_id',\)year->id);
                })->delete();

                foreach (studentIds as id) {
                    if (!HumanOlympiad::where('student_id', \$id)->exists()) {
                        Student::where('id', \$id)->delete();
                    }
                }
            });
        }
        
        DB::table('cache')->where('key', 'like', '%throttle%')->delete();
        \$this->info('Регламентная очистка зафиксирована.');
    }
}
```

## 5. РАЗРАБОТКА ИНТЕРФЕЙСОВ REACT (INERTIA PAGES)

### 5.1. Компонент `Pages/Guest/Login.jsx`
* Сверстать форму с двумя полями: ФИО и Дата рождения.
* Использовать хук `useForm` из `@inertiajs/react` для отправки данных методом `post('/showcase')`.
* Не использовать поле токена/шифра школы — форма должна быть максимально чистой.

### 5.2. Компонент `Pages/School/Dashboard.jsx`
* Исключить блоки генерации или вывода секретных шифров.
* Сверстать интерактивную таблицу учеников класса.
* Добавить чекбоксы на строки и глобальную кнопку «Скачать выбранные работы (ZIP)». При клике отправлять POST-запрос на маршрут `school.download_zip` с передачей массива выбранных ID.
