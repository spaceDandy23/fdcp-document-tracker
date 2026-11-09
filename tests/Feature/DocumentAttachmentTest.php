<?php

namespace Tests\Feature;

use App\Actions\Documents\DownloadDocumentAttachment;
use App\Actions\Documents\RemoveDocumentAttachment;
use App\Actions\Documents\UploadDocumentAttachment;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentAction;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class DocumentAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['documents.disk' => 'local']);
    }

    public function test_creator_can_upload_multiple_private_attachments_and_the_uploads_are_audited(): void
    {
        $creator = $this->employee();
        $document = $this->document($creator);
        $this->actingAs($creator);

        $first = app(UploadDocumentAttachment::class)->handle($document, UploadedFile::fake()->create('budget.pdf', 100, 'application/pdf'));
        $second = app(UploadDocumentAttachment::class)->handle($document, UploadedFile::fake()->create('endorsement.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));

        $this->assertDatabaseCount('document_attachments', 2);
        $this->assertDatabaseHas('document_actions', ['document_id' => $document->id, 'performed_by' => $creator->id, 'action' => 'attachment_uploaded']);
        Storage::disk('local')->assertExists([$first->storage_path, $second->storage_path]);
        Storage::disk('public')->assertMissing([$first->storage_path, $second->storage_path]);
    }

    public function test_attachment_upload_rejects_invalid_types_and_files_over_the_configured_limit(): void
    {
        config(['documents.attachment_max_upload_kb' => 10]);
        $creator = $this->employee();
        $document = $this->document($creator);
        $this->actingAs($creator);

        foreach ([UploadedFile::fake()->create('notes.txt', 5, 'text/plain'), UploadedFile::fake()->create('large.pdf', 11, 'application/pdf')] as $file) {
            try {
                app(UploadDocumentAttachment::class)->handle($document, $file);
                $this->fail('Expected attachment validation to fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }

        $this->assertDatabaseCount('document_attachments', 0);
        Storage::disk('local')->assertEmpty();
    }

    public function test_unauthorized_users_cannot_upload_attachments(): void
    {
        $creator = $this->employee();
        $outsider = $this->employee();
        $document = $this->document($creator);
        $this->actingAs($outsider);

        $this->expectException(AuthorizationException::class);
        app(UploadDocumentAttachment::class)->handle($document, UploadedFile::fake()->create('private.pdf', 10, 'application/pdf'));
    }

    public function test_current_handler_can_upload_while_processing_but_not_pending_completed_or_cancelled(): void
    {
        $creator = $this->employee();
        $handler = $this->employee();
        $document = $this->document($creator, ['current_handler_id' => $handler->id, 'status' => 'in_progress']);
        $this->actingAs($handler);

        app(UploadDocumentAttachment::class)->handle($document, UploadedFile::fake()->create('working.pdf', 10, 'application/pdf'));
        $this->assertDatabaseCount('document_attachments', 1);

        $document->update(['status' => 'revision_requested']);
        app(UploadDocumentAttachment::class)->handle($document->fresh(), UploadedFile::fake()->create('revision.pdf', 10, 'application/pdf'));
        $this->assertDatabaseCount('document_attachments', 2);

        foreach (['pending_acknowledgment', 'completed', 'cancelled'] as $status) {
            $document->update(['status' => $status]);

            try {
                app(UploadDocumentAttachment::class)->handle($document->fresh(), UploadedFile::fake()->create("{$status}.pdf", 10, 'application/pdf'));
                $this->fail("Expected {$status} attachment upload to be denied.");
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('document_attachments', 2);
            }
        }
    }

    public function test_attachment_download_requires_visibility_and_is_scoped_to_its_document(): void
    {
        $creator = $this->employee();
        $outsider = $this->employee();
        $document = $this->document($creator);
        $otherDocument = $this->document($creator, ['title' => 'Other']);
        $this->actingAs($creator);
        $attachment = app(UploadDocumentAttachment::class)->handle($document, UploadedFile::fake()->create('secure.pdf', 10, 'application/pdf'));

        $response = app(DownloadDocumentAttachment::class)->handle($attachment);
        $this->assertSame('attachment; filename=secure.pdf', $response->headers->get('content-disposition'));
        $this->get(route('documents.attachments.download', [$otherDocument, $attachment]))->assertNotFound();

        $this->actingAs($outsider);
        $this->expectException(AuthorizationException::class);
        app(DownloadDocumentAttachment::class)->handle($attachment);
    }

    public function test_attachment_database_failures_remove_orphaned_files(): void
    {
        $creator = $this->employee();
        $document = $this->document($creator);
        $this->actingAs($creator);
        DocumentAction::creating(function (): void {
            throw new RuntimeException('Attachment audit failed.');
        });

        try {
            app(UploadDocumentAttachment::class)->handle($document, UploadedFile::fake()->create('failed.pdf', 10, 'application/pdf'));
            $this->fail('Expected attachment persistence to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Attachment audit failed.', $exception->getMessage());
        }

        $this->assertDatabaseCount('document_attachments', 0);
        $this->assertDatabaseCount('document_actions', 0);
        Storage::disk('local')->assertEmpty();
    }

    public function test_authorized_creator_soft_deletes_an_attachment_and_records_the_reason_without_deleting_its_file(): void
    {
        $creator = $this->employee();
        $document = $this->document($creator);
        $this->actingAs($creator);
        $attachment = app(UploadDocumentAttachment::class)->handle($document, UploadedFile::fake()->create('obsolete.pdf', 10, 'application/pdf'));

        $removed = app(RemoveDocumentAttachment::class)->handle($document, $attachment, ['reason' => 'Replaced with an updated appendix.']);

        $this->assertTrue($removed->trashed());
        $this->assertCount(0, $document->fresh()->attachments);
        $this->assertDatabaseHas('document_attachments', ['id' => $attachment->id]);
        $this->assertSoftDeleted('document_attachments', ['id' => $attachment->id]);
        $this->assertDatabaseHas('document_actions', [
            'document_id' => $document->id,
            'performed_by' => $creator->id,
            'action' => 'attachment_removed',
            'remarks' => 'Replaced with an updated appendix.',
        ]);
        $this->assertSame($attachment->id, DocumentAction::query()->latest('id')->firstOrFail()->metadata['document_attachment_id']);
        Storage::disk('local')->assertExists($attachment->storage_path);
    }

    public function test_attachment_removal_requires_a_reason_and_cannot_be_repeated(): void
    {
        $creator = $this->employee();
        $document = $this->document($creator);
        $this->actingAs($creator);
        $attachment = app(UploadDocumentAttachment::class)->handle($document, UploadedFile::fake()->create('obsolete.pdf', 10, 'application/pdf'));

        try {
            app(RemoveDocumentAttachment::class)->handle($document, $attachment, ['reason' => '']);
            $this->fail('Expected a removal reason to be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        app(RemoveDocumentAttachment::class)->handle($document, $attachment, ['reason' => 'Duplicate file.']);

        try {
            app(RemoveDocumentAttachment::class)->handle($document, $attachment->fresh(), ['reason' => 'Try again.']);
            $this->fail('Expected repeat attachment removal to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attachment', $exception->errors());
        }
    }

    public function test_current_handler_can_remove_an_attachment_while_the_document_is_in_progress(): void
    {
        $creator = $this->employee();
        $handler = $this->employee();
        $document = $this->document($creator);
        $this->actingAs($creator);
        $attachment = app(UploadDocumentAttachment::class)->handle($document, UploadedFile::fake()->create('working.pdf', 10, 'application/pdf'));
        $document->update(['current_handler_id' => $handler->id, 'status' => 'in_progress']);
        $this->actingAs($handler);

        app(RemoveDocumentAttachment::class)->handle($document->fresh(), $attachment, ['reason' => 'No longer needed for processing.']);

        $this->assertSoftDeleted('document_attachments', ['id' => $attachment->id]);
    }

    public function test_attachment_removal_is_forbidden_for_pending_completed_and_cancelled_documents(): void
    {
        $creator = $this->employee();
        $handler = $this->employee();
        $document = $this->document($creator);
        $this->actingAs($creator);
        $attachment = app(UploadDocumentAttachment::class)->handle($document, UploadedFile::fake()->create('restricted.pdf', 10, 'application/pdf'));
        $document->update(['current_handler_id' => $handler->id]);
        $this->actingAs($handler);

        foreach (['pending_acknowledgment', 'completed', 'cancelled'] as $status) {
            $document->update(['status' => $status]);

            try {
                app(RemoveDocumentAttachment::class)->handle($document->fresh(), $attachment->fresh(), ['reason' => 'Not allowed.']);
                $this->fail("Expected {$status} removal to be denied.");
            } catch (AuthorizationException) {
                $this->assertFalse($attachment->fresh()->trashed());
            }
        }
    }

    public function test_attachment_removal_cannot_target_an_attachment_from_another_document(): void
    {
        $creator = $this->employee();
        $document = $this->document($creator);
        $otherDocument = $this->document($creator, ['title' => 'Other document']);
        $this->actingAs($creator);
        $attachment = app(UploadDocumentAttachment::class)->handle($document, UploadedFile::fake()->create('scoped.pdf', 10, 'application/pdf'));

        $this->expectException(ModelNotFoundException::class);
        app(RemoveDocumentAttachment::class)->handle($otherDocument, $attachment, ['reason' => 'Wrong document.']);
    }

    private function employee(): User
    {
        $division = Division::query()->create([
            'sso_division_id' => fake()->uuid(),
            'name' => fake()->unique()->company(),
            'code' => fake()->unique()->lexify('???'),
            'is_active' => true,
        ]);

        return User::factory()->create(['division_id' => $division->id]);
    }

    private function document(User $creator, array $attributes = []): Document
    {
        $type = DocumentType::query()->firstOrCreate(['code' => 'MEM'], ['name' => 'Memorandum', 'is_active' => true]);

        return Document::query()->create(array_merge([
            'title' => 'Draft',
            'document_type_id' => $type->id,
            'origin_division_id' => $creator->division_id,
            'created_by' => $creator->id,
            'current_handler_id' => $creator->id,
            'status' => 'draft',
        ], $attributes));
    }
}
