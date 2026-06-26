<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMaintenanceTest extends TestCase
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

    public function test_admin_can_view_panel(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);

        $this->actingAs($this->admin())->get(route('admin.maintenance'))->assertOk();
    }

    public function test_non_admin_is_forbidden(): void
    {
        $operator = User::factory()->create(['role' => UserRole::SchoolOperator, 'is_active' => true]);

        $this->actingAs($operator)->get(route('admin.maintenance'))->assertForbidden();
    }

    public function test_rotate_action_runs_command(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);

        $this->actingAs($this->admin())
            ->post(route('admin.maintenance.rotate'), ['name' => '2026/2027'])
            ->assertSessionHas('success');

        $this->assertSame('current', AcademicYear::where('name', '2026/2027')->value('status'));
        $this->assertSame('archive', AcademicYear::where('name', '2025/2026')->value('status'));
    }

    public function test_rotate_validates_year_format(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);

        $this->actingAs($this->admin())
            ->post(route('admin.maintenance.rotate'), ['name' => 'badformat'])
            ->assertSessionHasErrors('name');
    }

    public function test_purge_action_runs_command(): void
    {
        AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);

        $this->actingAs($this->admin())
            ->post(route('admin.maintenance.purge'), ['years' => 3])
            ->assertSessionHas('success');
    }
}
