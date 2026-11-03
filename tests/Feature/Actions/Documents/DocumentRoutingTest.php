<?php

namespace Tests\Feature\Actions\Documents;

use App\Actions\Documents\AcknowledgeDocumentRoute;
use App\Actions\Documents\DeclineDocumentRoute;
use App\Actions\Documents\ForwardDocument;
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

class DocumentRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_acknowledged_handler_can_forward_across_divisions_with_version_and_history_intact(): void
    {
        [$document, $version, , $handler, $initialRoute] = $this->createInProgressDocument();
        $recipient = $this->createEmployee();
        $this->actingAs($handler);

        $route = app(ForwardDocument::class)->handle($document, [
            'recipient_id' => $recipient->id,
            'remarks' => 'For review',
        ]);

        $pendingDocument = $document->fresh();
        $this->assertSame('pending_acknowledgment', $pendingDocument->status);
        $this->assertSame($recipient->id, $pendingDocument->current_handler_id);
        $this->assertSame($version->id, $route->document_version_id);
        $this->assertSame($handler->id, $route->from_user_id);
        $this->assertSame($recipient->id, $route->to_user_id);
        $this->assertSame($handler->division_id, $route->from_division_id);
        $this->assertSame($recipient->division_id, $route->to_division_id);
        $this->assertSame('forward', $route->route_type);
        $this->assertSame('pending', $route->status);
        $this->assertSame('acknowledged', $initialRoute->fresh()->status);
        $this->assertSame($version->id, $initialRoute->fresh()->document_version_id);
        $this->assertDatabaseHas('document_actions', [
            'document_id' => $document->id,
            'document_route_id' => $route->id,
            'performed_by' => $handler->id,
            'action' => 'forwarded',
        ]);
    }

    public function test_forwarding_rejects_self_and_any_attempt_while_acknowledgment_is_pending(): void
    {
        [$document, , , $handler] = $this->createInProgressDocument();
        $recipient = $this->createEmployee();
        $this->actingAs($handler);

        try {
            app(ForwardDocument::class)->handle($document, ['recipient_id' => $handler->id]);
            $this->fail('Expected self-forwarding validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('recipient_id', $exception->errors());
        }

        app(ForwardDocument::class)->handle($document->fresh(), ['recipient_id' => $recipient->id]);

        foreach ([$handler, $recipient] as $user) {
            $this->actingAs($user);
            try {
                app(ForwardDocument::class)->handle($document->fresh(), ['recipient_id' => $this->createEmployee()->id]);
                $this->fail('Expected forwarding during pending acknowledgment to fail.');
            } catch (AuthorizationException) {
                $this->assertSame('pending_acknowledgment', $document->fresh()->status);
            }
        }

        $this->assertDatabaseCount('document_routes', 2);
    }

    public function test_pending_recipient_can_acknowledge_once_and_become_the_acknowledged_handler(): void
    {
        [$document, , , $handler] = $this->createInProgressDocument();
        $recipient = $this->createEmployee();
        $this->actingAs($handler);
        $route = app(ForwardDocument::class)->handle($document, ['recipient_id' => $recipient->id]);
        $this->actingAs($recipient);

        $acknowledged = app(AcknowledgeDocumentRoute::class)->handle($route);

        $this->assertSame('acknowledged', $acknowledged->status);
        $this->assertNotNull($acknowledged->acknowledged_at);
        $this->assertSame('in_progress', $document->fresh()->status);
        $this->assertSame($recipient->id, $document->fresh()->current_handler_id);
        $this->assertDatabaseHas('document_actions', [
            'document_route_id' => $route->id,
            'performed_by' => $recipient->id,
            'action' => 'acknowledged',
        ]);

        $this->expectException(AuthorizationException::class);
        app(AcknowledgeDocumentRoute::class)->handle($route->fresh());
    }

    public function test_only_pending_recipient_can_decline_with_a_reason_and_responsibility_returns_to_sender(): void
    {
        [$document, , , $handler] = $this->createInProgressDocument();
        $recipient = $this->createEmployee();
        $outsider = $this->createEmployee();
        $this->actingAs($handler);
        $route = app(ForwardDocument::class)->handle($document, ['recipient_id' => $recipient->id]);

        $this->actingAs($recipient);
        try {
            app(DeclineDocumentRoute::class)->handle($route, ['reason' => '']);
            $this->fail('Expected a decline reason to be required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $this->actingAs($outsider);
        try {
            app(DeclineDocumentRoute::class)->handle($route, ['reason' => 'Not mine']);
            $this->fail('Expected an outsider to be denied.');
        } catch (AuthorizationException) {
            $this->assertSame('pending', $route->fresh()->status);
        }

        $this->actingAs($recipient);
        $declined = app(DeclineDocumentRoute::class)->handle($route, ['reason' => 'Wrong office']);

        $this->assertSame('declined', $declined->status);
        $this->assertSame('Wrong office', $declined->decline_reason);
        $this->assertNotNull($declined->declined_at);
        $this->assertSame($handler->id, $document->fresh()->current_handler_id);
        $this->assertSame('in_progress', $document->fresh()->status);
        $this->assertDatabaseHas('document_actions', [
            'document_route_id' => $route->id,
            'performed_by' => $recipient->id,
            'action' => 'declined',
            'remarks' => 'Wrong office',
        ]);

        $this->expectException(AuthorizationException::class);
        app(DeclineDocumentRoute::class)->handle($route->fresh(), ['reason' => 'Again']);
    }

    /**
     * @return array{Document, DocumentVersion, User, User, DocumentRoute}
     */
    private function createInProgressDocument(): array
    {
        $creator = $this->createEmployee();
        $handler = $this->createEmployee();
        $type = DocumentType::create([
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->lexify('TYPE-???'),
        ]);
        $document = Document::create([
            'tracking_number' => 'FDCP-'.fake()->unique()->numerify('########'),
            'title' => 'Routed document',
            'document_type_id' => $type->id,
            'origin_division_id' => $creator->division_id,
            'created_by' => $creator->id,
            'current_handler_id' => $handler->id,
            'status' => 'in_progress',
            'submitted_at' => now(),
        ]);
        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'version_number' => 1,
            'uploaded_by' => $creator->id,
            'storage_path' => "documents/{$document->id}/versions/test.pdf",
            'disk' => 'local',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
        ]);
        $document->update(['current_version_id' => $version->id]);
        $initialRoute = DocumentRoute::create([
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

        return [$document->fresh(), $version, $creator, $handler, $initialRoute];
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
