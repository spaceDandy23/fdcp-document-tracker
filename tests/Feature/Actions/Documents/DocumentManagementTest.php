<?php

namespace Tests\Feature\Actions\Documents;

use App\Actions\Documents\ListDocuments;
use App\Actions\Documents\UpdateDocumentDraft;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_edit_an_active_type_on_their_own_draft(): void
    {
        $creator = $this->createEmployee();
        $draft = $this->createDocument($creator);
        $replacementType = $this->createDocumentType();
        $this->actingAs($creator);

        $updated = app(UpdateDocumentDraft::class)->handle($draft, [
            'title' => 'Updated title',
            'description' => 'Updated description',
            'document_type_id' => $replacementType->id,
        ]);

        $this->assertSame('Updated title', $updated->title);
        $this->assertSame('Updated description', $updated->description);
        $this->assertSame($replacementType->id, $updated->document_type_id);
        $this->assertDatabaseHas('document_actions', [
            'document_id' => $draft->id,
            'performed_by' => $creator->id,
            'action' => 'draft_updated',
        ]);
    }

    public function test_draft_editing_rejects_inactive_document_types(): void
    {
        $creator = $this->createEmployee();
        $draft = $this->createDocument($creator);
        $inactiveType = $this->createDocumentType(false);
        $this->actingAs($creator);

        try {
            app(UpdateDocumentDraft::class)->handle($draft, [
                'document_type_id' => $inactiveType->id,
            ]);

            $this->fail('Expected the inactive document type to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document_type_id', $exception->errors());
        }

        $this->assertSame($draft->document_type_id, $draft->fresh()->document_type_id);
    }

    public function test_only_the_creator_can_edit_a_draft_and_non_drafts_cannot_be_edited(): void
    {
        $creator = $this->createEmployee();
        $otherEmployee = $this->createEmployee();
        $draft = $this->createDocument($creator);

        $this->actingAs($otherEmployee);
        try {
            app(UpdateDocumentDraft::class)->handle($draft, ['title' => 'Unauthorized']);
            $this->fail('Expected another employee to be denied.');
        } catch (AuthorizationException) {
            $this->assertSame('Original title', $draft->fresh()->title);
        }

        $draft->update(['status' => 'in_progress']);
        $this->actingAs($creator);

        $this->expectException(AuthorizationException::class);
        app(UpdateDocumentDraft::class)->handle($draft->fresh(), ['title' => 'Too late']);
    }

    public function test_policy_and_listing_cover_creator_handlers_division_heads_and_administrators(): void
    {
        $creator = $this->createEmployee();
        $currentHandler = $this->createEmployee();
        $previousHandler = $this->createEmployee();
        $outsider = $this->createEmployee();
        $divisionHead = $this->createEmployee($creator->division);
        $divisionHead->forceFill(['is_division_head' => true])->save();
        $administrator = $this->createEmployee();
        $administrator->forceFill(['is_admin' => true])->save();
        $document = $this->createDocument($creator, $currentHandler, 'in_progress');
        $version = $this->createVersion($document, $creator);
        $document->update(['current_version_id' => $version->id]);
        DocumentRoute::create([
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'from_user_id' => $creator->id,
            'to_user_id' => $previousHandler->id,
            'from_division_id' => $creator->division_id,
            'to_division_id' => $previousHandler->division_id,
            'status' => 'acknowledged',
            'forwarded_at' => now(),
            'acknowledged_at' => now(),
        ]);

        $this->assertTrue($creator->can('view', $document));
        $this->assertTrue($currentHandler->can('view', $document));
        $this->assertTrue($previousHandler->can('view', $document));
        $this->assertTrue($divisionHead->can('view', $document));
        $this->assertTrue($administrator->can('view', $document));
        $this->assertFalse($outsider->can('view', $document));

        foreach ([$creator, $currentHandler, $previousHandler, $divisionHead, $administrator] as $viewer) {
            $this->actingAs($viewer);
            $this->assertTrue(app(ListDocuments::class)->handle()->contains($document));
        }

        $this->actingAs($outsider);
        $this->assertFalse(app(ListDocuments::class)->handle()->contains($document));
    }

    private function createEmployee(?Division $division = null): User
    {
        $division ??= Division::create([
            'sso_division_id' => fake()->uuid(),
            'name' => fake()->unique()->company(),
        ]);

        return User::factory()->create(['division_id' => $division->id]);
    }

    private function createDocumentType(bool $active = true): DocumentType
    {
        return DocumentType::create([
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->lexify('TYPE-???'),
            'is_active' => $active,
        ]);
    }

    private function createDocument(User $creator, ?User $handler = null, string $status = 'draft'): Document
    {
        return Document::create([
            'title' => 'Original title',
            'document_type_id' => $this->createDocumentType()->id,
            'origin_division_id' => $creator->division_id,
            'created_by' => $creator->id,
            'current_handler_id' => ($handler ?? $creator)->id,
            'status' => $status,
        ]);
    }

    private function createVersion(Document $document, User $uploader): DocumentVersion
    {
        return DocumentVersion::create([
            'document_id' => $document->id,
            'version_number' => 1,
            'uploaded_by' => $uploader->id,
            'storage_path' => 'documents/test.pdf',
            'disk' => 'local',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
        ]);
    }
}
