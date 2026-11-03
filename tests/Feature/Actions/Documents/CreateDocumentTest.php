<?php

namespace Tests\Feature\Actions\Documents;

use App\Actions\Documents\CreateDocument;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentAction;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class CreateDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_employee_can_create_a_document_draft(): void
    {
        $employee = $this->createEmployee();
        $documentType = $this->createDocumentType();
        $this->actingAs($employee);

        $document = app(CreateDocument::class)->handle([
            'title' => 'Board Resolution',
            'document_type_id' => $documentType->id,
            'description' => 'Draft for internal review.',
        ]);

        $this->assertInstanceOf(Document::class, $document);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'title' => 'Board Resolution',
            'description' => 'Draft for internal review.',
            'document_type_id' => $documentType->id,
            'status' => 'draft',
        ]);
        $this->assertNull($document->tracking_number);
        $this->assertNull($document->current_version_id);
    }

    public function test_inactive_document_type_is_rejected(): void
    {
        $employee = $this->createEmployee();
        $inactiveDocumentType = $this->createDocumentType(isActive: false);
        $this->actingAs($employee);

        try {
            app(CreateDocument::class)->handle([
                'title' => 'Invalid Draft',
                'document_type_id' => $inactiveDocumentType->id,
            ]);

            $this->fail('Expected validation to reject an inactive document type.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('document_type_id', $exception->errors());
        }

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_actions', 0);
    }

    public function test_title_and_document_type_are_required(): void
    {
        $employee = $this->createEmployee();
        $this->actingAs($employee);

        try {
            app(CreateDocument::class)->handle(['description' => 'Incomplete draft.']);

            $this->fail('Expected validation to reject missing required fields.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('title', $exception->errors());
            $this->assertArrayHasKey('document_type_id', $exception->errors());
        }

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_ownership_and_draft_state_come_from_the_authenticated_employee(): void
    {
        $employee = $this->createEmployee();
        $otherEmployee = $this->createEmployee();
        $documentType = $this->createDocumentType();
        $this->actingAs($employee);

        $document = app(CreateDocument::class)->handle([
            'title' => 'Owned Draft',
            'document_type_id' => $documentType->id,
            'created_by' => $otherEmployee->id,
            'origin_division_id' => $otherEmployee->division_id,
            'current_handler_id' => $otherEmployee->id,
            'status' => 'completed',
            'tracking_number' => 'UNTRUSTED-001',
            'current_version_id' => 999,
        ]);

        $this->assertSame($employee->id, $document->created_by);
        $this->assertSame($employee->division_id, $document->origin_division_id);
        $this->assertSame($employee->id, $document->current_handler_id);
        $this->assertSame('draft', $document->status);
        $this->assertNull($document->tracking_number);
        $this->assertNull($document->current_version_id);
    }

    public function test_document_creation_is_audited(): void
    {
        $employee = $this->createEmployee();
        $documentType = $this->createDocumentType();
        $this->actingAs($employee);

        $document = app(CreateDocument::class)->handle([
            'title' => 'Audited Draft',
            'document_type_id' => $documentType->id,
        ]);

        $action = DocumentAction::query()->sole();

        $this->assertSame($document->id, $action->document_id);
        $this->assertSame($employee->id, $action->performed_by);
        $this->assertSame('created', $action->action);
        $this->assertNull($action->document_route_id);
    }

    public function test_document_creation_rolls_back_when_audit_logging_fails(): void
    {
        $employee = $this->createEmployee();
        $documentType = $this->createDocumentType();
        $this->actingAs($employee);
        DocumentAction::creating(function (): void {
            throw new RuntimeException('Audit log failed.');
        });

        try {
            app(CreateDocument::class)->handle([
                'title' => 'Atomic Draft',
                'document_type_id' => $documentType->id,
            ]);

            $this->fail('Expected the failed audit log to abort document creation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit log failed.', $exception->getMessage());
        }

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_actions', 0);
    }

    public function test_inactive_employee_cannot_create_a_document(): void
    {
        $employee = $this->createEmployee();
        $employee->update(['is_active' => false]);
        $documentType = $this->createDocumentType();
        $this->actingAs($employee);

        $this->expectException(AuthorizationException::class);
        app(CreateDocument::class)->handle([
            'title' => 'Unauthorized Draft',
            'document_type_id' => $documentType->id,
        ]);
    }

    private function createEmployee(): User
    {
        $division = Division::create([
            'sso_division_id' => fake()->uuid(),
            'name' => fake()->unique()->company(),
            'code' => fake()->unique()->lexify('DIV-???'),
        ]);

        return User::factory()->create(['division_id' => $division->id]);
    }

    private function createDocumentType(bool $isActive = true): DocumentType
    {
        return DocumentType::create([
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->lexify('TYPE-???'),
            'is_active' => $isActive,
        ]);
    }
}
