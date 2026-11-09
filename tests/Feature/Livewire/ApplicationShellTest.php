<?php

namespace Tests\Feature\Livewire;

use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_are_redirected_from_application_pages(): void
    {
        foreach (['/', '/documents', '/documents/create', '/inbox', '/outgoing', '/division', '/administration/documents'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_staff_navigation_hides_privileged_sections(): void
    {
        $user = $this->employee();

        $this->actingAs($user)->get('/')->assertOk()
            ->assertSeeText('Dashboard')->assertSeeText('Inbox')->assertSeeText('Create Document')
            ->assertDontSeeText('Division Overview')->assertDontSeeText('Administration');
    }

    public function test_privileged_navigation_matches_the_sso_role_flags(): void
    {
        $head = $this->employee(['is_division_head' => true]);
        $admin = $this->employee(['is_admin' => true]);

        $this->actingAs($head)->get('/')->assertOk()->assertSeeText('Division Overview')->assertDontSeeText('Administration');
        $this->actingAs($admin)->get('/')->assertOk()->assertSeeText('Division Overview')->assertSeeText('Administration');
    }

    public function test_dashboard_counts_only_authorized_documents(): void
    {
        $user = $this->employee();
        $other = $this->employee();
        $type = DocumentType::query()->create(['name' => 'Memo', 'code' => 'MEM', 'is_active' => true]);
        $this->document($user, $type, ['status' => 'pending_acknowledgment']);
        $this->document($user, $type, ['status' => 'completed', 'current_handler_id' => null]);
        $this->document($other, $type, ['title' => 'Secret unrelated record']);

        $this->actingAs($user)->get('/')->assertOk()
            ->assertSeeText('Pending acknowledgment')->assertSeeText('Created documents')
            ->assertDontSeeText('Secret unrelated record');
    }

    private function employee(array $attributes = []): User
    {
        $division = Division::query()->create(['sso_division_id' => fake()->uuid(), 'name' => fake()->company(), 'code' => fake()->unique()->lexify('???'), 'is_active' => true]);

        return User::factory()->create(array_merge(['division_id' => $division->id], $attributes));
    }

    private function document(User $owner, DocumentType $type, array $attributes = []): Document
    {
        return Document::query()->create(array_merge(['title' => 'Authorized document', 'document_type_id' => $type->id, 'origin_division_id' => $owner->division_id, 'created_by' => $owner->id, 'current_handler_id' => $owner->id, 'status' => 'draft'], $attributes));
    }
}
