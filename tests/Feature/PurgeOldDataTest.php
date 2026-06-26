<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Ate;
use App\Models\HistoricalStat;
use App\Models\HumanOlympiad;
use App\Models\Msu;
use App\Models\Olympiad;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeOldDataTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $ate = Ate::firstOrCreate(['ate_code' => '10'], ['name' => 'АТЕ', 'type' => 'isolated']);
        $msu = Msu::firstOrCreate(['msu_code' => '10'], ['name' => 'МСУ', 'ate_id' => $ate->id]);
        $this->school = School::create([
            'oo_code' => '10001', 'short_name' => 'Школа', 'full_name' => 'Школа',
            'education_level' => 3, 'territorial_sign' => 'city',
            'msu_id' => $msu->id, 'msu_code' => '10', 'ate_id' => $ate->id, 'ate_code' => '10',
        ]);
    }

    private function makeYear(string $name, string $status): AcademicYear
    {
        return AcademicYear::create(['name' => $name, 'status' => $status]);
    }

    private function makeOlympiad(AcademicYear $year, string $subject = 'Математика'): Olympiad
    {
        return Olympiad::create([
            'academic_year_id' => $year->id, 'subject' => $subject, 'stage' => 'school',
            'date_held' => '2022-11-15', 'published_at' => now(),
        ]);
    }

    private int $snilsSeq = 0;

    private function makeStudent(string $fio): Student
    {
        return Student::create([
            'fio' => $fio, 'birth_date' => '2010-03-01',
            'school_id' => $this->school->id, 'real_grade' => 9,
            'snils' => sprintf('100-000-000 %02d', ++$this->snilsSeq), // уникальный СНИЛС
        ]);
    }

    private function makeWork(Student $s, Olympiad $o, string $status, ?string $scan): HumanOlympiad
    {
        if ($scan) {
            Storage::put($scan, 'PDF');
        }

        return HumanOlympiad::create([
            'student_id' => $s->id, 'olympiad_id' => $o->id,
            'participation_grade' => 9, 'score' => 90, 'result_status' => $status, 'scan_path' => $scan,
        ]);
    }

    public function test_purge_aggregates_deletes_scans_and_anonymizes(): void
    {
        // today == 2026-06-09 -> сезон 2022/2023 истёк (3 года), 2025/2026 актуален
        $old = $this->makeYear('2022/2023', 'archive');
        $current = $this->makeYear('2025/2026', 'current');
        $oldOlympiad = $this->makeOlympiad($old);
        $newOlympiad = $this->makeOlympiad($current);

        $onlyOld = $this->makeStudent('Старый Участник');
        $both = $this->makeStudent('Активный Участник');

        $this->makeWork($onlyOld, $oldOlympiad, 'winner', 'scans/old-only.pdf');
        $bothOldWork = $this->makeWork($both, $oldOlympiad, 'prize_winner', 'scans/both-old.pdf');
        $bothNewWork = $this->makeWork($both, $newOlympiad, 'participant', 'scans/both-new.pdf');

        $this->artisan('data:purge', ['--force' => true])->assertSuccessful();

        // 1. Историческая статистика по истёкшему сезону
        $stat = HistoricalStat::where('year_name', '2022/2023')->where('subject', 'Математика')->first();
        $this->assertNotNull($stat);
        $this->assertSame(2, $stat->total_participants);
        $this->assertSame(1, $stat->total_winner_diplomas);
        $this->assertSame(1, $stat->total_prizewinner_diplomas);

        // 2. Сканы истёкшего сезона удалены, актуального — на месте
        Storage::assertMissing('scans/old-only.pdf');
        Storage::assertMissing('scans/both-old.pdf');
        Storage::assertExists('scans/both-new.pdf');
        $this->assertNull($bothOldWork->fresh()->scan_path);
        $this->assertSame('scans/both-new.pdf', $bothNewWork->fresh()->scan_path);

        // 3. ПДн: участник только истёкшего сезона обезличен, активный — нет
        $onlyOld->refresh();
        $this->assertNotNull($onlyOld->anonymized_at);
        $this->assertSame('Удалённые данные (ФЗ-152)', $onlyOld->fio);
        $this->assertNull($onlyOld->snils);

        $both->refresh();
        $this->assertNull($both->anonymized_at);
        $this->assertSame('Активный Участник', $both->fio);
    }

    public function test_purge_is_idempotent(): void
    {
        $old = $this->makeYear('2022/2023', 'archive');
        $this->makeYear('2025/2026', 'current');
        $student = $this->makeStudent('Старый Участник');
        $this->makeWork($student, $this->makeOlympiad($old), 'winner', 'scans/x.pdf');

        $this->artisan('data:purge', ['--force' => true])->assertSuccessful();
        $firstAnonymizedAt = $student->fresh()->anonymized_at;
        $statCount = HistoricalStat::count();

        $this->artisan('data:purge', ['--force' => true])->assertSuccessful();

        $this->assertSame($statCount, HistoricalStat::count());
        $this->assertEquals($firstAnonymizedAt, $student->fresh()->anonymized_at);
    }

    public function test_purge_skips_when_no_expired_seasons(): void
    {
        $this->makeYear('2025/2026', 'current');
        $student = $this->makeStudent('Свежий Участник');
        $this->makeWork($student, $this->makeOlympiad($this->makeYear('2024/2025', 'archive')), 'winner', 'scans/y.pdf');

        $this->artisan('data:purge', ['--force' => true])->assertSuccessful();

        Storage::assertExists('scans/y.pdf');
        $this->assertNull($student->fresh()->anonymized_at);
        $this->assertSame(0, HistoricalStat::count());
    }
}
