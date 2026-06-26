<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ProtocolColumn;
use App\Models\ProtocolTemplate;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProtocolConstructorTest extends TestCase
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

    public function test_admin_creates_template_and_columns(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.protocols.store'), [
            'name' => 'Протокол ШЭ (общий)', 'stage' => 'school', 'subject_id' => null,
        ])->assertSessionHas('success');

        $template = ProtocolTemplate::first();
        $this->assertNotNull($template);

        $this->actingAs($admin)->post(route('admin.protocols.columns.store', $template), [
            'header' => 'СНИЛС', 'source_key' => 'student.snils',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('protocol_columns', [
            'protocol_template_id' => $template->id, 'header' => 'СНИЛС',
            'source_key' => 'student.snils', 'position' => 1,
        ]);
    }

    public function test_duplicate_template_for_stage_subject_rejected(): void
    {
        $admin = $this->admin();
        $subject = Subject::create(['name' => 'Физика', 'is_active' => true]);
        ProtocolTemplate::create(['name' => 'A', 'stage' => 'municipal', 'subject_id' => $subject->id]);

        $this->actingAs($admin)->post(route('admin.protocols.store'), [
            'name' => 'B', 'stage' => 'municipal', 'subject_id' => $subject->id,
        ])->assertSessionHasErrors('subject_id');
    }

    public function test_invalid_source_key_rejected(): void
    {
        $admin = $this->admin();
        $template = ProtocolTemplate::create(['name' => 'A', 'stage' => 'school', 'subject_id' => null]);

        $this->actingAs($admin)->post(route('admin.protocols.columns.store', $template), [
            'header' => 'X', 'source_key' => 'something.invalid',
        ])->assertSessionHasErrors('source_key');

        // А question:N — допустим
        $this->actingAs($admin)->post(route('admin.protocols.columns.store', $template), [
            'header' => 'В1', 'group_header' => 'Вопросы', 'source_key' => 'question:1',
        ])->assertSessionHasNoErrors();
    }

    public function test_reorder_columns(): void
    {
        $admin = $this->admin();
        $template = ProtocolTemplate::create(['name' => 'A', 'stage' => 'school', 'subject_id' => null]);
        $c1 = ProtocolColumn::create(['protocol_template_id' => $template->id, 'position' => 1, 'header' => 'A', 'source_key' => 'row_number']);
        $c2 = ProtocolColumn::create(['protocol_template_id' => $template->id, 'position' => 2, 'header' => 'B', 'source_key' => 'student.snils']);

        $this->actingAs($admin)->post(route('admin.protocols.reorder', $template), [
            'ids' => [$c2->id, $c1->id],
        ])->assertSessionHas('success');

        $this->assertSame(1, $c2->fresh()->position);
        $this->assertSame(2, $c1->fresh()->position);
    }

    public function test_non_admin_forbidden(): void
    {
        $op = User::factory()->create(['role' => UserRole::SchoolOperator, 'is_active' => true]);

        $this->actingAs($op)->get(route('admin.protocols.index'))->assertForbidden();
    }
}
