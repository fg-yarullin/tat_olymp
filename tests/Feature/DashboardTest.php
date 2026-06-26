<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Olympiad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_dashboard_lists_open_entry_and_upcoming_olympiads(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);

        // Открыт первичный ввод (grading + срок в будущем).
        $open = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'stage' => 'school', 'grades' => '9',
            'date_held' => '2025-11-01', 'status' => 'grading', 'results_deadline' => now()->addDays(3),
        ]);

        // Опубликована — ввода нет, в «активных» не показываем.
        Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Химия', 'stage' => 'school', 'grades' => '9',
            'date_held' => '2025-10-01', 'published_at' => now(),
        ]);

        // Предстоящая по дате проведения.
        Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Биология', 'stage' => 'municipal', 'grades' => '9',
            'date_held' => now()->addWeeks(2)->toDateString(), 'status' => 'planned',
        ]);

        $user = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Dashboard')
                ->where('counts.active', 1)
                ->where('active.0.subject', 'Физика')
                ->where('active.0.phase', 'primary')
                ->where('counts.upcoming', 1)
                ->where('upcoming.0.subject', 'Биология'));
    }

    public function test_dashboard_counts_closing_soon(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Астрономия', 'stage' => 'school', 'grades' => '9',
            'date_held' => '2025-11-01', 'status' => 'grading', 'results_deadline' => now()->addHours(6),
        ]);
        $user = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('counts.closing_soon', 1));
    }
}
