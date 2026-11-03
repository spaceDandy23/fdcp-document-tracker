<?php

namespace Tests\Feature\Actions\Documents;

use App\Actions\Documents\CancelDocument;
use App\Actions\Documents\CompleteDocument;
use App\Actions\Documents\DeclineDocumentRoute;
use App\Actions\Documents\ForwardDocument;
use App\Actions\Documents\ReturnDocumentForRevision;
use App\Actions\Documents\SubmitDocument;
use App\Actions\Documents\UpdateDocumentDraft;
use App\Actions\Documents\UploadDocumentVersion;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentAction;
use App\Models\DocumentRoute;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class DocumentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_acknowledged_handler_can_return_to_immediate_sender_for_revision(): void
    {
        [$document, $version, $creator, $handler] = $this->createInProgressDocument();
        $trackingNumber = $document->tracking_number;
        $this->actingAs($handler);

        $route = app(ReturnDocumentForRevision::class)->handle($document, [
            'reason' => 'Please correct the figures.',
        ]);

        $revisingDocument = $document->fresh();
        $this->assertSame('revision_return', $route->route_type);
        $this->assertSame('acknowledged', $route->status);
        $this->assertSame($version->id, $route->document_version_id);
        $this->assertSame($handler->id, $route->from_user_id);
        $this->assertSame($creator->id, $route->to_user_id);
        $this->assertSame('Please correct the figures.', $route->remarks);
        $this->assertNotNull($route->acknowledged_at);
        $this->assertSame('revision_requested', $revisingDocument->status);
        $this->assertSame($creator->id, $revisingDocument->current_handler_id);
        $this->assertSame($trackingNumber, $revisingDocument->tracking_number);
        $this->assertDatabaseHas('document_actions', [
            'document_id' => $document->id,
            'document_route_id' => $route->id,
            'performed_by' => $handler->id,
            'action' => 'revision_returned',
        ]);
    }

    public function test_revision_return_requires_reason_authorization_and_an_eligible_previous_handler(): void
    {
        [$document, , , $handler] = $this->createInProgressDocument();
        $outsider = $this->createEmployee();
        $this->actingAs($handler);

        try {
            app(ReturnDocumentForRevision::class)->handle($document, ['reason' => '']);
            $this->fail('Expected a revision reason to be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        try {
            app(ReturnDocumentForRevision::class)->handle($document, [
                'reason' => 'Incorrect recipient.',
                'recipient_id' => $outsider->id,
            ]);
            $this->fail('Expected a non-previous handler to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('recipient_id', $exception->errors());
        }

        $this->actingAs($outsider);
        $this->expectException(AuthorizationException::class);
        app(ReturnDocumentForRevision::class)->handle($document, ['reason' => 'Unauthorized']);
    }

    public function test_revision_return_rolls_back_route_and_state_when_audit_logging_fails(): void
    {
        [$document, , , $handler] = $this->createInProgressDocument();
        $originalRouteCount = $document->routes()->count();
        $this->actingAs($handler);
        DocumentAction::creating(function (): void {
            throw new RuntimeException('Audit failed.');
        });

        try {
            app(ReturnDocumentForRevision::class)->handle($document, ['reason' => 'Revise this']);
            $this->fail('Expected the failed audit to roll back the return.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit failed.', $exception->getMessage());
        }

        $this->assertSame($originalRouteCount, $document->routes()->count());
        $this->assertSame('in_progress', $document->fresh()->status);
        $this->assertSame($handler->id, $document->fresh()->current_handler_id);
    }

    public function test_revision_handler_must_supply_change_summary_and_uploads_an_immutable_version(): void
    {
        Storage::fake('local');
        [$document, $originalVersion, $creator, $handler] = $this->createInProgressDocument();
        $trackingNumber = $document->tracking_number;
        $this->actingAs($handler);
        app(ReturnDocumentForRevision::class)->handle($document, ['reason' => 'Revise this']);
        $this->actingAs($creator);

        try {
            app(UploadDocumentVersion::class)->handle(
                $document->fresh(),
                UploadedFile::fake()->create('revision.pdf', 100, 'application/pdf'),
            );
            $this->fail('Expected a change summary to be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('change_summary', $exception->errors());
        }

        $revision = app(UploadDocumentVersion::class)->handle(
            $document->fresh(),
            UploadedFile::fake()->create('revision.pdf', 100, 'application/pdf'),
            'Corrected the financial figures.',
        );

        $this->assertSame(2, $revision->version_number);
        $this->assertSame('Corrected the financial figures.', $revision->change_summary);
        $this->assertSame($revision->id, $document->fresh()->current_version_id);
        $this->assertSame($trackingNumber, $document->fresh()->tracking_number);
        $this->assertDatabaseHas('document_versions', ['id' => $originalVersion->id, 'version_number' => 1]);
        $this->assertDatabaseCount('document_versions', 2);
        Storage::disk('local')->assertExists($revision->storage_path);
    }

    public function test_revision_resubmission_requires_new_version_and_decline_restores_revision_state(): void
    {
        Storage::fake('local');
        [$document, $originalVersion, $creator, $handler, $incomingRoute] = $this->createInProgressDocument();
        $trackingNumber = $document->tracking_number;
        $this->actingAs($handler);
        $returnRoute = app(ReturnDocumentForRevision::class)->handle($document, ['reason' => 'Revise this']);
        $this->actingAs($creator);

        try {
            app(SubmitDocument::class)->handle($document->fresh(), ['recipient_id' => $handler->id]);
            $this->fail('Expected a new revision version before resubmission.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
        }

        $revision = app(UploadDocumentVersion::class)->handle(
            $document->fresh(),
            UploadedFile::fake()->create('revision.pdf', 100, 'application/pdf'),
            'Applied requested corrections.',
        );
        $resubmission = app(SubmitDocument::class)->handle($document->fresh(), [
            'recipient_id' => $handler->id,
        ]);

        $this->assertSame('revision_resubmission', $resubmission->route_type);
        $this->assertSame($revision->id, $resubmission->document_version_id);
        $this->assertSame('pending_acknowledgment', $document->fresh()->status);
        $this->assertSame($trackingNumber, $document->fresh()->tracking_number);
        $this->assertSame($originalVersion->id, $incomingRoute->fresh()->document_version_id);
        $this->assertSame($originalVersion->id, $returnRoute->fresh()->document_version_id);
        $this->assertDatabaseHas('document_actions', [
            'document_route_id' => $resubmission->id,
            'action' => 'revision_resubmitted',
        ]);

        $this->actingAs($handler);
        app(DeclineDocumentRoute::class)->handle($resubmission, ['reason' => 'More changes needed']);

        $this->assertSame('revision_requested', $document->fresh()->status);
        $this->assertSame($creator->id, $document->fresh()->current_handler_id);
        $this->assertSame($trackingNumber, $document->fresh()->tracking_number);
        $this->assertDatabaseCount('document_versions', 2);
        $this->assertDatabaseCount('document_routes', 3);

        $this->actingAs($creator);
        try {
            app(SubmitDocument::class)->handle($document->fresh(), ['recipient_id' => $handler->id]);
            $this->fail('Expected the declined revision version to require another revision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
        }

        $this->assertDatabaseCount('document_routes', 3);
    }

    public function test_only_current_acknowledged_handler_can_complete_once_and_completed_document_is_terminal(): void
    {
        [$document, , , $handler] = $this->createInProgressDocument();
        $outsider = $this->createEmployee();
        $this->actingAs($outsider);

        try {
            app(CompleteDocument::class)->handle($document);
            $this->fail('Expected an outsider to be denied.');
        } catch (AuthorizationException) {
            $this->assertSame('in_progress', $document->fresh()->status);
        }

        $this->actingAs($handler);
        $completed = app(CompleteDocument::class)->handle($document->fresh());

        $this->assertSame('completed', $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertNull($completed->current_handler_id);
        $this->assertDatabaseHas('document_actions', [
            'document_id' => $document->id,
            'performed_by' => $handler->id,
            'action' => 'completed',
        ]);

        foreach ([CompleteDocument::class, ForwardDocument::class] as $action) {
            try {
                $action === CompleteDocument::class
                    ? app($action)->handle($document->fresh())
                    : app($action)->handle($document->fresh(), ['recipient_id' => $outsider->id]);
                $this->fail('Expected the completed document transition to be denied.');
            } catch (AuthorizationException) {
                $this->assertSame('completed', $document->fresh()->status);
            }
        }
    }

    public function test_creator_can_cancel_own_draft_once_and_cancelled_document_is_terminal(): void
    {
        $creator = $this->createEmployee();
        $outsider = $this->createEmployee();
        $draft = $this->createDraft($creator);
        $this->actingAs($outsider);

        try {
            app(CancelDocument::class)->handle($draft);
            $this->fail('Expected an outsider to be denied.');
        } catch (AuthorizationException) {
            $this->assertSame('draft', $draft->fresh()->status);
        }

        $this->actingAs($creator);
        $cancelled = app(CancelDocument::class)->handle($draft->fresh());

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertNull($cancelled->current_handler_id);
        $this->assertDatabaseHas('document_actions', [
            'document_id' => $draft->id,
            'performed_by' => $creator->id,
            'action' => 'cancelled',
        ]);

        foreach ([CancelDocument::class, UpdateDocumentDraft::class, SubmitDocument::class] as $action) {
            try {
                match ($action) {
                    CancelDocument::class => app($action)->handle($draft->fresh()),
                    UpdateDocumentDraft::class => app($action)->handle($draft->fresh(), ['title' => 'Too late']),
                    SubmitDocument::class => app($action)->handle($draft->fresh(), ['recipient_id' => $outsider->id]),
                };
                $this->fail('Expected the cancelled document transition to be denied.');
            } catch (AuthorizationException) {
                $this->assertSame('cancelled', $draft->fresh()->status);
            }
        }
    }

    /**
     * @return array{Document, DocumentVersion, User, User, DocumentRoute}
     */
    private function createInProgressDocument(): array
    {
        $creator = $this->createEmployee();
        $handler = $this->createEmployee();
        $document = $this->createDraft($creator);
        $document->update([
            'tracking_number' => 'FDCP-'.fake()->unique()->numerify('########'),
            'current_handler_id' => $handler->id,
            'status' => 'in_progress',
            'submitted_at' => now(),
        ]);
        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'version_number' => 1,
            'uploaded_by' => $creator->id,
            'storage_path' => "documents/{$document->id}/versions/original.pdf",
            'disk' => 'local',
            'original_filename' => 'original.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
        ]);
        $document->update(['current_version_id' => $version->id]);
        $route = DocumentRoute::create([
            'document_id' => $document->id,
            'document_version_id' => $version->id,
            'from_user_id' => $creator->id,
            'to_user_id' => $handler->id,
            'from_division_id' => $creator->division_id,
            'to_division_id' => $handler->division_id,
            'route_type' => 'initial',
            'status' => 'acknowledged',
            'forwarded_at' => now()->subMinute(),
            'acknowledged_at' => now(),
        ]);

        return [$document->fresh(), $version, $creator, $handler, $route];
    }

    private function createDraft(User $creator): Document
    {
        $type = DocumentType::create([
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->lexify('TYPE-???'),
        ]);

        return Document::create([
            'title' => 'Lifecycle document',
            'document_type_id' => $type->id,
            'origin_division_id' => $creator->division_id,
            'created_by' => $creator->id,
            'current_handler_id' => $creator->id,
            'status' => 'draft',
        ]);
    }

    private function createEmployee(): User
    {
        $division = Division::create([
            'sso_division_id' => fake()->uuid(),
            'name' => fake()->unique()->company(),
        ]);

        return User::factory()->create(['division_id' => $division->id]);
    }
}
