<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\HumanOlympiad;
use App\Models\Msu;
use App\Models\Olympiad;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminOlympiadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function year(): AcademicYear
    {
        return AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
    }

    public function test_index_filters_by_subject_search_hide_stage_and_level(): void
    {
        $year = $this->year();
        $math = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Математика', 'stage' => 'school', 'level' => 'regional', 'grades' => '7', 'date_held' => '2025-11-01', 'status' => 'grading']);
        $physMun = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal', 'level' => 'republican', 'grades' => '7', 'date_held' => '2025-12-01', 'status' => 'grading']);
        $physSchool = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'school', 'level' => 'regional', 'grades' => '8', 'date_held' => '2025-11-01', 'status' => 'grading']);
        $admin = $this->admin();

        // Поиск по предмету.
        $this->actingAs($admin)->get(route('admin.olympiads.index', ['q' => 'Физ']))
            ->assertInertia(fn ($p) => $p->where('olympiads.data', fn ($d) => collect($d)->pluck('id')->sort()->values()->all() === collect([$physMun->id, $physSchool->id])->sort()->values()->all()));

        // Скрытие школьного этапа чекбоксом — остаются только не-школьные.
        $this->actingAs($admin)->get(route('admin.olympiads.index', ['hide_school' => 1]))
            ->assertInertia(fn ($p) => $p->where('olympiads.data', fn ($d) => collect($d)->pluck('id')->all() === [$physMun->id])
                ->where('filters.hide_school', true));

        // Состояние сохраняется в настройках пользователя: следующий запрос без параметра — школьный скрыт.
        $this->actingAs($admin)->get(route('admin.olympiads.index'))
            ->assertInertia(fn ($p) => $p->where('olympiads.data', fn ($d) => collect($d)->pluck('id')->all() === [$physMun->id]));

        // Переживает выход/повторный вход (хранится на пользователе, а не в сессии).
        auth()->logout();
        $this->assertEquals(true, $admin->fresh()->ui_preferences['admin_olympiads_hide_school']);
        $this->actingAs($admin->fresh())->get(route('admin.olympiads.index'))
            ->assertInertia(fn ($p) => $p->where('olympiads.data', fn ($d) => collect($d)->pluck('id')->all() === [$physMun->id]));

        // Снимаем скрытие — школьные снова видны.
        $this->actingAs($admin)->get(route('admin.olympiads.index', ['hide_school' => 0]))
            ->assertInertia(fn ($p) => $p->where('olympiads.data', fn ($d) => collect($d)->count() === 3));

        // Фильтр по уровню.
        $this->actingAs($admin)->get(route('admin.olympiads.index', ['level' => 'republican']))
            ->assertInertia(fn ($p) => $p->where('olympiads.data', fn ($d) => collect($d)->pluck('id')->all() === [$physMun->id]));
    }

    public function test_create_municipal_from_school_computes_deadlines(): void
    {
        $year = $this->year();
        $phys = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $chem = Subject::create(['name' => 'Химия', 'is_active' => true]);

        $physShe = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $phys->id,
            'stage' => 'school', 'level' => 'republican', 'grades' => '7,8', 'date_held' => '2025-11-15']);
        // У химии МЭ уже существует — должна пропуститься.
        $chemShe = Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Химия', 'subject_id' => $chem->id,
            'stage' => 'school', 'level' => 'regional', 'grades' => '9', 'date_held' => '2025-11-10']);
        Olympiad::create(['academic_year_id' => $year->id, 'subject' => 'Химия', 'subject_id' => $chem->id,
            'stage' => 'municipal', 'level' => 'regional', 'grades' => '9', 'date_held' => '2025-12-10']);

        $this->actingAs($this->admin())
            ->post(route('admin.olympiads.create-municipal'), ['school_olympiad_ids' => [$physShe->id, $chemShe->id]])
            ->assertSessionHasNoErrors();

        $munPhys = Olympiad::where('stage', 'municipal')->where('subject_id', $phys->id)->first();
        $this->assertNotNull($munPhys);
        $this->assertSame('7,8', $munPhys->grades);
        $this->assertSame('republican', $munPhys->level);
        $this->assertSame('2025-12-15', $munPhys->date_held->toDateString());
        $this->assertSame('2025-12-20 16:00', $munPhys->results_deadline->format('Y-m-d H:i'));
        $this->assertSame('2025-12-23 16:00', $munPhys->final_results_deadline->format('Y-m-d H:i'));

        // Химия — МЭ не задублировалась (осталась одна).
        $this->assertSame(1, Olympiad::where('stage', 'municipal')->where('subject_id', $chem->id)->count());
    }

    public function test_store_sets_denormalized_subject_string_from_reference(): void
    {
        $year = $this->year();
        $subject = Subject::create(['name' => 'Химия', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('admin.olympiads.store'), [
                'academic_year_id' => $year->id, 'subject_id' => $subject->id,
                'stage' => 'municipal', 'status' => 'planned', 'date_held' => '2025-12-01',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('olympiads', [
            'subject_id' => $subject->id, 'subject' => 'Химия', 'stage' => 'municipal',
        ]);
    }

    public function test_can_split_subject_by_grades(): void
    {
        $year = $this->year();
        $subject = Subject::create(['name' => 'Математика', 'is_active' => true]);
        $admin = $this->admin();

        // Математика, школьный этап, 4–6 классы
        $this->actingAs($admin)->post(route('admin.olympiads.store'), [
            'academic_year_id' => $year->id, 'subject_id' => $subject->id,
            'stage' => 'school', 'status' => 'planned', 'date_held' => '2025-11-15',
            'grades' => [4, 5, 6],
        ])->assertSessionHas('success');

        // Тот же предмет/этап/год, но 7–11 классы — отдельная олимпиада
        $this->actingAs($admin)->post(route('admin.olympiads.store'), [
            'academic_year_id' => $year->id, 'subject_id' => $subject->id,
            'stage' => 'school', 'status' => 'planned', 'date_held' => '2025-11-15',
            'grades' => [7, 8, 9, 10, 11],
        ])->assertSessionHas('success');

        $this->assertSame(2, Olympiad::count());
        $this->assertDatabaseHas('olympiads', ['subject_id' => $subject->id, 'stage' => 'school', 'grades' => '4,5,6']);
        $this->assertDatabaseHas('olympiads', ['subject_id' => $subject->id, 'stage' => 'school', 'grades' => '7,8,9,10,11']);
    }

    public function test_duplicate_grades_rejected(): void
    {
        $year = $this->year();
        $subject = Subject::create(['name' => 'Математика', 'is_active' => true]);
        $admin = $this->admin();

        $payload = [
            'academic_year_id' => $year->id, 'subject_id' => $subject->id,
            'stage' => 'school', 'status' => 'planned', 'date_held' => '2025-11-15', 'grades' => [4, 5, 6],
        ];
        $this->actingAs($admin)->post(route('admin.olympiads.store'), $payload)->assertSessionHas('success');
        $this->actingAs($admin)->post(route('admin.olympiads.store'), $payload)->assertSessionHasErrors('grades');

        $this->assertSame(1, Olympiad::count());
    }

    public function test_store_saves_level_and_defaults_regional(): void
    {
        $year = $this->year();
        $subject = Subject::create(['name' => 'Татарский язык', 'is_active' => true]);
        $admin = $this->admin();

        // Явный уровень
        $this->actingAs($admin)->post(route('admin.olympiads.store'), [
            'academic_year_id' => $year->id, 'subject_id' => $subject->id,
            'stage' => 'regional', 'status' => 'planned', 'date_held' => '2025-11-15',
            'level' => 'republican', 'grades' => [9, 10, 11],
        ])->assertSessionHas('success');
        $this->assertSame('republican', Olympiad::where('grades', '9,10,11')->value('level'));

        // Без уровня -> regional по умолчанию
        $this->actingAs($admin)->post(route('admin.olympiads.store'), [
            'academic_year_id' => $year->id, 'subject_id' => $subject->id,
            'stage' => 'regional', 'status' => 'planned', 'date_held' => '2025-11-15',
            'grades' => [4, 5, 6],
        ])->assertSessionHas('success');
        $this->assertSame('regional', Olympiad::where('grades', '4,5,6')->value('level'));
    }

    public function test_empty_grades_default_to_all(): void
    {
        $year = $this->year();
        $subject = Subject::create(['name' => 'Биология', 'is_active' => true]);

        $this->actingAs($this->admin())->post(route('admin.olympiads.store'), [
            'academic_year_id' => $year->id, 'subject_id' => $subject->id,
            'stage' => 'school', 'status' => 'planned', 'date_held' => '2025-11-15',
        ])->assertSessionHas('success');

        $this->assertSame('1,2,3,4,5,6,7,8,9,10,11', Olympiad::first()->grades);
    }

    public function test_store_saves_result_deadlines(): void
    {
        $year = $this->year();
        $subject = Subject::create(['name' => 'Химия', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('admin.olympiads.store'), [
                'academic_year_id' => $year->id, 'subject_id' => $subject->id,
                'stage' => 'municipal', 'status' => 'planned', 'date_held' => '2025-12-01',
                'results_deadline' => '2025-12-10T18:00',
                'final_results_deadline' => '2025-12-20T18:00',
            ])
            ->assertSessionHas('success');

        $o = Olympiad::first();
        $this->assertSame('2025-12-10 18:00', $o->results_deadline->format('Y-m-d H:i'));
        $this->assertSame('2025-12-20 18:00', $o->final_results_deadline->format('Y-m-d H:i'));
    }

    public function test_final_deadline_cannot_precede_primary(): void
    {
        $year = $this->year();
        $subject = Subject::create(['name' => 'Химия', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('admin.olympiads.store'), [
                'academic_year_id' => $year->id, 'subject_id' => $subject->id,
                'stage' => 'municipal', 'status' => 'planned', 'date_held' => '2025-12-01',
                'results_deadline' => '2025-12-20T18:00',
                'final_results_deadline' => '2025-12-10T18:00', // раньше первичного
            ])
            ->assertSessionHasErrors('final_results_deadline');
    }

    public function test_publish_sets_status_and_published_at(): void
    {
        Carbon::setTestNow('2026-06-10 10:00:00');
        $olympiad = $this->makeOlympiad('planned');

        $this->actingAs($this->admin())
            ->post(route('admin.olympiads.publish', $olympiad))
            ->assertSessionHas('success');

        $olympiad->refresh();
        $this->assertNotNull($olympiad->published_at);
    }

    public function test_republish_keeps_original_published_at(): void
    {
        $firstPublish = Carbon::parse('2026-06-01 08:00:00');
        $olympiad = $this->makeOlympiad('published');
        $olympiad->update(['published_at' => $firstPublish]);

        Carbon::setTestNow('2026-06-10 10:00:00');
        $this->actingAs($this->admin())->post(route('admin.olympiads.publish', $olympiad));

        $this->assertEquals($firstPublish->toDateTimeString(), $olympiad->fresh()->published_at->toDateTimeString());
    }

    public function test_cannot_delete_olympiad_with_participations(): void
    {
        $olympiad = $this->makeOlympiad('grading');
        $this->makeParticipation($olympiad);

        $this->actingAs($this->admin())
            ->delete(route('admin.olympiads.destroy', $olympiad))
            ->assertSessionHasErrors('olympiad');

        $this->assertDatabaseHas('olympiads', ['id' => $olympiad->id]);
    }

    public function test_non_admin_forbidden(): void
    {
        $coord = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'is_active' => true]);

        $this->actingAs($coord)->get(route('admin.olympiads.index'))->assertForbidden();
    }

    private function makeOlympiad(string $status): Olympiad
    {
        $year = $this->year();
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);

        return Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id,
            'stage' => 'school', 'date_held' => '2025-11-15',
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    private function makeParticipation(Olympiad $olympiad): void
    {
        $ate = Ate::firstOrCreate(['ate_code' => '10'], ['name' => 'АТЕ', 'type' => 'isolated']);
        $msu = Msu::firstOrCreate(['msu_code' => '10'], ['name' => 'МСУ', 'ate_id' => $ate->id]);
        $school = School::create([
            'oo_code' => '10001', 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);
        $student = Student::create([
            'fio' => 'Уч', 'birth_date' => '2011-01-01', 'school_id' => $school->id, 'real_grade' => 9,
        ]);
        HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $olympiad->id,
            'participation_grade' => 9, 'result_status' => 'participant',
        ]);
    }
}
