<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\Msu;
use App\Models\Olympiad;
use App\Models\School;
use App\Models\Subject;
use App\Models\TechPractice;
use App\Models\TechProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TechReferenceTest extends TestCase
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

    public function test_seeder_loads_two_profiles_and_fourteen_practices(): void
    {
        $this->seed(\Database\Seeders\TechReferenceSeeder::class);

        $this->assertSame(2, TechProfile::count());
        $this->assertSame(14, TechPractice::count());
        // Идемпотентность: повторный запуск не плодит дубли.
        $this->seed(\Database\Seeders\TechReferenceSeeder::class);
        $this->assertSame(14, TechPractice::count());
    }

    public function test_admin_can_create_profile_and_practice(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.tech.profiles.store'), ['name' => 'Направление X', 'position' => 1, 'is_active' => true])
            ->assertSessionHasNoErrors();
        $profile = TechProfile::firstWhere('name', 'Направление X');
        $this->assertNotNull($profile);

        $this->actingAs($admin)
            ->post(route('admin.tech.practices.store', $profile), ['code' => '1.1', 'name' => 'Практика A', 'position' => 1, 'is_active' => true])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tech_practices', [
            'tech_profile_id' => $profile->id, 'code' => '1.1', 'name' => 'Практика A',
        ]);
    }

    public function test_practice_code_unique_within_profile(): void
    {
        $admin = $this->admin();
        $profile = TechProfile::create(['name' => 'Напр', 'position' => 1, 'is_active' => true]);
        $profile->practices()->create(['code' => '1.1', 'name' => 'A', 'position' => 1, 'is_active' => true]);

        $this->actingAs($admin)
            ->post(route('admin.tech.practices.store', $profile), ['code' => '1.1', 'name' => 'B', 'position' => 2, 'is_active' => true])
            ->assertSessionHasErrors('code');
    }

    public function test_technology_show_exposes_reference_other_subject_does_not(): void
    {
        $this->seed(\Database\Seeders\TechReferenceSeeder::class);
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $tech = Subject::create(['name' => 'Труд (технология)', 'is_active' => true]);
        $math = Subject::create(['name' => 'Математика', 'is_active' => true]);

        $ate = Ate::create(['ate_code' => '10', 'name' => 'АТЕ', 'type' => 'isolated']);
        $msu = Msu::create(['msu_code' => '10', 'name' => 'МСУ', 'ate_id' => $ate->id]);
        $school = School::create([
            'oo_code' => 'OO1', 'short_name' => 'Школа', 'full_name' => 'Школа', 'education_level' => 3,
            'territorial_sign' => 'city', 'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);
        $operator = User::factory()->create(['role' => UserRole::SchoolOperator, 'school_id' => $school->id, 'is_active' => true]);

        $techOl = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Труд (технология)', 'subject_id' => $tech->id,
            'stage' => 'school', 'grades' => '7,8,9', 'date_held' => '2025-11-15', 'status' => 'grading',
        ]);
        $mathOl = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Математика', 'subject_id' => $math->id,
            'stage' => 'school', 'grades' => '7,8,9', 'date_held' => '2025-11-15', 'status' => 'grading',
        ]);

        $this->actingAs($operator)->get(route('school.results.show', $techOl))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('is_technology', true)
                ->has('tech_profiles', 2)
                ->has('tech_profiles.0.practices', 8));

        $this->actingAs($operator)->get(route('school.results.show', $mathOl))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('is_technology', false)
                ->where('tech_profiles', []));
    }
}
