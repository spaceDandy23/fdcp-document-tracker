<?php

namespace Tests\Feature;

use App\Actions\Documents\AcknowledgeDocumentRoute;
use App\Actions\Documents\CompleteDocument;
use App\Actions\Documents\DeclineDocumentRoute;
use App\Actions\Documents\ForwardDocument;
use App\Actions\Documents\ReturnDocumentForRevision;
use App\Actions\Documents\SubmitDocument;
use App\Actions\Documents\UploadDocumentVersion;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DocumentNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['documents.disk' => 'local']);
    }

    public function test_initial_submission_and_forward_notify_only_each_receiving_employee(): void
    {
        $creator = $this->employee();
        $firstRecipient = $this->employee();
        $secondRecipient = $this->employee();
        $document = $this->draft($creator);
        $version = $this->version($document, $creator);
        $this->actingAs($creator);

        $initialRoute = app(SubmitDocument::class)->handle($document, ['recipient_id' => $firstRecipient->id]);

        $initialNotification = $firstRecipient->notifications()->sole();
        $this->assertSame('document_forwarded', $initialNotification->data['event_type']);
        $this->assertSame($document->id, $initialNotification->data['document_id']);
        $this->assertSame($document->fresh()->tracking_number, $initialNotification->data['tracking_number']);
        $this->assertSame('Document '.$document->fresh()->tracking_number.' was sent to you.', $initialNotification->data['message']);
        $this->assertSame(['event_type', 'document_id', 'tracking_number', 'message'], array_keys($initialNotification->data));
        $this->assertSame(0, $creator->notifications()->count());

        $this->actingAs($firstRecipient);
        app(AcknowledgeDocumentRoute::class)->handle($initialRoute);
        app(ForwardDocument::class)->handle($document->fresh(), ['recipient_id' => $secondRecipient->id]);

        $forwardNotification = $secondRecipient->notifications()->sole();
        $this->assertSame('document_forwarded', $forwardNotification->data['event_type']);
        $this->assertSame($document->id, $forwardNotification->data['document_id']);
        $this->assertSame($version->id, $document->routes()->latest('id')->firstOrFail()->document_version_id);
        $this->assertSame(1, $firstRecipient->notifications()->count());
        $this->assertSame(1, $secondRecipient->notifications()->count());
    }

    public function test_declining_a_route_notifies_the_sender_and_not_the_declining_recipient(): void
    {
        $sender = $this->employee();
        $recipient = $this->employee();
        $document = $this->draft($sender, ['status' => 'in_progress']);
        $version = $this->version($document, $sender);
        $document->update(['current_handler_id' => $recipient->id]);
        $route = $this->route($document, $version, $sender, $recipient, 'forward', 'pending');
        $document->update(['status' => 'pending_acknowledgment']);
        $this->actingAs($recipient);

        app(DeclineDocumentRoute::class)->handle($route, ['reason' => 'Please correct the routing.']);

        $notification = $sender->notifications()->sole();
        $this->assertSame('document_declined', $notification->data['event_type']);
        $this->assertSame($document->id, $notification->data['document_id']);
        $this->assertSame('Your transfer of document '.$document->tracking_number.' was declined.', $notification->data['message']);
        $this->assertSame(0, $recipient->notifications()->count());
    }

    public function test_revision_return_and_resubmission_notify_the_assigned_handler_and_new_recipient(): void
    {
        $creator = $this->employee();
        $currentHandler = $this->employee();
        $resubmissionRecipient = $this->employee();
        $document = $this->draft($creator, ['status' => 'in_progress']);
        $versionOne = $this->version($document, $creator);
        $document->update(['current_handler_id' => $currentHandler->id]);
        $this->route($document, $versionOne, $creator, $currentHandler, 'initial', 'acknowledged');
        $this->actingAs($currentHandler);

        app(ReturnDocumentForRevision::class)->handle($document, [
            'reason' => 'Please correct the date.',
            'recipient_id' => $creator->id,
        ]);

        $revisionNotification = $creator->notifications()->sole();
        $this->assertSame('document_returned_for_revision', $revisionNotification->data['event_type']);
        $this->assertSame('Document '.$document->tracking_number.' was returned to you for revision.', $revisionNotification->data['message']);

        $this->actingAs($creator);
        $versionTwo = app(UploadDocumentVersion::class)->handle(
            $document->fresh(),
            UploadedFile::fake()->create('revised.pdf', 40, 'application/pdf'),
            'Corrected the date.',
        );
        $revisionRoute = app(SubmitDocument::class)->handle($document->fresh(), ['recipient_id' => $resubmissionRecipient->id]);

        $resubmissionNotification = $resubmissionRecipient->notifications()->sole();
        $this->assertSame('document_revision_resubmitted', $resubmissionNotification->data['event_type']);
        $this->assertSame($document->tracking_number, $resubmissionNotification->data['tracking_number']);
        $this->assertSame('Revised document '.$document->tracking_number.' was sent to you.', $resubmissionNotification->data['message']);
        $this->assertSame($versionTwo->id, $revisionRoute->document_version_id);
        $this->assertSame(1, $creator->notifications()->count());
    }

    public function test_completion_notifies_creator_only_when_the_creator_is_not_the_completing_handler(): void
    {
        $creator = $this->employee();
        $handler = $this->employee();
        $document = $this->draft($creator, ['status' => 'in_progress', 'current_handler_id' => $handler->id]);
        $this->actingAs($handler);

        app(CompleteDocument::class)->handle($document);

        $notification = $creator->notifications()->sole();
        $this->assertSame('document_completed', $notification->data['event_type']);
        $this->assertSame('Document '.$document->tracking_number.' was completed.', $notification->data['message']);

        $sameActorDocument = $this->draft($handler, ['status' => 'in_progress', 'current_handler_id' => $handler->id]);
        app(CompleteDocument::class)->handle($sameActorDocument);

        $this->assertSame(0, $handler->notifications()->count());
        $this->assertSame(1, $creator->notifications()->count());
    }

    public function test_rolled_back_or_rejected_transitions_do_not_create_notifications(): void
    {
        $sender = $this->employee();
        $recipient = $this->employee();
        $outsider = $this->employee();
        $document = $this->draft($sender);
        $this->version($document, $sender);
        $this->actingAs($sender);

        try {
            DB::transaction(function () use ($document, $recipient): void {
                app(SubmitDocument::class)->handle($document, ['recipient_id' => $recipient->id]);

                throw new RuntimeException('Force rollback after the nested action completes.');
            });
            $this->fail('Expected the outer transaction to roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Force rollback after the nested action completes.', $exception->getMessage());
        }

        $this->assertSame('draft', $document->fresh()->status);
        $this->assertSame(0, $document->routes()->count());
        $this->assertSame(0, $recipient->notifications()->count());

        $this->actingAs($outsider);
        $this->expectException(AuthorizationException::class);
        app(SubmitDocument::class)->handle($document->fresh(), ['recipient_id' => $recipient->id]);
    }

    public function test_notification_delivery_failure_does_not_undo_a_successful_route(): void
    {
        $sender = $this->employee();
        $recipient = $this->employee();
        $document = $this->draft($sender);
        $this->version($document, $sender);
        Event::listen(NotificationSending::class, static function (): never {
            throw new RuntimeException('Simulated notification delivery failure.');
        });
        Log::spy();
        $this->actingAs($sender);

        $route = app(SubmitDocument::class)->handle($document, ['recipient_id' => $recipient->id]);

        $this->assertSame('pending', $route->fresh()->status);
        $this->assertSame('pending_acknowledgment', $document->fresh()->status);
        $this->assertSame(1, $document->routes()->count());
        $this->assertSame(0, $recipient->notifications()->count());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_duplicate_submission_is_rejected_without_creating_another_notification(): void
    {
        $sender = $this->employee();
        $recipient = $this->employee();
        $document = $this->draft($sender);
        $this->version($document, $sender);
        $this->actingAs($sender);

        app(SubmitDocument::class)->handle($document, ['recipient_id' => $recipient->id]);

        try {
            app(SubmitDocument::class)->handle($document->fresh(), ['recipient_id' => $recipient->id]);
            $this->fail('A submitted document cannot be submitted again.');
        } catch (AuthorizationException) {
            $this->assertSame(1, $recipient->notifications()->count());
            $this->assertSame(1, $document->routes()->count());
        }
    }

    public function test_bell_shows_the_authenticated_users_unread_count_and_marks_only_their_notifications_read(): void
    {
        $user = $this->employee();
        $other = $this->employee();
        $document = $this->draft($user);
        $first = $this->workflowNotification($user, $document, 'document_forwarded', 'Document '.$document->tracking_number.' was sent to you.');
        $second = $this->workflowNotification($user, $document, 'document_completed', 'Document '.$document->tracking_number.' was completed.');
        $otherNotification = $this->workflowNotification($other, $document, 'document_forwarded', 'Private notification.');

        $component = Livewire::actingAs($user)->test('notification-bell')
            ->call('toggle')
            ->assertSee('Notifications, 2 unread')
            ->assertSee('Document '.$document->tracking_number.' was sent to you.')
            ->assertSee('Mark all as read')
            ->call('markAsRead', $first->id)
            ->assertSee('Notifications, 1 unread');

        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($second->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);

        $component->call('markAllAsRead')->assertSee('Notifications, 0 unread');
        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($otherNotification->fresh()->read_at);
    }

    public function test_opening_an_accessible_notification_marks_it_read_and_navigates_to_its_document(): void
    {
        $user = $this->employee();
        $document = $this->draft($user);
        $notification = $this->workflowNotification($user, $document, 'document_forwarded', 'Document '.$document->tracking_number.' was sent to you.');

        Livewire::actingAs($user)->test('notification-bell')
            ->call('openNotification', $notification->id)
            ->assertRedirect(route('documents.show', $document));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_bell_redacts_inaccessible_document_metadata_and_rejects_another_users_notification(): void
    {
        $owner = $this->employee();
        $outsider = $this->employee();
        $document = $this->draft($owner);
        $privateTrackingNumber = $document->tracking_number;
        $notification = $this->workflowNotification($outsider, $document, 'document_forwarded', 'Private message for '.$privateTrackingNumber.'.');

        $component = Livewire::actingAs($outsider)->test('notification-bell')
            ->call('toggle')
            ->assertSee('A document notification is no longer accessible.')
            ->assertDontSee($privateTrackingNumber)
            ->assertDontSee('Private message for');

        $component->call('openNotification', $notification->id)
            ->assertSet('statusMessage', 'This document is no longer available to you.');
        $this->assertNull($notification->fresh()->read_at);

        $ownerNotification = $this->workflowNotification($owner, $document, 'document_forwarded', 'Owner only.');
        $component->call('openNotification', $ownerNotification->id)->assertStatus(404);
        $this->assertNull($ownerNotification->fresh()->read_at);
    }

    private function employee(): User
    {
        $division = Division::query()->create([
            'sso_division_id' => fake()->uuid(),
            'name' => fake()->unique()->company(),
            'code' => fake()->unique()->lexify('DIV-???'),
            'is_active' => true,
        ]);

        return User::factory()->create(['division_id' => $division->id]);
    }

    private function draft(User $creator, array $attributes = []): Document
    {
        $type = DocumentType::query()->create([
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->lexify('TYPE-???'),
            'is_active' => true,
        ]);

        return Document::query()->create(array_merge([
            'tracking_number' => 'FDCP-'.fake()->unique()->numerify('########'),
            'title' => 'Notification document',
            'document_type_id' => $type->id,
            'origin_division_id' => $creator->division_id,
            'created_by' => $creator->id,
            'current_handler_id' => $creator->id,
            'status' => 'draft',
        ], $attributes));
    }

    private function version(Document $document, User $uploader): DocumentVersion
    {
        $version = $document->versions()->create([
            'version_number' => ((int) $document->versions()->max('version_number')) + 1,
            'uploaded_by' => $uploader->id,
            'storage_path' => "documents/{$document->id}/versions/notification.pdf",
            'disk' => 'local',
            'original_filename' => 'notification.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
        ]);
        $document->update(['current_version_id' => $version->id]);

        return $version;
    }

    private function route(Document $document, DocumentVersion $version, User $sender, User $recipient, string $type, string $status): DocumentRoute
    {
        return $document->routes()->create([
            'document_version_id' => $version->id,
            'from_user_id' => $sender->id,
            'to_user_id' => $recipient->id,
            'from_division_id' => $sender->division_id,
            'to_division_id' => $recipient->division_id,
            'route_type' => $type,
            'status' => $status,
            'forwarded_at' => now(),
            'acknowledged_at' => $status === 'acknowledged' ? now() : null,
        ]);
    }

    private function workflowNotification(User $recipient, Document $document, string $eventType, string $message): Model
    {
        return $recipient->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'document_workflow',
            'data' => [
                'event_type' => $eventType,
                'document_id' => $document->id,
                'tracking_number' => $document->tracking_number,
                'message' => $message,
            ],
        ]);
    }
}
