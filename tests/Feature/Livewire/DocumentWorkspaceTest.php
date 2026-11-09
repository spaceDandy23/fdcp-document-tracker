<?php

namespace Tests\Feature\Livewire;

use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DocumentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_employee_creates_a_draft_without_a_main_document_through_the_livewire_form(): void
    {
        $user = $this->employee();
        $type = $this->type();

        Livewire::actingAs($user)->test('pages::documents.create')
            ->set('title', 'Budget endorsement')
            ->set('documentTypeId', (string) $type->id)
            ->set('description', 'For review')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('documents', ['title' => 'Budget endorsement', 'created_by' => $user->id, 'origin_division_id' => $user->division_id, 'status' => 'draft']);
        $this->assertDatabaseHas('document_actions', ['action' => 'created', 'performed_by' => $user->id]);
        $this->assertDatabaseCount('document_versions', 0);
    }

    public function test_employee_creates_a_draft_with_a_main_document_version(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $user = $this->employee();
        $type = $this->type();

        Livewire::actingAs($user)->test('pages::documents.create')
            ->set('title', 'Budget endorsement')
            ->set('documentTypeId', (string) $type->id)
            ->set('mainDocument', UploadedFile::fake()->create('endorsement.pdf', 100, 'application/pdf'))
            ->assertSee('Selected: endorsement.pdf')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $document = Document::sole();
        $version = DocumentVersion::sole();
        $this->assertSame($document->id, $version->document_id);
        $this->assertSame(1, $version->version_number);
        $this->assertSame($version->id, $document->current_version_id);
        $this->assertDatabaseHas('document_actions', ['document_id' => $document->id, 'action' => 'version_uploaded']);
        Storage::disk('local')->assertExists($version->storage_path);
    }

    public function test_invalid_main_document_does_not_create_a_draft(): void
    {
        $user = $this->employee();
        $type = $this->type();

        Livewire::actingAs($user)->test('pages::documents.create')
            ->set('title', 'Budget endorsement')
            ->set('documentTypeId', (string) $type->id)
            ->set('mainDocument', UploadedFile::fake()->create('notes.txt', 10, 'text/plain'))
            ->call('save')
            ->assertHasErrors(['mainDocument']);

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
    }

    public function test_failed_main_document_persistence_leaves_a_recoverable_draft_without_an_orphaned_file(): void
    {
        Storage::fake('local');
        config(['documents.disk' => 'local']);
        $user = $this->employee();
        $type = $this->type();
        DocumentVersion::creating(function (): void {
            throw new RuntimeException('Version persistence failed.');
        });

        Livewire::actingAs($user)->test('pages::documents.create')
            ->set('title', 'Recoverable draft')
            ->set('documentTypeId', (string) $type->id)
            ->set('mainDocument', UploadedFile::fake()->create('recoverable.pdf', 100, 'application/pdf'))
            ->call('save')
            ->assertRedirect();

        $this->assertDatabaseHas('documents', ['title' => 'Recoverable draft', 'status' => 'draft']);
        $this->assertDatabaseCount('document_versions', 0);
        Storage::disk('local')->assertEmpty();
        $this->assertSame('Draft saved, but the main document could not be uploaded. You can retry from the document detail page.', session('error'));
    }

    public function test_employee_without_an_assigned_division_cannot_create_a_draft(): void
    {
        $user = User::factory()->create(['division_id' => null]);
        $type = $this->type();

        Livewire::actingAs($user)->test('pages::documents.create')
            ->set('title', 'Unauthorized draft')
            ->set('documentTypeId', (string) $type->id)
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_draft_form_reports_required_fields(): void
    {
        Livewire::actingAs($this->employee())->test('pages::documents.create')->call('save')
            ->assertHasErrors(['title', 'document_type_id']);
    }

    public function test_document_list_search_and_status_filters_do_not_expose_unrelated_records(): void
    {
        $user = $this->employee();
        $other = $this->employee();
        $type = $this->type();
        $this->document($user, $type, ['title' => 'Needle memo', 'status' => 'draft']);
        $this->document($user, $type, ['title' => 'Completed memo', 'status' => 'completed', 'current_handler_id' => null]);
        $this->document($other, $type, ['title' => 'Needle confidential']);

        Livewire::actingAs($user)->test('pages::documents.index')->set('search', 'Needle')
            ->assertSee('Needle memo')->assertDontSee('Completed memo')->assertDontSee('Needle confidential')
            ->set('status', 'completed')->assertDontSee('Needle memo');
    }

    public function test_staff_cannot_open_division_or_administration_pages(): void
    {
        $this->actingAs($this->employee())->get('/division')->assertForbidden();
        $this->get('/administration/documents')->assertForbidden();
        $this->get('/administration/document-types')->assertForbidden();
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
