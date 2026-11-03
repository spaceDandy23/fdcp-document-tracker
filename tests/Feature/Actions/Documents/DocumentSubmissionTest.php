<?php

namespace Tests\Feature\Actions\Documents;

use App\Actions\Documents\SubmitDocument;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_submission_requires_a_main_document_version(): void
    {
        $creator = $this->createEmployee();
        $recipient = $this->createEmployee();
        $document = $this->createDraft($creator);
        $this->actingAs($creator);

        try {
            app(SubmitDocument::class)->handle($document, ['recipient_id' => $recipient->id]);
            $this->fail('Expected submission without a file to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document', $exception->errors());
        }

        $this->assertSame('draft', $document->fresh()->status);
        $this->assertDatabaseCount('document_routes', 0);
    }

    public function test_submission_requires_an_active_document_type(): void
    {
        $creator = $this->createEmployee();
        $recipient = $this->createEmployee();
        $document = $this->createDraft($creator, false);
        $this->addVersion($document, $creator);
        $this->actingAs($creator);

        try {
            app(SubmitDocument::class)->handle($document->fresh(), ['recipient_id' => $recipient->id]);
            $this->fail('Expected inactive document type validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document_type_id', $exception->errors());
        }

        $this->assertNull($document->fresh()->tracking_number);
    }

    public function test_submission_requires_exactly_one_other_active_employee_with_a_division(): void
    {
        $creator = $this->createEmployee();
        $inactiveRecipient = $this->createEmployee();
        $inactiveRecipient->update(['is_active' => false]);
        $document = $this->createDraft($creator);
        $this->addVersion($document, $creator);
        $this->actingAs($creator);

        foreach ([[], ['recipient_id' => $creator->id], ['recipient_id' => $inactiveRecipient->id]] as $input) {
            try {
                app(SubmitDocument::class)->handle($document->fresh(), $input);
                $this->fail('Expected recipient validation to fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('recipient_id', $exception->errors());
            }
        }

        $this->assertDatabaseCount('document_routes', 0);
    }

    public function test_successful_initial_submission_creates_route_state_and_audit_history(): void
    {
        $creator = $this->createEmployee();
        $recipient = $this->createEmployee();
        $document = $this->createDraft($creator);
        $version = $this->addVersion($document, $creator);
        $currentDivision = Division::create([
            'sso_division_id' => fake()->uuid(),
            'name' => fake()->unique()->company(),
        ]);
        $creator->update(['division_id' => $currentDivision->id]);
        $this->actingAs($creator);

        $route = app(SubmitDocument::class)->handle($document->fresh(), [
            'recipient_id' => $recipient->id,
        ]);

        $submitted = $document->fresh();
        $this->assertNotNull($submitted->tracking_number);
        $this->assertSame('pending_acknowledgment', $submitted->status);
        $this->assertSame($recipient->id, $submitted->current_handler_id);
        $this->assertNotNull($submitted->submitted_at);
        $this->assertSame($version->id, $route->document_version_id);
        $this->assertSame($creator->id, $route->from_user_id);
        $this->assertSame($recipient->id, $route->to_user_id);
        $this->assertSame($currentDivision->id, $route->from_division_id);
        $this->assertSame($recipient->division_id, $route->to_division_id);
        $this->assertSame('initial', $route->route_type);
        $this->assertSame('pending', $route->status);
        $this->assertDatabaseHas('document_actions', ['document_id' => $document->id, 'action' => 'submitted']);
        $this->assertDatabaseHas('document_actions', [
            'document_id' => $document->id,
            'document_route_id' => $route->id,
            'action' => 'forwarded',
        ]);
    }

    public function test_tracking_numbers_are_unique_and_duplicate_submission_is_rejected(): void
    {
        $creator = $this->createEmployee();
        $recipient = $this->createEmployee();
        $first = $this->createDraft($creator);
        $second = $this->createDraft($creator);
        $this->addVersion($first, $creator);
        $this->addVersion($second, $creator);
        $this->actingAs($creator);

        app(SubmitDocument::class)->handle($first->fresh(), ['recipient_id' => $recipient->id]);
        app(SubmitDocument::class)->handle($second->fresh(), ['recipient_id' => $recipient->id]);

        $this->assertNotSame($first->fresh()->tracking_number, $second->fresh()->tracking_number);
        $this->assertDatabaseCount('document_routes', 2);

        $this->expectException(AuthorizationException::class);
        app(SubmitDocument::class)->handle($first->fresh(), ['recipient_id' => $recipient->id]);
    }

    private function createEmployee(): User
    {
        $division = Division::create([
            'sso_division_id' => fake()->uuid(),
            'name' => fake()->unique()->company(),
        ]);

        return User::factory()->create(['division_id' => $division->id]);
    }

    private function createDraft(User $creator, bool $activeType = true): Document
    {
        $type = DocumentType::create([
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->lexify('TYPE-???'),
            'is_active' => $activeType,
        ]);

        return Document::create([
            'title' => 'Submission draft',
            'document_type_id' => $type->id,
            'origin_division_id' => $creator->division_id,
            'created_by' => $creator->id,
            'current_handler_id' => $creator->id,
            'status' => 'draft',
        ]);
    }

    private function addVersion(Document $document, User $uploader): DocumentVersion
    {
        $version = DocumentVersion::create([
            'document_id' => $document->id,
            'version_number' => 1,
            'uploaded_by' => $uploader->id,
            'storage_path' => "documents/{$document->id}/versions/test.pdf",
            'disk' => 'local',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
        ]);

        $document->update(['current_version_id' => $version->id]);

        return $version;
    }
}
