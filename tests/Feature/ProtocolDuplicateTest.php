<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ProtocolTemplate;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtocolDuplicateTest extends TestCase
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

    private function templateWithColumns(string $stage, ?int $subjectId): ProtocolTemplate
    {
        $t = ProtocolTemplate::create(['name' => 'Протокол', 'stage' => $stage, 'subject_id' => $subjectId]);
        $t->columns()->create(['position' => 1, 'header' => '№', 'source_key' => 'row_number']);
        $t->columns()->create(['position' => 2, 'header' => 'СНИЛС', 'source_key' => 'student.snils']);

        return $t;
    }

    public function test_duplicate_copies_template_and_columns(): void
    {
        $source = $this->templateWithColumns('municipal', null);

        $this->actingAs($this->admin())
            ->post(route('admin.protocols.duplicate', $source), [
                'name' => 'Протокол (копия)', 'stage' => 'regional', 'subject_id' => '',
            ])
            ->assertSessionHas('success');

        $copy = ProtocolTemplate::where('stage', 'regional')->where('name', 'Протокол (копия)')->first();
        $this->assertNotNull($copy);
        $this->assertNotSame($source->id, $copy->id);
        $this->assertSame(2, $copy->columns()->count());
        $this->assertEqualsCanonicalizing(
            ['row_number', 'student.snils'],
            $copy->columns()->pluck('source_key')->all(),
        );
    }

    public function test_duplicate_to_existing_stage_subject_is_rejected(): void
    {
        $subject = Subject::create(['name' => 'Технология', 'is_active' => true]);
        $source = $this->templateWithColumns('municipal', $subject->id);

        // Цель совпадает с уже существующим шаблоном (этот же этап+предмет) → ошибка.
        $this->actingAs($this->admin())
            ->post(route('admin.protocols.duplicate', $source), [
                'name' => 'Дубль', 'stage' => 'municipal', 'subject_id' => $subject->id,
            ])
            ->assertSessionHasErrors('subject_id');

        $this->assertSame(1, ProtocolTemplate::where('stage', 'municipal')->where('subject_id', $subject->id)->count());
    }
}
