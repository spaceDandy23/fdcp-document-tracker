<?php

namespace Tests\Feature;

use App\Actions\Documents\ReassignDocument;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentAction;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DocumentReassignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_reassign_an_inactive_handler_and_notify_the_new_handler(): void
    {
        $admin = $this->employee(['is_admin' => true]);
        $inactiveHandler = $this->employee(['is_active' => false]);
        $newHandler = $this->employee();
        $document = $this->inProgressDocument($inactiveHandler);
        $trackingNumber = $document->tracking_number;
        $versionId = $document->current_version_id;
        $this->actingAs($admin);

        $route = app(ReassignDocument::class)->handle($document, [
            'recipient_id' => $newHandler->id,
            'reason' => 'The assigned employee is no longer active.',
        ]);

        $document->refresh();
        $this->assertSame('reassignment', $route->route_type);
        $this->assertSame('pending', $route->status);
        $this->assertSame($inactiveHandler->id, $route->from_user_id);
        $this->assertSame($newHandler->id, $route->to_user_id);
        $this->assertSame('pending_acknowledgment', $document->status);
        $this->assertSame($newHandler->id, $document->current_handler_id);
        $this->assertSame($trackingNumber, $document->tracking_number);
        $this->assertSame($versionId, $document->current_version_id);
        $this->assertDatabaseHas('document_actions', [
            'document_id' => $document->id,
            'performed_by' => $admin->id,
            'action' => 'reassigned',
            'document_route_id' => $route->id,
            'remarks' => 'The assigned employee is no longer active.',
        ]);
        $this->assertSame($admin->id, $document->actions()->latest('id')->firstOrFail()->metadata['administrator_id']);
        $this->assertSame('document_reassigned', $newHandler->notifications()->sole()->data['event_type']);
    }

    public function test_reassignment_requires_an_administrator_and_an_active_target(): void
    {
        $staff = $this->employee();
        $inactiveHandler = $this->employee(['is_active' => false]);
        $target = $this->employee(['is_active' => false]);
        $document = $this->inProgressDocument($inactiveHandler);

        $this->actingAs($staff);
        $this->expectException(AuthorizationException::class);
        app(ReassignDocument::class)->handle($document, ['recipient_id' => $target->id, 'reason' => 'Not authorized.']);
    }

    public function test_reassignment_rejects_inactive_targets_and_terminal_documents_without_changes(): void
    {
        $admin = $this->employee(['is_admin' => true]);
        $inactiveHandler = $this->employee(['is_active' => false]);
        $inactiveTarget = $this->employee(['is_active' => false]);
        $document = $this->inProgressDocument($inactiveHandler);
        $this->actingAs($admin);

        try {
            app(ReassignDocument::class)->handle($document, ['recipient_id' => $inactiveTarget->id, 'reason' => 'Target is inactive.']);
            $this->fail('An inactive target should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('recipient_id', $exception->errors());
        }

        $document->update(['status' => 'completed', 'current_handler_id' => null]);

        $this->expectException(AuthorizationException::class);
        app(ReassignDocument::class)->handle($document->fresh(), ['recipient_id' => $inactiveHandler->id, 'reason' => 'Terminal document.']);
    }

    public function test_stale_reassignment_is_rejected_and_repeat_reassignment_is_not_created(): void
    {
        $admin = $this->employee(['is_admin' => true]);
        $inactiveHandler = $this->employee(['is_active' => false]);
        $target = $this->employee();
        $document = $this->inProgressDocument($inactiveHandler);
        $this->actingAs($admin);

        $inactiveHandler->update(['is_active' => true]);

        try {
            app(ReassignDocument::class)->handle($document->fresh(), ['recipient_id' => $target->id, 'reason' => 'Stale handler state.']);
            $this->fail('A stale reassignment should be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('This action is unauthorized.', $exception->getMessage());
        }
        $this->assertDatabaseCount('document_routes', 0);
    }

    public function test_successful_reassignment_cannot_be_repeated(): void
    {
        $admin = $this->employee(['is_admin' => true]);
        $inactiveHandler = $this->employee(['is_active' => false]);
        $firstTarget = $this->employee();
        $secondTarget = $this->employee();
        $document = $this->inProgressDocument($inactiveHandler);
        $this->actingAs($admin);

        app(ReassignDocument::class)->handle($document, ['recipient_id' => $firstTarget->id, 'reason' => 'First reassignment.']);

        try {
            app(ReassignDocument::class)->handle($document->fresh(), ['recipient_id' => $secondTarget->id, 'reason' => 'Repeat reassignment.']);
            $this->fail('A repeat reassignment should be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('This action is unauthorized.', $exception->getMessage());
        }
        $this->assertDatabaseCount('document_routes', 1);
    }

    public function test_pending_reassignment_supersedes_the_old_pending_route(): void
    {
        $admin = $this->employee(['is_admin' => true]);
        $inactiveHandler = $this->employee(['is_active' => false]);
        $newHandler = $this->employee();
        $document = $this->inProgressDocument($inactiveHandler, 'pending_acknowledgment');
        $oldRoute = $document->routes()->create([
            'document_version_id' => $document->current_version_id,
            'from_user_id' => $document->created_by,
            'to_user_id' => $inactiveHandler->id,
            'from_division_id' => $document->origin_division_id,
            'to_division_id' => $inactiveHandler->division_id,
            'route_type' => 'initial',
            'status' => 'pending',
            'forwarded_at' => now(),
        ]);
        $this->actingAs($admin);

        app(ReassignDocument::class)->handle($document, ['recipient_id' => $newHandler->id, 'reason' => 'Replace inactive recipient.']);

        $this->assertSame('declined', $oldRoute->fresh()->status);
        $this->assertSame(2, $document->routes()->count());
        $this->assertSame('pending_acknowledgment', $document->fresh()->status);
    }

    public function test_revision_requested_reassignment_preserves_revision_work_state(): void
    {
        $admin = $this->employee(['is_admin' => true]);
        $inactiveHandler = $this->employee(['is_active' => false]);
        $newHandler = $this->employee();
        $document = $this->inProgressDocument($inactiveHandler, 'revision_requested');
        $this->actingAs($admin);

        $route = app(ReassignDocument::class)->handle($document, ['recipient_id' => $newHandler->id, 'reason' => 'Continue revision work.']);

        $this->assertSame('acknowledged', $route->status);
        $this->assertNotNull($route->acknowledged_at);
        $this->assertSame('revision_requested', $document->fresh()->status);
        $this->assertSame($newHandler->id, $document->fresh()->current_handler_id);
    }

    public function test_reassignment_rolls_back_document_route_and_state_when_audit_persistence_fails(): void
    {
        $admin = $this->employee(['is_admin' => true]);
        $inactiveHandler = $this->employee(['is_active' => false]);
        $newHandler = $this->employee();
        $document = $this->inProgressDocument($inactiveHandler);
        $this->actingAs($admin);
        DocumentAction::creating(function (): void {
            throw new RuntimeException('Audit persistence failed.');
        });

        try {
            app(ReassignDocument::class)->handle($document, ['recipient_id' => $newHandler->id, 'reason' => 'Rollback test.']);
            $this->fail('The reassignment should fail when the audit cannot be persisted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit persistence failed.', $exception->getMessage());
        } finally {
            DocumentAction::flushEventListeners();
        }

        $fresh = $document->fresh();
        $this->assertSame($inactiveHandler->id, $fresh->current_handler_id);
        $this->assertSame('in_progress', $fresh->status);
        $this->assertDatabaseCount('document_routes', 0);
        $this->assertDatabaseMissing('document_actions', ['action' => 'reassigned']);
        $this->assertCount(0, $newHandler->notifications);
    }

    public function test_administrator_can_reassign_from_the_document_detail_livewire_page(): void
    {
        $admin = $this->employee(['is_admin' => true]);
        $inactiveHandler = $this->employee(['is_active' => false]);
        $newHandler = $this->employee();
        $document = $this->inProgressDocument($inactiveHandler);

        Livewire::actingAs($admin)->test('pages::documents.show', ['document' => $document])
            ->assertSee('Reassign document')
            ->set('reassignmentRecipientId', (string) $newHandler->id)
            ->set('reassignmentReason', 'Assign to an active employee.')
            ->call('reassign')
            ->assertHasNoErrors();

        $this->assertSame($newHandler->id, $document->fresh()->current_handler_id);
    }

    private function employee(array $attributes = []): User
    {
        $division = Division::query()->create([
            'sso_division_id' => fake()->uuid(),
            'name' => fake()->company(),
            'code' => fake()->unique()->lexify('DIV-???'),
            'is_active' => true,
        ]);

        return User::factory()->create(array_merge(['division_id' => $division->id], $attributes));
    }

    private function inProgressDocument(User $handler, string $status = 'in_progress'): Document
    {
        $type = DocumentType::query()->create([
            'name' => 'Memorandum',
            'code' => fake()->unique()->lexify('MEM-???'),
            'is_active' => true,
        ]);
        $document = Document::query()->create([
            'tracking_number' => 'FDCP-2026-REASSIGN',
            'title' => 'Reassignment document',
            'document_type_id' => $type->id,
            'origin_division_id' => $handler->division_id,
            'created_by' => $handler->id,
            'current_handler_id' => $handler->id,
            'status' => $status,
            'submitted_at' => now(),
        ]);
        $version = DocumentVersion::query()->create([
            'document_id' => $document->id,
            'version_number' => 1,
            'uploaded_by' => $handler->id,
            'storage_path' => 'documents/reassignment.pdf',
            'disk' => 'local',
            'original_filename' => 'reassignment.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);
        $document->update(['current_version_id' => $version->id]);

        return $document->fresh();
    }
}
