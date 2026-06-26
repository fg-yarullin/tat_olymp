<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminHomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_sees_hub(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($admin)->get(route('admin.home'))->assertOk();
    }

    public function test_non_admin_forbidden(): void
    {
        $coord = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'is_active' => true]);

        $this->actingAs($coord)->get(route('admin.home'))->assertForbidden();
    }
}
