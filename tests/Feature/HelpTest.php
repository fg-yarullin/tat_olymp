<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class HelpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_sees_all_documents(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($user)->get(route('help.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Help/Index')
                ->where('docs', fn ($docs) => collect($docs)->pluck('slug')
                    ->intersect(['scans', 'faq-school', 'faq-municipal', 'faq-chair'])->count() === 4));
    }

    public function test_index_filters_documents_by_role(): void
    {
        $chair = User::factory()->create(['role' => UserRole::CommissionChair, 'is_active' => true]);

        $this->actingAs($chair)->get(route('help.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('docs', function ($docs) {
                $slugs = collect($docs)->pluck('slug');

                return $slugs->contains('faq-chair')
                    && ! $slugs->contains('faq-school')
                    && ! $slugs->contains('faq-municipal');
            }));
    }

    public function test_help_document_renders_markdown(): void
    {
        $user = User::factory()->create(['role' => UserRole::MunicipalCoordinator, 'is_active' => true]);

        $this->actingAs($user)->get(route('help.show', 'scans'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Help/Document')
                ->where('title', 'Загрузка сканов работ')
                ->where('html', fn ($html) => str_contains($html, '<h1')));
    }

    public function test_unknown_document_is_404(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        $this->actingAs($user)->get(route('help.show', 'nope'))->assertNotFound();
    }

    public function test_help_requires_auth(): void
    {
        $this->get(route('help.index'))->assertRedirect(route('login'));
    }
}
