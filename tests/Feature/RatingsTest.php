<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\HistoricalStat;
use App\Models\HumanOlympiad;
use App\Models\Msu;
use App\Models\Olympiad;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use App\Services\RatingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RatingsTest extends TestCase
{
    use RefreshDatabase;

    private Ate $ate;
    private Msu $msu;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->ate = Ate::create(['ate_code' => '50', 'name' => 'Тестовая АТЕ', 'type' => 'isolated']);
        $this->msu = Msu::create(['msu_code' => '50', 'name' => 'МСУ', 'ate_id' => $this->ate->id]);
    }

    private function makeSchool(string $name, string $territory = 'city'): School
    {
        return School::create([
            'oo_code' => '500'.(++$this->seq), 'short_name' => $name, 'full_name' => $name,
            'education_level' => 3, 'territorial_sign' => $territory,
            'msu_id' => $this->msu->id, 'msu_code' => '50', 'ate_id' => $this->ate->id, 'ate_code' => '50',
        ]);
    }

    private function makeYear(string $name = '2025/2026', string $status = 'current'): AcademicYear
    {
        return AcademicYear::create(['name' => $name, 'status' => $status]);
    }

    private function makeOlympiad(AcademicYear $year): Olympiad
    {
        return Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => 'Математика', 'stage' => 'municipal',
            'date_held' => '2025-12-15', 'published_at' => now(),
        ]);
    }

    private function makeWork(School $school, Olympiad $olympiad, string $status): void
    {
        $student = Student::create([
            'fio' => 'Уч '.(++$this->seq), 'birth_date' => '2011-03-01',
            'school_id' => $school->id, 'real_grade' => 9,
        ]);
        HumanOlympiad::create([
            'student_id' => $student->id, 'olympiad_id' => $olympiad->id,
            'participation_grade' => 9, 'score' => 90, 'result_status' => $status,
        ]);
    }

    private function coordinator(): User
    {
        return User::factory()->create([
            'role' => UserRole::MunicipalCoordinator, 'ate_id' => $this->ate->id, 'is_active' => true,
        ]);
    }

    public function test_service_ranks_schools_by_points(): void
    {
        $year = $this->makeYear();
        $olympiad = $this->makeOlympiad($year);
        $schoolA = $this->makeSchool('Школа А');
        $schoolB = $this->makeSchool('Школа Б');

        // A: 1 победитель + 1 призёр = 3 + 1 = 4 балла
        $this->makeWork($schoolA, $olympiad, 'winner');
        $this->makeWork($schoolA, $olympiad, 'prize_winner');
        // B: 2 победителя = 6 баллов
        $this->makeWork($schoolB, $olympiad, 'winner');
        $this->makeWork($schoolB, $olympiad, 'winner');

        $ratings = app(RatingService::class)->schoolRatings('50');

        $this->assertSame('Школа Б', $ratings[0]['school']);
        $this->assertSame(1, $ratings[0]['rank']);
        $this->assertSame(6, $ratings[0]['points']);
        $this->assertSame('Школа А', $ratings[1]['school']);
        $this->assertSame(4, $ratings[1]['points']);
    }

    public function test_service_uses_historical_stats_for_archived_year(): void
    {
        $this->makeYear('2025/2026', 'current');
        $this->makeYear('2020/2021', 'archive');
        $school = $this->makeSchool('Архивная Школа');

        HistoricalStat::create([
            'year_name' => '2020/2021', 'ate_code' => '50', 'msu_code' => '50',
            'oo_code' => $school->oo_code, 'subject' => 'Физика', 'stage' => 'municipal',
            'total_participants' => 10, 'total_prizewinner_diplomas' => 2, 'total_winner_diplomas' => 1,
        ]);

        $ratings = app(RatingService::class)->schoolRatings('50', '2020/2021');

        $this->assertCount(1, $ratings);
        $this->assertSame('Архивная Школа', $ratings[0]['school']);
        $this->assertSame(10, $ratings[0]['participants']);
        $this->assertSame(5, $ratings[0]['points']); // 1*3 + 2*1
    }

    public function test_coordinator_can_view_ratings_scoped_to_their_ate(): void
    {
        $year = $this->makeYear();
        $olympiad = $this->makeOlympiad($year);
        $this->makeWork($this->makeSchool('Школа А'), $olympiad, 'winner');

        $this->actingAs($this->coordinator())
            ->get(route('analytics.ratings'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Coordinator/Ratings')
                ->where('ate.code', '50')
                ->where('schoolRatings.0.school', 'Школа А')
            );
    }

    public function test_school_operator_is_forbidden(): void
    {
        $operator = User::factory()->create(['role' => UserRole::SchoolOperator, 'is_active' => true]);

        $this->actingAs($operator)->get(route('analytics.ratings'))->assertForbidden();
    }
}
