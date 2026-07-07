<?php

use App\Http\Controllers\Guest\AuthController as GuestAuthController;
use App\Http\Controllers\Guest\ShowcaseController as GuestShowcaseController;
use App\Http\Controllers\Admin\AcademicYearController;
use App\Http\Controllers\Admin\CatalogImportController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\OlympiadController as AdminOlympiadController;
use App\Http\Controllers\Admin\ProtocolController as AdminProtocolController;
use App\Http\Controllers\Admin\ResultController;
use App\Http\Controllers\Admin\SnilsAuditController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\Admin\SubjectController;
use App\Http\Controllers\Admin\TechReferenceController;
use App\Http\Controllers\Admin\TerritoryController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\CityCenter\SubjectCoordinatorController as CitySubjectCoordinatorController;
use App\Http\Controllers\Commission\ResultController as CommissionResultController;
use App\Http\Controllers\Municipal\ChairController as MunicipalChairController;
use App\Http\Controllers\Municipal\ProtocolController as MunicipalProtocolController;
use App\Http\Controllers\Municipal\ResultController as MunicipalResultController;
use App\Http\Controllers\Municipal\SchoolController as MunicipalSchoolController;
use App\Http\Controllers\Coordinator\RatingController;
use App\Http\Controllers\Coordinator\ReportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Roc\CoordinatorController as RocCoordinatorController;
use App\Http\Controllers\Roc\ProtocolController as RocProtocolController;
use App\Http\Controllers\School\InvitationController as SchoolInvitationController;
use App\Http\Controllers\School\OlympiadController as SchoolOlympiadController;
use App\Http\Controllers\School\ProtocolController as SchoolProtocolController;
use App\Http\Controllers\School\ResultController as SchoolResultController;
use App\Http\Controllers\School\SchoolController as SchoolInfoController;
use App\Http\Controllers\School\StudentController as SchoolStudentController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

// Публичное краткое расписание (начало + публикация результатов), без входа.
Route::get('/schedule/public', [\App\Http\Controllers\ScheduleController::class, 'publicView'])
    ->name('schedule.public');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Полное расписание со сроками ввода/апелляций — для рабочих ролей.
    Route::get('/schedule', [\App\Http\Controllers\ScheduleController::class, 'index'])->name('schedule');

    // Центр справки: инструкции и FAQ (markdown из docs/).
    Route::get('/help', [\App\Http\Controllers\HelpController::class, 'index'])->name('help.index');
    Route::get('/help/{doc}', [\App\Http\Controllers\HelpController::class, 'show'])->name('help.show');
});

/*
 * Гостевой онлайн-показ работ (ТЗ 4.6, вариант 1).
 */
Route::get('/showcase', [GuestAuthController::class, 'showForm'])->name('guest.login');
// Защита от перебора: не более 3 попыток за 10 минут
Route::post('/showcase', [GuestAuthController::class, 'login'])
    ->middleware('throttle:3,10')->name('guest.login.attempt');
Route::post('/showcase/logout', [GuestAuthController::class, 'logout'])->name('guest.logout');

Route::middleware('auth.guest')->group(function () {
    Route::get('/showcase/works', [GuestShowcaseController::class, 'index'])->name('guest.works');

    // Доступ к конкретной работе — только в окне показа (48 ч + 07:30–19:30)
    Route::middleware('olympiad.window')->group(function () {
        Route::get('/showcase/work/{humanOlympiad}', [GuestShowcaseController::class, 'view'])->name('guest.work.view');
        Route::get('/showcase/work/{humanOlympiad}/scan', [GuestShowcaseController::class, 'scan'])->name('guest.work.scan');
        Route::post('/showcase/work/{humanOlympiad}/appeal', [GuestShowcaseController::class, 'submitAppeal'])->name('guest.appeal.submit');
    });
});

/*
 * ЛК школьного оператора (ТЗ 5, бэклог §3 / §5.2).
 */
Route::middleware(['auth', 'user.active', 'role:school_operator'])
    ->prefix('school')->name('school.')->group(function () {
        Route::get('/info', [SchoolInfoController::class, 'show'])->name('info');

        // Учащиеся своей школы (списки, создание, импорт)
        Route::get('/students', [SchoolStudentController::class, 'index'])->name('students.index');
        Route::post('/students', [SchoolStudentController::class, 'store'])->name('students.store');
        Route::get('/students/template', [SchoolStudentController::class, 'downloadTemplate'])->name('students.template');
        Route::post('/students/import', [SchoolStudentController::class, 'import'])->name('students.import');
        Route::post('/students/import/{bulkImport}/chunk', [SchoolStudentController::class, 'importChunk'])->name('students.import.chunk');
        Route::get('/students/import/{bulkImport}/errors.csv', [SchoolStudentController::class, 'importErrors'])->name('students.import.errors');
        Route::put('/students/{student}', [SchoolStudentController::class, 'update'])->name('students.update');
        Route::post('/students/{student}/depart', [SchoolStudentController::class, 'markDeparted'])->name('students.depart');
        Route::post('/students/{student}/restore', [SchoolStudentController::class, 'restore'])->name('students.restore');

        // Ввод результатов (ручной + массовый)
        Route::get('/results', [SchoolResultController::class, 'index'])->name('results.index');
        Route::get('/results/{olympiad}', [SchoolResultController::class, 'show'])->name('results.show');
        Route::post('/results/{olympiad}', [SchoolResultController::class, 'store'])->name('results.store');
        Route::post('/results/participation/{participation}/score', [SchoolResultController::class, 'updateScore'])->name('results.score');
        Route::post('/results/{olympiad}/auto-status', [SchoolResultController::class, 'autoStatus'])->name('results.auto-status');
        Route::delete('/results/participation/{participation}', [SchoolResultController::class, 'destroy'])->name('results.destroy');
        Route::post('/results/{olympiad}/bulk-destroy', [SchoolResultController::class, 'bulkDestroy'])->name('results.bulk-destroy');
        // Протокол школьного этапа (XLSX по образцу)
        Route::get('/results/{olympiad}/protocol', [SchoolProtocolController::class, 'schoolStage'])->name('results.protocol');
        Route::post('/olympiads/{olympiad}/download-zip', [SchoolOlympiadController::class, 'downloadZipArchive'])
            ->name('olympiad.zip');
        // Школьный этап, ввод баллов (ТЗ 4.2)
        Route::get('/olympiads/{olympiad}/template', [SchoolOlympiadController::class, 'downloadTemplate'])
            ->name('olympiad.template');
        Route::post('/olympiads/{olympiad}/import', [SchoolOlympiadController::class, 'importResults'])
            ->name('olympiad.import');
        Route::post('/olympiads/import/{bulkImport}/chunk', [SchoolOlympiadController::class, 'importResultsChunk'])
            ->name('olympiad.import.chunk');
        Route::get('/olympiads/import/{bulkImport}/errors.csv', [SchoolOlympiadController::class, 'importResultsErrors'])
            ->name('olympiad.import.errors');

        // Приглашённые на муниципальный этап (видимость + выгрузка XLSX)
        Route::get('/invitations', [SchoolInvitationController::class, 'index'])->name('invitations.index');
        Route::get('/invitations/{olympiad}', [SchoolInvitationController::class, 'show'])->name('invitations.show');
        Route::get('/invitations/{olympiad}/list.xlsx', [SchoolInvitationController::class, 'xlsx'])->name('invitations.xlsx');
    });

/*
 * Панель обслуживания администратора (ТЗ 5): ротация (ТЗ 4.1) и очистка БД (ТЗ 4.9).
 */
Route::middleware(['auth', 'user.active', 'role:admin'])
    ->prefix('admin')->name('admin.')->group(function () {
        // Хаб администрирования: карточки всех разделов
        Route::get('/', fn () => Inertia::render('Admin/Home'))->name('home');

        Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance');
        Route::post('/maintenance/rotate', [MaintenanceController::class, 'rotate'])->name('maintenance.rotate');
        Route::post('/maintenance/purge', [MaintenanceController::class, 'purge'])->name('maintenance.purge');

        // Управление пользователями (ТЗ 3)
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('users.destroy');

        // Учебные годы
        Route::get('/academic-years', [AcademicYearController::class, 'index'])->name('years.index');
        Route::post('/academic-years', [AcademicYearController::class, 'store'])->name('years.store');
        Route::put('/academic-years/{academicYear}', [AcademicYearController::class, 'update'])->name('years.update');
        Route::post('/academic-years/{academicYear}/current', [AcademicYearController::class, 'makeCurrent'])->name('years.current');
        Route::delete('/academic-years/{academicYear}', [AcademicYearController::class, 'destroy'])->name('years.destroy');

        // Предметы
        Route::get('/subjects', [SubjectController::class, 'index'])->name('subjects.index');
        Route::post('/subjects', [SubjectController::class, 'store'])->name('subjects.store');
        Route::put('/subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');
        Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy'])->name('subjects.destroy');

        // Справочник технологии: направления и виды практик
        Route::get('/tech-reference', [TechReferenceController::class, 'index'])->name('tech.index');
        Route::post('/tech-reference/profiles', [TechReferenceController::class, 'storeProfile'])->name('tech.profiles.store');
        Route::put('/tech-reference/profiles/{profile}', [TechReferenceController::class, 'updateProfile'])->name('tech.profiles.update');
        Route::delete('/tech-reference/profiles/{profile}', [TechReferenceController::class, 'destroyProfile'])->name('tech.profiles.destroy');
        Route::post('/tech-reference/profiles/{profile}/practices', [TechReferenceController::class, 'storePractice'])->name('tech.practices.store');
        Route::put('/tech-reference/practices/{practice}', [TechReferenceController::class, 'updatePractice'])->name('tech.practices.update');
        Route::delete('/tech-reference/practices/{practice}', [TechReferenceController::class, 'destroyPractice'])->name('tech.practices.destroy');

        // Конструктор протоколов (Вариант 3)
        Route::get('/protocols', [AdminProtocolController::class, 'index'])->name('protocols.index');
        Route::post('/protocols', [AdminProtocolController::class, 'store'])->name('protocols.store');
        Route::post('/protocols/{protocol}/duplicate', [AdminProtocolController::class, 'duplicate'])->name('protocols.duplicate');
        Route::get('/protocols/{protocol}', [AdminProtocolController::class, 'show'])->name('protocols.show');
        Route::put('/protocols/{protocol}', [AdminProtocolController::class, 'update'])->name('protocols.update');
        Route::delete('/protocols/{protocol}', [AdminProtocolController::class, 'destroy'])->name('protocols.destroy');
        Route::post('/protocols/{protocol}/columns', [AdminProtocolController::class, 'storeColumn'])->name('protocols.columns.store');
        Route::post('/protocols/{protocol}/reorder', [AdminProtocolController::class, 'reorder'])->name('protocols.reorder');
        Route::put('/protocols/columns/{column}', [AdminProtocolController::class, 'updateColumn'])->name('protocols.columns.update');
        Route::delete('/protocols/columns/{column}', [AdminProtocolController::class, 'destroyColumn'])->name('protocols.columns.destroy');

        // Просмотр внесённых результатов
        Route::get('/results', [ResultController::class, 'index'])->name('results.index');

        // Олимпиады + публикация результатов (ТЗ 4.6)
        Route::get('/olympiads', [AdminOlympiadController::class, 'index'])->name('olympiads.index');
        Route::post('/olympiads', [AdminOlympiadController::class, 'store'])->name('olympiads.store');
        Route::post('/olympiads/create-municipal', [AdminOlympiadController::class, 'createMunicipalFromSchool'])->name('olympiads.create-municipal');
        Route::put('/olympiads/{olympiad}', [AdminOlympiadController::class, 'update'])->name('olympiads.update');
        Route::post('/olympiads/{olympiad}/publish', [AdminOlympiadController::class, 'publish'])->name('olympiads.publish');
        Route::post('/olympiads/{olympiad}/scans', [AdminOlympiadController::class, 'uploadScans'])->name('olympiads.scans');
        Route::post('/olympiads/{olympiad}/auto-status', [AdminOlympiadController::class, 'autoStatus'])->name('olympiads.auto-status');
        Route::get('/olympiad-schools', [AdminOlympiadController::class, 'schools'])->name('olympiads.schools');
        Route::post('/olympiads/{olympiad}/extend', [AdminOlympiadController::class, 'extend'])->name('olympiads.extend');
        Route::delete('/olympiad-extensions/{extension}', [AdminOlympiadController::class, 'revokeExtension'])->name('olympiads.extensions.destroy');
        Route::delete('/olympiads/{olympiad}', [AdminOlympiadController::class, 'destroy'])->name('olympiads.destroy');

        // Территориальные справочники: АТЕ / МСУ / Школы
        Route::get('/territory', [TerritoryController::class, 'index'])->name('territory.index');
        Route::post('/territory/ates', [TerritoryController::class, 'storeAte'])->name('territory.ate.store');
        Route::put('/territory/ates/{ate}', [TerritoryController::class, 'updateAte'])->name('territory.ate.update');
        Route::delete('/territory/ates/{ate}', [TerritoryController::class, 'destroyAte'])->name('territory.ate.destroy');
        Route::post('/territory/msus', [TerritoryController::class, 'storeMsu'])->name('territory.msu.store');
        Route::put('/territory/msus/{msu}', [TerritoryController::class, 'updateMsu'])->name('territory.msu.update');
        Route::delete('/territory/msus/{msu}', [TerritoryController::class, 'destroyMsu'])->name('territory.msu.destroy');
        Route::post('/territory/schools', [TerritoryController::class, 'storeSchool'])->name('territory.school.store');
        Route::put('/territory/schools/{school}', [TerritoryController::class, 'updateSchool'])->name('territory.school.update');
        Route::delete('/territory/schools/{school}', [TerritoryController::class, 'destroySchool'])->name('territory.school.destroy');
        Route::post('/territory/school-types', [TerritoryController::class, 'storeSchoolType'])->name('territory.school-type.store');
        Route::put('/territory/school-types/{schoolType}', [TerritoryController::class, 'updateSchoolType'])->name('territory.school-type.update');
        Route::delete('/territory/school-types/{schoolType}', [TerritoryController::class, 'destroySchoolType'])->name('territory.school-type.destroy');

        // Аудит СНИЛС (дубли и подозрительные)
        Route::get('/snils-audit', [SnilsAuditController::class, 'index'])->name('snils.audit');

        // Учащиеся
        Route::get('/students', [StudentController::class, 'index'])->name('students.index');
        Route::post('/students', [StudentController::class, 'store'])->name('students.store');
        Route::put('/students/{student}', [StudentController::class, 'update'])->name('students.update');
        Route::delete('/students/{student}', [StudentController::class, 'destroy'])->name('students.destroy');

        // Пакетный импорт справочников из Excel/CSV (ТЗ 4.0)
        Route::get('/imports', [CatalogImportController::class, 'index'])->name('imports.index');
        Route::post('/imports/ates', [CatalogImportController::class, 'importAtes'])->name('imports.ates');
        Route::post('/imports/msus', [CatalogImportController::class, 'importMsus'])->name('imports.msus');
        Route::post('/imports/schools', [CatalogImportController::class, 'importSchools'])->name('imports.schools');
        Route::post('/imports/coordinators', [CatalogImportController::class, 'importCoordinators'])->name('imports.coordinators');
        Route::post('/imports/subjects', [CatalogImportController::class, 'importSubjects'])->name('imports.subjects');
        Route::post('/imports/olympiads', [CatalogImportController::class, 'importOlympiads'])->name('imports.olympiads');
        Route::post('/imports/students', [CatalogImportController::class, 'importStudents'])->name('imports.students');
        Route::post('/imports/users', [CatalogImportController::class, 'importUsers'])->name('imports.users');
        Route::post('/imports/user-imports/{userImport}/chunk', [CatalogImportController::class, 'chunkUserImport'])->name('imports.users.chunk');
        Route::get('/imports/user-imports/{userImport}/errors.csv', [CatalogImportController::class, 'userImportErrors'])->name('imports.users.errors');
        Route::get('/imports/errors', [CatalogImportController::class, 'downloadErrors'])->name('imports.errors');
    });

/*
 * Городской центр Казани: супер-координатор управляет ответственными по предметам.
 */
Route::middleware(['auth', 'user.active', 'role:super_coordinator'])
    ->prefix('city')->name('city.')->group(function () {
        // Ответственные по предметам Казани (права мун. координатора по своим предметам).
        Route::get('/subject-coordinators', [CitySubjectCoordinatorController::class, 'index'])->name('subject-coordinators.index');
        Route::post('/subject-coordinators', [CitySubjectCoordinatorController::class, 'store'])->name('subject-coordinators.store');
        Route::put('/subject-coordinators/{coordinator}', [CitySubjectCoordinatorController::class, 'update'])->name('subject-coordinators.update');
        Route::delete('/subject-coordinators/{coordinator}', [CitySubjectCoordinatorController::class, 'destroy'])->name('subject-coordinators.destroy');
    });

/*
 * Кабинет муниципального координатора АТЕ: состав участников муниципального этапа.
 */
Route::middleware(['auth', 'user.active', 'role:municipal_coordinator,kazan_subject_coordinator,super_coordinator', 'kazan.subject'])
    ->prefix('municipal')->name('municipal.')->group(function () {
        Route::get('/results', [MunicipalResultController::class, 'index'])->name('results.index');
        Route::get('/results/{olympiad}', [MunicipalResultController::class, 'show'])->name('results.show');
        Route::get('/results/{olympiad}/entry', [MunicipalResultController::class, 'entry'])->name('results.entry');
        Route::get('/results/{olympiad}/invited.xlsx', [MunicipalResultController::class, 'invitedXlsx'])->name('results.invited');
        Route::get('/results/{olympiad}/protocol.xlsx', [MunicipalProtocolController::class, 'municipalStage'])->name('results.protocol');
        Route::get('/results/{olympiad}/school-stage', [MunicipalResultController::class, 'schoolStageResults'])->name('results.school-stage');
        Route::get('/results/{olympiad}/school-stage.xlsx', [MunicipalResultController::class, 'exportSchoolStage'])->name('results.school-stage-export');
        Route::post('/results/{olympiad}/import-invited', [MunicipalResultController::class, 'importInvited'])->name('results.import-invited');
        Route::get('/results/{olympiad}/cipher-template.xlsx', [MunicipalResultController::class, 'cipherTemplateXlsx'])->name('results.cipher-template');
        Route::post('/results/{olympiad}/import-ciphers', [MunicipalResultController::class, 'importCiphers'])->name('results.import-ciphers');
        Route::post('/results/{olympiad}/scans', [MunicipalResultController::class, 'uploadScans'])->name('results.scans');
        Route::get('/results/{olympiad}/score-template.xlsx', [MunicipalResultController::class, 'scoreTemplateXlsx'])->name('results.score-template');
        Route::post('/results/{olympiad}/import-scores', [MunicipalResultController::class, 'importScores'])->name('results.import-scores');
        Route::post('/results/import-scores/{bulkImport}/chunk', [MunicipalResultController::class, 'importScoresChunk'])->name('results.import-scores.chunk');
        Route::get('/results/import-scores/{bulkImport}/errors.csv', [MunicipalResultController::class, 'importScoresErrors'])->name('results.import-scores.errors');
        Route::get('/results/{olympiad}/appeal-template.xlsx', [MunicipalResultController::class, 'appealTemplateXlsx'])->name('results.appeal-template');
        Route::post('/results/{olympiad}/import-appeals', [MunicipalResultController::class, 'importAppeals'])->name('results.import-appeals');
        Route::post('/results/import-appeals/{bulkImport}/chunk', [MunicipalResultController::class, 'importAppealsChunk'])->name('results.import-appeals.chunk');
        Route::get('/results/import-appeals/{bulkImport}/errors.csv', [MunicipalResultController::class, 'importAppealsErrors'])->name('results.import-appeals.errors');
        Route::post('/results/{olympiad}/compose', [MunicipalResultController::class, 'composeFromStages'])->name('results.compose');
        Route::post('/results/{olympiad}/compose-top-n', [MunicipalResultController::class, 'composeTopN'])->name('results.compose-top-n');
        Route::post('/results/{olympiad}/compose-top-n-school', [MunicipalResultController::class, 'composeTopNPerSchool'])->name('results.compose-top-n-school');
        Route::post('/results/{olympiad}/external', [MunicipalResultController::class, 'external'])->name('results.external');
        Route::post('/results/{olympiad}', [MunicipalResultController::class, 'store'])->name('results.store');
        Route::post('/results/participation/{participation}/cipher', [MunicipalResultController::class, 'storeCipher'])->name('results.cipher');
        Route::post('/results/participation/{participation}/primary', [MunicipalResultController::class, 'storePrimary'])->name('results.primary');
        Route::post('/results/participation/{participation}/appeal', [MunicipalResultController::class, 'storeAppeal'])->name('results.appeal');
        Route::delete('/results/participation/{participation}', [MunicipalResultController::class, 'destroy'])->name('results.destroy');
        Route::post('/results/{olympiad}/bulk-destroy', [MunicipalResultController::class, 'bulkDestroy'])->name('results.bulk-destroy');
        Route::post('/results/{olympiad}/clear-scores', [MunicipalResultController::class, 'clearScores'])->name('results.clear-scores');

        // Председатели предметных комиссий своего АТЕ (создание с привязкой к олимпиаде)
        Route::get('/chairs', [MunicipalChairController::class, 'index'])->name('chairs.index');
        Route::post('/chairs', [MunicipalChairController::class, 'store'])->name('chairs.store');
        Route::put('/chairs/{chair}', [MunicipalChairController::class, 'update'])->name('chairs.update');
        Route::delete('/chairs/{chair}', [MunicipalChairController::class, 'destroy'])->name('chairs.destroy');
    });

/*
 * Управление школами своего АТЕ: муниципальный координатор и супер-координатор Казани
 * (зонтичный скоуп — все районы Казани). Просмотр/добавление/редактирование, без удаления.
 */
Route::middleware(['auth', 'user.active', 'role:municipal_coordinator,super_coordinator'])
    ->prefix('municipal')->name('municipal.')->group(function () {
        Route::get('/schools', [MunicipalSchoolController::class, 'index'])->name('schools.index');
        Route::post('/schools', [MunicipalSchoolController::class, 'store'])->name('schools.store');
        Route::put('/schools/{school}', [MunicipalSchoolController::class, 'update'])->name('schools.update');
    });

/*
 * Кабинет председателя предметной комиссии МЭ: обезличенный ввод первичных результатов.
 */
Route::middleware(['auth', 'user.active', 'role:commission_chair'])
    ->prefix('commission')->name('commission.')->group(function () {
        Route::get('/results', [CommissionResultController::class, 'index'])->name('results.index');
        Route::get('/results/{olympiad}', [CommissionResultController::class, 'show'])->name('results.show');
        Route::post('/results/participation/{participation}/primary', [CommissionResultController::class, 'storePrimary'])->name('results.primary');
        Route::get('/results/{olympiad}/score-template.xlsx', [CommissionResultController::class, 'scoreTemplateXlsx'])->name('results.score-template');
        Route::post('/results/{olympiad}/import', [CommissionResultController::class, 'importPrimary'])->name('results.import');
        Route::post('/results/import/{bulkImport}/chunk', [CommissionResultController::class, 'importPrimaryChunk'])->name('results.import.chunk');
        Route::get('/results/import/{bulkImport}/errors.csv', [CommissionResultController::class, 'importPrimaryErrors'])->name('results.import.errors');
    });

/*
 * Кабинет РОЦ РТ: представитель и координатор по предмету — просмотр и выгрузка протоколов
 * ШЭ/МЭ по фильтрам. Управление координаторами доступно только представителю.
 */
Route::middleware(['auth', 'user.active', 'role:roc_representative,roc_subject_coordinator'])
    ->prefix('roc')->name('roc.')->group(function () {
        Route::get('/olympiads', [RocProtocolController::class, 'index'])->name('olympiads.index');
        Route::get('/olympiads/{olympiad}', [RocProtocolController::class, 'show'])->name('olympiads.show');
        Route::get('/olympiads/{olympiad}/protocol.xlsx', [RocProtocolController::class, 'exportProtocol'])->name('olympiads.protocol');
    });

Route::middleware(['auth', 'user.active', 'role:roc_representative'])
    ->prefix('roc')->name('roc.')->group(function () {
        Route::get('/coordinators', [RocCoordinatorController::class, 'index'])->name('coordinators.index');
        Route::post('/coordinators', [RocCoordinatorController::class, 'store'])->name('coordinators.store');
        Route::put('/coordinators/{coordinator}', [RocCoordinatorController::class, 'update'])->name('coordinators.update');
        Route::delete('/coordinators/{coordinator}', [RocCoordinatorController::class, 'destroy'])->name('coordinators.destroy');
    });

/*
 * Аналитика и рейтинги (ТЗ 4.4): координаторы АТЕ/Казани и администратор.
 */
Route::middleware(['auth', 'user.active', 'role:admin,municipal_coordinator,super_coordinator'])
    ->prefix('analytics')->name('analytics.')->group(function () {
        Route::get('/ratings', [RatingController::class, 'index'])->name('ratings');
        // Министерская отчётность: экспорт ранжированного протокола в Excel (ТЗ 4.5)
        Route::get('/ratings/export.xlsx', [ReportController::class, 'ratingsXlsx'])->name('ratings.xlsx');
    });

require __DIR__.'/auth.php';
