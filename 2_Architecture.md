# ТЕХНИЧЕСКАЯ АРХИТЕКТУРА И ИНФРАСТРУКТУРА
**Стек проекта:** Laravel Monolith + Inertia.js (React) + MySQL 8.x + Storage (Local/S3)

## 1. СХЕМА МИГРАЦИЙ MYSQL (LARAVEL BLUEPRINT)

### 1.1. Базовые таблицы и Территории
```php
Schema::create('academic_years', function (Blueprint \$table) {
    \(table->id();\)table->string('name', 9); 
    \(table->enum('status', ['current', 'archive'])->default('archive');\)table->timestamps();
});

Schema::create('ates', function (Blueprint \(table) {\)table->id();
    \(table->string('ate_code')->unique();\)table->string('name');
    \(table->enum('type', ['isolated', 'unified']);\)table->timestamps();
});

Schema::create('msus', function (Blueprint \(table) {\)table->id();
    \(table->string('msu_code')->unique();\)table->string('name');
    \(table->foreignId('ate_id')->constrained('ates');\)table->timestamps();
});

Schema::create('schools', function (Blueprint \(table) {\)table->id();
    \(table->string('oo_code')->unique();\)table->string('short_name');
    \(table->text('full_name');\)table->string('oo_type');
    \(table->string('oo_kind');\)table->enum('territorial_sign', ['city', 'rural']);
    \(table->foreignId('msu_id')->constrained('msus');\)table->string('msu_code')->index(); // Индекс для денормализованных отчетов
    \(table->foreignId('ate_id')->constrained('ates');\)table->string('ate_code')->index(); // Индекс для денормализованных отчетов
    \$table->timestamps();
});
```

### 1.2. Олимпиады, Участники и Статистика
```php
Schema::create('olympiads', function (Blueprint \(table) {\)table->id();
    \(table->foreignId('academic_year_id')->constrained('academic_years');\)table->string('subject');
    \(table->enum('stage', ['school', 'municipal', 'regional']);\)table->date('date_held');
    \(table->enum('status', ['planned', 'grading', 'appeal', 'published'])->default('planned');\)table->timestamps();
});

Schema::create('students', function (Blueprint \$table) {
    \(table->id();\)table->string('fio');
    \(table->date('birth_date');\)table->string('snils', 14)->nullable();
    \(table->foreignId('school_id')->constrained('schools');\)table->integer('real_grade');
    \(table->enum('status', ['active', 'graduated', 'transferring'])->default('active');\)table->timestamps();

    // Критический составной индекс для оптимизации гостевого входа без шифров
    \$table->index(['fio', 'birth_date']);
});

Schema::create('human_olympiad', function (Blueprint \$table) {
    \(table->id();\)table->foreignId('student_id')->constrained('students')->onDelete('cascade');
    \(table->foreignId('olympiad_id')->constrained('olympiads');\)table->integer('participation_grade');
    \(table->string('barcode')->nullable()->unique()->index();\)table->float('score')->nullable();
    \(table->enum('result_status', ['participant', 'appealed', 'prize_winner', 'winner', 'disqualified'])->default('participant');\)table->string('scan_path')->nullable(); // Ссылка на файл в Storage
    \$table->timestamps();
});

Schema::create('historical_stats', function (Blueprint \(table) {\)table->id();
    \(table->string('year_name', 9);\)table->string('ate_code');
    \(table->string('msu_code');\)table->string('oo_code');
    \(table->string('subject');\)table->string('stage');
    \(table->integer('total_participants');\)table->integer('total_prizewinner_diplomas');
    \(table->integer('total_winner_diplomas');\)table->timestamps();
});
```

## 2. МАРШРУТИЗАЦИЯ СИСТЕМЫ (`routes/web.php`)

```php
use App\Http\Controllers\Guest\AuthController as GuestAuthController;
use App\Http\Controllers\Guest\ShowcaseController as GuestShowcaseController;
use App\Http\Controllers\School\DashboardController as SchoolDashboardController;
use App\Http\Controllers\School\OlympiadController as SchoolOlympiadController;
use App\Http\Controllers\CityCenter\WizardController as CityWizardController;
use App\Http\Controllers\Admin\MaintenanceController as AdminMaintenanceController;

// Публичные маршруты: Гостевой онлайн-показ (п. 4.6. Вариант 1)
Route::middleware(['web'])->group(function () {
    Route::get('/showcase', [GuestAuthController::class, 'showForm'])->name('guest.login');
    // Защита от перебора: максимум 3 попытки в 10 минут с одного IP/сессии
    Route::post('/showcase', [GuestAuthController::class, 'login'])->middleware('throttle:3,10');
    
    // Контур авторизованного гостя
    Route::middleware(['auth.guest', 'olympiad.window'])->group(function () {
        Route::get('/showcase/work/{humanOlympiad}', [GuestShowcaseController::class, 'view'])->name('guest.work.view');
        Route::post('/showcase/work/{humanOlympiad}/appeal', [GuestShowcaseController::class, 'submitAppeal'])->name('guest.appeal.submit');
    });
});

// Закрытый контур: Веб-интерфейсы сотрудников
Route::middleware(['auth', 'verified', 'user.active'])->group(function () {

    // ЛК Школьного оператора
    Route::middleware(['role:school_operator'])->prefix('school')->group(function () {
        Route::get('/dashboard', [SchoolDashboardController::class, 'index'])->name('school.dashboard');
        Route::post('/olympiads/{olympiad}/import', [SchoolOlympiadController::class, 'importResults'])->name('school.import');
        // Пакетное скачивание сканов в ZIP-архив (п. 4.6. Вариант 2)
        Route::post('/olympiads/{olympiad}/download-zip', [SchoolOlympiadController::class, 'downloadZipArchive'])->name('school.download_zip');
    });

    // ЛК Городского центра (Слепой импорт)
    Route::middleware(['role:super_coordinator'])->prefix('city-center')->group(function () {
        Route::get('/wizard/step-1', [CityWizardController::class, 'stepOne'])->name('city.wizard.1');
        Route::post('/wizard/validate', [CityWizardController::class, 'processFiles'])->name('city.wizard.validate');
        Route::post('/wizard/save', [CityWizardController::class, 'saveResults'])->name('city.wizard.save');
    });

    // Инструменты Администратора
    Route::middleware(['role:admin'])->prefix('admin')->group(function () {
        Route::post('/maintenance/rotate', [AdminMaintenanceController::class, 'triggerNewYear'])->name('admin.rotate');
        Route::post('/maintenance/purge', [AdminMaintenanceController::class, 'runPurgeLifecycle'])->name('admin.purge');
    });
});
```
