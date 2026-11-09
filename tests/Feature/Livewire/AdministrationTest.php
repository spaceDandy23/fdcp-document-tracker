<?php

namespace Tests\Feature\Livewire;

use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_division_head_sees_originating_and_routed_documents_only(): void
    {
        $head = $this->employee(['is_division_head' => true]);
        $other = $this->employee();
        $type = $this->type();
        $originating = $this->document($head, $type, ['title' => 'Division origin']);
        $unrelated = $this->document($other, $type, ['title' => 'Unrelated document']);

        $this->actingAs($head)->get('/division')->assertOk()->assertSeeText($originating->title)->assertDontSeeText($unrelated->title);
    }

    public function test_administrator_can_manage_document_types(): void
    {
        $admin = $this->employee(['is_admin' => true]);

        Livewire::actingAs($admin)->test('pages::admin.document-types')
            ->set('name', 'Office Order')->set('code', 'OO')->call('save')->assertHasNoErrors();

        $type = DocumentType::sole();
        $this->assertTrue($type->is_active);

        Livewire::actingAs($admin)->test('pages::admin.document-types')->call('toggle', $type->id)->assertHasNoErrors();
        $this->assertFalse($type->fresh()->is_active);
    }

    public function test_non_administrator_cannot_invoke_document_type_management(): void
    {
        $staff = $this->employee();

        $this->actingAs($staff)->get('/administration/document-types')->assertForbidden();
    }

    private function employee(array $attributes = []): User
    {
        $division = Division::query()->create(['sso_division_id' => fake()->uuid(), 'name' => fake()->company(), 'code' => fake()->unique()->lexify('???'), 'is_active' => true]);

        return User::factory()->create(array_merge(['division_id' => $division->id], $attributes));
    }

    private function type(): DocumentType
    {
        return DocumentType::query()->create(['name' => 'Memorandum', 'code' => 'MEM', 'is_active' => true]);
    }

    private function document(User $owner, DocumentType $type, array $attributes = []): Document
    {
        return Document::query()->create(array_merge(['title' => 'Document', 'document_type_id' => $type->id, 'origin_division_id' => $owner->division_id, 'created_by' => $owner->id, 'current_handler_id' => $owner->id, 'status' => 'draft'], $attributes));
    }
}
