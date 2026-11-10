<?php

namespace Tests\Feature\Livewire;

use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['documents.disk' => 'local']);
    }

    public function test_unauthorized_employee_cannot_view_or_mutate_a_document(): void
    {
        $owner = $this->employee();
        $outsider = $this->employee();
        $document = $this->document($owner);

        $this->actingAs($outsider)->get(route('documents.show', $document))->assertForbidden();
    }

    public function test_creator_edits_uploads_submits_and_cancels_drafts(): void
    {
        $creator = $this->employee();
        $recipient = $this->employee();
        $document = $this->document($creator);

        Livewire::actingAs($creator)->test('pages::documents.show', ['document' => $document])
            ->set('title', 'Updated title')->call('saveDraft')->assertHasNoErrors()
            ->set('file', UploadedFile::fake()->create('memo.pdf', 20, 'application/pdf'))->call('uploadVersion')->assertHasNoErrors()
            ->set('recipientId', (string) $recipient->id)->call('submit')->assertHasNoErrors();

        $document->refresh();
        $this->assertSame('Updated title', $document->title);
        $this->assertSame('pending_acknowledgment', $document->status);
        $this->assertSame($recipient->id, $document->current_handler_id);
        $this->assertNotNull($document->tracking_number);

        $draft = $this->document($creator, ['title' => 'Disposable draft']);
        Livewire::actingAs($creator)->test('pages::documents.show', ['document' => $draft])->call('cancel')->assertHasNoErrors();
        $this->assertSame('cancelled', $draft->fresh()->status);
    }

    public function test_creator_can_switch_between_predefined_and_custom_document_types(): void
    {
        $creator = $this->employee();
        $predefinedType = DocumentType::query()->firstOrCreate(['code' => 'MEM'], ['name' => 'Memorandum', 'is_active' => true]);
        $customDocument = $this->document($creator, [
            'document_type_id' => null,
            'custom_document_type' => 'External endorsement',
        ]);

        Livewire::actingAs($creator)->test('pages::documents.show', ['document' => $customDocument])
            ->assertSet('documentTypeId', 'other')
            ->assertSet('customDocumentType', 'External endorsement')
            ->set('documentTypeId', (string) $predefinedType->id)
            ->assertSet('customDocumentType', '')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('documents', [
            'id' => $customDocument->id,
            'document_type_id' => $predefinedType->id,
            'custom_document_type' => null,
        ]);

        Livewire::actingAs($creator)->test('pages::documents.show', ['document' => $customDocument->fresh()])
            ->set('documentTypeId', 'other')
            ->set('customDocumentType', '  External certification  ')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('documents', [
            'id' => $customDocument->id,
            'document_type_id' => null,
            'custom_document_type' => 'External certification',
        ]);
    }

    public function test_upload_validation_rejects_unsupported_files(): void
    {
        $creator = $this->employee();
        $document = $this->document($creator);

        Livewire::actingAs($creator)->test('pages::documents.show', ['document' => $document])
            ->set('file', UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream'))
            ->call('uploadVersion')->assertHasErrors(['file']);

        $this->assertDatabaseCount('document_versions', 0);
    }

    public function test_authorized_user_uploads_and_sees_supporting_attachments(): void
    {
        $creator = $this->employee();
        $document = $this->document($creator);

        Livewire::actingAs($creator)->test('pages::documents.show', ['document' => $document])
            ->set('attachmentFiles', [
                UploadedFile::fake()->create('support.pdf', 20, 'application/pdf'),
                UploadedFile::fake()->create('appendix.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ])
            ->call('uploadAttachments')
            ->assertHasNoErrors()
            ->assertSee('support.pdf')
            ->assertSee('appendix.docx');

        $this->assertSame(2, $document->attachments()->count());
        $this->assertDatabaseHas('document_actions', ['document_id' => $document->id, 'action' => 'attachment_uploaded']);
    }

    public function test_creator_removes_a_supporting_attachment_with_a_reason(): void
    {
        $creator = $this->employee();
        $document = $this->document($creator);

        Livewire::actingAs($creator)->test('pages::documents.show', ['document' => $document])
            ->set('attachmentFiles', [UploadedFile::fake()->create('obsolete.pdf', 20, 'application/pdf')])
            ->call('uploadAttachments')
            ->assertHasNoErrors();

        $attachment = $document->attachments()->sole();

        Livewire::actingAs($creator)->test('pages::documents.show', ['document' => $document->fresh()])
            ->set("removalReasons.{$attachment->id}", 'Replaced with a corrected attachment.')
            ->call('removeAttachment', $attachment->id)
            ->assertHasNoErrors()
            ->assertSee('Removed attachments');

        $this->assertSoftDeleted('document_attachments', ['id' => $attachment->id]);
    }

    public function test_recipient_acknowledges_then_forwards_the_document(): void
    {
        $sender = $this->employee();
        $recipient = $this->employee();
        $next = $this->employee();
        $document = $this->routedDocument($sender, $recipient);

        Livewire::actingAs($recipient)->test('pages::documents.show', ['document' => $document])->call('acknowledge')->assertHasNoErrors()
            ->assertDontSeeHtml('<option value="'.$sender->id.'" wire:key="forward-recipient-'.$sender->id.'">')
            ->assertSeeHtml('<option value="'.$next->id.'" wire:key="forward-recipient-'.$next->id.'">')
            ->set('recipientId', (string) $next->id)->set('remarks', 'Please review')->call('forward')->assertHasNoErrors();

        $this->assertSame('pending_acknowledgment', $document->fresh()->status);
        $this->assertSame($next->id, $document->fresh()->current_handler_id);
        $this->assertDatabaseHas('document_routes', ['from_user_id' => $recipient->id, 'to_user_id' => $next->id, 'route_type' => 'forward']);
    }

    public function test_forwarding_back_to_the_immediate_sender_is_rejected_even_if_livewire_is_tampered_with(): void
    {
        $sender = $this->employee();
        $recipient = $this->employee();
        $document = $this->routedDocument($sender, $recipient);
        $document->routes()->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);
        $document->update(['status' => 'in_progress']);

        Livewire::actingAs($recipient)->test('pages::documents.show', ['document' => $document])
            ->assertDontSeeHtml('<option value="'.$sender->id.'" wire:key="forward-recipient-'.$sender->id.'">')
            ->set('recipientId', (string) $sender->id)
            ->call('forward')
            ->assertHasErrors(['recipient_id']);

        $this->assertDatabaseCount('document_routes', 1);
    }

    public function test_recipient_declines_with_a_required_reason(): void
    {
        $sender = $this->employee();
        $recipient = $this->employee();
        $document = $this->routedDocument($sender, $recipient);

        Livewire::actingAs($recipient)->test('pages::documents.show', ['document' => $document])->call('decline')->assertHasErrors(['reason'])
            ->set('declineReason', 'Incorrect routing')->call('decline')->assertHasNoErrors();

        $this->assertSame('in_progress', $document->fresh()->status);
        $this->assertSame($sender->id, $document->fresh()->current_handler_id);
    }

    public function test_original_sender_does_not_see_return_for_revision_when_no_previous_handler_is_eligible(): void
    {
        $sender = $this->employee();
        $recipient = $this->employee();
        $document = $this->routedDocument($sender, $recipient);
        $document->routes()->update(['status' => 'declined', 'declined_at' => now()]);
        $document->update(['status' => 'in_progress', 'current_handler_id' => $sender->id]);

        Livewire::actingAs($sender)->test('pages::documents.show', ['document' => $document->fresh()])
            ->assertDontSee('Return for revision');
    }

    public function test_acknowledged_handler_returns_for_revision_and_creator_resubmits_a_new_version(): void
    {
        $creator = $this->employee();
        $handler = $this->employee();
        $document = $this->routedDocument($creator, $handler);
        $route = $document->routes()->latest('id')->firstOrFail();
        $route->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);
        $document->update(['status' => 'in_progress']);

        Livewire::actingAs($handler)->test('pages::documents.show', ['document' => $document])
            ->assertSee('Return for revision')
            ->set('revisionReason', 'Update the signature block')->call('returnForRevision')->assertHasNoErrors();
        $oldVersionId = $document->fresh()->current_version_id;

        Livewire::actingAs($creator)->test('pages::documents.show', ['document' => $document->fresh()])
            ->assertDontSee('Return for revision')
            ->assertSee('Resubmit revision')
            ->set('file', UploadedFile::fake()->create('revised.pdf', 20, 'application/pdf'))
            ->set('changeSummary', 'Updated signature block')->call('uploadVersion')->assertHasNoErrors()
            ->set('recipientId', (string) $handler->id)->call('submit')->assertHasNoErrors();

        $this->assertSame(2, $document->versions()->count());
        $this->assertNotSame($oldVersionId, $document->fresh()->current_version_id);
        $this->assertDatabaseHas('document_routes', ['document_id' => $document->id, 'route_type' => 'revision_resubmission', 'document_version_id' => $document->fresh()->current_version_id]);
    }

    public function test_current_acknowledged_handler_completes_a_document(): void
    {
        $creator = $this->employee();
        $handler = $this->employee();
        $document = $this->routedDocument($creator, $handler);
        $document->routes()->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);
        $document->update(['status' => 'in_progress']);

        Livewire::actingAs($handler)->test('pages::documents.show', ['document' => $document])->call('complete')->assertHasNoErrors();

        $this->assertSame('completed', $document->fresh()->status);
        $this->assertNull($document->fresh()->current_handler_id);
    }

    public function test_version_download_is_private_and_scoped_to_its_document(): void
    {
        $owner = $this->employee();
        $outsider = $this->employee();
        $document = $this->document($owner);
        $version = $this->version($document, $owner);
        Storage::disk('local')->put($version->storage_path, 'private content');
        $otherDocument = $this->document($owner, ['title' => 'Other']);

        $this->actingAs($owner)->get(route('documents.versions.download', [$document, $version]))->assertDownload('memo.pdf');
        $this->actingAs($owner)->get(route('documents.versions.download', [$otherDocument, $version]))->assertNotFound();
        $this->actingAs($outsider)->get(route('documents.versions.download', [$document, $version]))->assertForbidden();
    }

    public function test_pdf_versions_and_attachments_open_the_preview_modal(): void
    {
        $creator = $this->employee();
        $document = $this->document($creator);
        $version = $this->version($document, $creator);
        $attachment = $document->attachments()->create([
            'uploaded_by' => $creator->id,
            'storage_path' => 'documents/test/support.pdf',
            'disk' => 'local',
            'original_filename' => 'support.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 15,
        ]);

        Livewire::actingAs($creator)->test('pages::documents.show', ['document' => $document])
            ->call('previewVersion', $version->id)
            ->assertSet('previewFilename', 'memo.pdf')
            ->assertSee('Full screen')
            ->call('closePreview')
            ->assertSet('previewUrl', '')
            ->call('previewAttachment', $attachment->id)
            ->assertSet('previewFilename', 'support.pdf');
    }

    private function employee(): User
    {
        $division = Division::query()->create(['sso_division_id' => fake()->uuid(), 'name' => fake()->company(), 'code' => fake()->unique()->lexify('???'), 'is_active' => true]);

        return User::factory()->create(['division_id' => $division->id]);
    }

    private function document(User $owner, array $attributes = []): Document
    {
        $type = DocumentType::query()->firstOrCreate(['code' => 'MEM'], ['name' => 'Memorandum', 'is_active' => true]);

        return Document::query()->create(array_merge(['title' => 'Test document', 'document_type_id' => $type->id, 'origin_division_id' => $owner->division_id, 'created_by' => $owner->id, 'current_handler_id' => $owner->id, 'status' => 'draft'], $attributes));
    }

    private function version(Document $document, User $uploader): DocumentVersion
    {
        $version = $document->versions()->create(['version_number' => 1, 'uploaded_by' => $uploader->id, 'storage_path' => 'documents/test/memo.pdf', 'disk' => 'local', 'original_filename' => 'memo.pdf', 'mime_type' => 'application/pdf', 'file_size' => 15]);
        $document->update(['current_version_id' => $version->id]);

        return $version;
    }

    private function routedDocument(User $sender, User $recipient): Document
    {
        $document = $this->document($sender, ['tracking_number' => 'FDCP-2026-TEST', 'status' => 'pending_acknowledgment', 'current_handler_id' => $recipient->id, 'submitted_at' => now()]);
        $version = $this->version($document, $sender);
        DocumentRoute::query()->create(['document_id' => $document->id, 'document_version_id' => $version->id, 'from_user_id' => $sender->id, 'to_user_id' => $recipient->id, 'from_division_id' => $sender->division_id, 'to_division_id' => $recipient->division_id, 'route_type' => 'initial', 'status' => 'pending', 'forwarded_at' => now()]);

        return $document->fresh();
    }
}
