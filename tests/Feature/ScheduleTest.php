<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\Olympiad;
use App\Models\OlympiadEntryExtension;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function municipal(AcademicYear $year): Olympiad
    {
        return Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'municipal', 'grades' => '9',
            'date_held' => '2025-12-01', 'status' => 'grading',
            'results_deadline' => now()->addDays(2), 'final_results_deadline' => now()->addDays(9),
        ]);
    }

    public function test_public_schedule_is_open_and_brief(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $this->municipal($year);

        // Без авторизации.
        $this->get(route('schedule.public'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Schedule/Public')
                ->where('olympiads.0.subject', 'Физика')
                ->where('olympiads.0.publication.date', fn ($d) => $d !== null)
                // Краткое: без сроков первичного ввода.
                ->missing('olympiads.0.primary_close'));
    }

    public function test_full_schedule_requires_auth(): void
    {
        $this->get(route('schedule'))->assertRedirect(route('login'));
    }

    public function test_extension_does_not_change_schedule_dates(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $ate = Ate::create(['ate_code' => '01', 'name' => 'АТЕ', 'type' => 'isolated']);
        $olympiad = $this->municipal($year);
        $baseIso = $olympiad->fresh()->results_deadline->toIso8601String();

        // Продление первичного ввода по АТЕ на 5 часов — расписание сдвигаться НЕ должно.
        OlympiadEntryExtension::create([
            'olympiad_id' => $olympiad->id, 'phase' => 'primary', 'scope' => 'ate', 'ate_id' => $ate->id,
            'extended_until' => $olympiad->results_deadline->copy()->addHours(5),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin)->get(route('schedule'))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Schedule/Index')
                // Показан зафиксированный (базовый) срок, без сдвига и без пометки «продлено».
                ->where('olympiads.0.primary_close.date', $baseIso)
                ->missing('olympiads.0.primary_close.extended'));
    }
}
