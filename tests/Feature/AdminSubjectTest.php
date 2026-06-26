<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Olympiad;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSubjectTest extends TestCase
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

    public function test_admin_creates_subject(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.subjects.store'), ['name' => 'Робототехника', 'is_active' => true])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('subjects', ['name' => 'Робототехника', 'is_active' => true]);
    }

    public function test_subjects_sorted_by_name(): void
    {
        Subject::create(['name' => 'Физика', 'is_active' => true]);
        Subject::create(['name' => 'Алгебра', 'is_active' => true]);

        $this->assertSame(['Алгебра', 'Физика'], \App\Models\Subject::ordered()->pluck('name')->all());
    }

    public function test_subject_name_is_unique(): void
    {
        Subject::create(['name' => 'Математика', 'is_active' => true]);

        $this->actingAs($this->admin())
            ->post(route('admin.subjects.store'), ['name' => 'Математика', 'is_active' => true])
            ->assertSessionHasErrors('name');
    }

    public function test_cannot_delete_subject_used_by_olympiad(): void
    {
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Физика', 'subject_id' => $subject->id,
            'stage' => 'school', 'date_held' => '2025-11-15', 'status' => 'planned',
        ]);

        $this->actingAs($this->admin())
            ->delete(route('admin.subjects.destroy', $subject))
            ->assertSessionHasErrors('subject');

        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
    }

    public function test_seeder_links_existing_olympiad_subject_strings(): void
    {
        $year = AcademicYear::create(['name' => '2025/2026', 'status' => 'current']);
        $olympiad = Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Биология',
            'stage' => 'school', 'date_held' => '2025-11-15', 'status' => 'planned',
        ]);

        $this->seed(\Database\Seeders\SubjectSeeder::class);

        $olympiad->refresh();
        $this->assertNotNull($olympiad->subject_id);
        $this->assertSame('Биология', $olympiad->subjectRef->name);
    }
}
