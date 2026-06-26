<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Olympiad;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAcademicYearTest extends TestCase
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

    public function test_creating_current_year_archives_previous(): void
    {
        $old = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);

        $this->actingAs($this->admin())
            ->post(route('admin.years.store'), ['name' => '2026/2027', 'status' => 'current'])
            ->assertSessionHas('success');

        $this->assertSame('archive', $old->fresh()->status);
        $this->assertSame('current', AcademicYear::where('name', '2026/2027')->value('status'));
    }

    public function test_make_current_switches_single_current(): void
    {
        $a = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $b = AcademicYear::create(['name' => '2024/2025', 'status' => 'archive']);

        $this->actingAs($this->admin())
            ->post(route('admin.years.current', $b))
            ->assertSessionHas('success');

        $this->assertSame('archive', $a->fresh()->status);
        $this->assertSame('current', $b->fresh()->status);
    }

    public function test_cannot_delete_year_with_olympiads(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Математика', 'stage' => 'school',
            'date_held' => '2025-11-15', 'status' => 'planned',
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.years.destroy', $year))
            ->assertSessionHasErrors('year');

        $this->assertDatabaseHas('academic_years', ['id' => $year->id]);
    }

    public function test_validates_year_format(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.years.store'), ['name' => 'badyear', 'status' => 'archive'])
            ->assertSessionHasErrors('name');
    }
}
