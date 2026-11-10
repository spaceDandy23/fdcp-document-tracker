<?php

namespace Tests\Feature;

use App\Actions\Documents\AcknowledgeDocumentRoute;
use App\Actions\Documents\ForwardDocument;
use App\Actions\Documents\ListDocuments;
use App\Actions\Documents\SubmitDocument;
use App\Actions\Documents\UploadDocumentVersion;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentRoutingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        config(['documents.disk' => 'local']);
    }

    public function test_employees_can_create_route_revise_and_complete_a_document_through_livewire(): void
    {
        [$itDivision, $hrDivision] = $this->divisions();
        $itStaff = $this->employee('Test IT Staff', $itDivision);
        $hrStaff = $this->employee('Test HR Staff', $hrDivision);
        $hrHead = $this->employee('Test HR Head', $hrDivision, isDivisionHead: true);
        $outsider = $this->employee('Test Outsider', $this->division('TEST-OTHER', 'Test Other Division'));
        $documentType = $this->documentType();

        Livewire::actingAs($itStaff)->test('pages::documents.create')
            ->set('title', 'Test routing workflow')
            ->set('documentTypeId', (string) $documentType->id)
            ->set('mainDocument', UploadedFile::fake()->create('routing-v1.pdf', 100, 'application/pdf'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $document = Document::query()->where('title', 'Test routing workflow')->sole();
        $versionOne = $document->currentVersion;
        $this->assertSame(1, $versionOne->version_number);
        $this->assertSame($itStaff->id, $document->created_by);
        $this->assertSame($itDivision->id, $document->origin_division_id);
        $this->assertSame($itStaff->id, $document->current_handler_id);
        $this->assertSame('draft', $document->status);
        Storage::disk('local')->assertExists($versionOne->storage_path);
        Storage::disk('public')->assertMissing($versionOne->storage_path);

        Livewire::actingAs($itStaff)->test('pages::documents.show', ['document' => $document])
            ->set('recipientId', (string) $hrStaff->id)
            ->call('submit')
            ->assertHasNoErrors();
        $trackingNumber = $document->fresh()->tracking_number;
        $initialRoute = $document->routes()->sole();
        $this->assertSame($versionOne->id, $initialRoute->document_version_id);
        $this->assertSame($hrStaff->id, $document->fresh()->current_handler_id);
        $this->assertSame('pending_acknowledgment', $document->fresh()->status);

        Livewire::actingAs($hrStaff)->test('pages::documents.show', ['document' => $document->fresh()])
            ->call('acknowledge')->assertHasNoErrors()
            ->set('recipientId', (string) $hrHead->id)
            ->set('remarks', 'Please review')
            ->call('forward')->assertHasNoErrors();
        $forwardRoute = $document->routes()->latest('id')->firstOrFail();
        $this->assertSame($versionOne->id, $forwardRoute->document_version_id);
        $this->assertSame($hrHead->id, $document->fresh()->current_handler_id);
        $this->assertSame('pending_acknowledgment', $document->fresh()->status);

        Livewire::actingAs($hrHead)->test('pages::documents.show', ['document' => $document->fresh()])
            ->call('acknowledge')->assertHasNoErrors()
            ->set('revisionRecipientId', (string) $itStaff->id)
            ->set('revisionReason', 'Please correct the test figures')
            ->call('returnForRevision')->assertHasNoErrors();
        $revisionReturn = $document->routes()->latest('id')->firstOrFail();
        $this->assertSame('revision_return', $revisionReturn->route_type);
        $this->assertSame($versionOne->id, $revisionReturn->document_version_id);
        $this->assertSame($itStaff->id, $document->fresh()->current_handler_id);
        $this->assertSame('revision_requested', $document->fresh()->status);

        $revisionPage = Livewire::actingAs($itStaff)->test('pages::documents.show', ['document' => $document->fresh()]);
        $revisionPage->set('file', UploadedFile::fake()->create('routing-v2.pdf', 120, 'application/pdf'))
            ->call('uploadVersion')->assertHasErrors(['change_summary']);
        $revisionPage->set('file', UploadedFile::fake()->create('routing-v2.pdf', 120, 'application/pdf'))
            ->set('changeSummary', 'Corrected test figures')
            ->call('uploadVersion')->assertHasNoErrors();
        $versionTwo = $document->fresh()->currentVersion;
        $this->assertSame(2, $versionTwo->version_number);
        $this->assertSame('Corrected test figures', $versionTwo->change_summary);
        Storage::disk('local')->assertExists([$versionOne->storage_path, $versionTwo->storage_path]);

        Livewire::actingAs($itStaff)->test('pages::documents.show', ['document' => $document->fresh()])
            ->set('recipientId', (string) $hrStaff->id)
            ->call('submit')->assertHasNoErrors();
        $resubmission = $document->routes()->latest('id')->firstOrFail();
        $this->assertSame('revision_resubmission', $resubmission->route_type);
        $this->assertSame($versionTwo->id, $resubmission->document_version_id);
        $this->assertSame($hrStaff->id, $document->fresh()->current_handler_id);

        Livewire::actingAs($hrStaff)->test('pages::documents.show', ['document' => $document->fresh()])
            ->call('acknowledge')->assertHasNoErrors()
            ->call('complete')->assertHasNoErrors();

        $completed = $document->fresh();
        $this->assertSame('completed', $completed->status);
        $this->assertSame($trackingNumber, $completed->tracking_number);
        $this->assertNull($completed->current_handler_id);
        $this->assertSame(2, $completed->versions()->count());
        $this->assertSame(4, $completed->routes()->count());
        $this->assertSame(['initial', 'forward', 'revision_return', 'revision_resubmission'], $completed->routes()->orderBy('id')->pluck('route_type')->all());
        $this->assertSame([$versionOne->id, $versionOne->id, $versionOne->id, $versionTwo->id], $completed->routes()->orderBy('id')->pluck('document_version_id')->all());
        $this->assertSame([
            'created', 'version_uploaded', 'submitted', 'forwarded', 'acknowledged',
            'forwarded', 'acknowledged', 'revision_returned', 'version_uploaded',
            'revision_resubmitted', 'forwarded', 'acknowledged', 'completed',
        ], $completed->actions()->orderBy('id')->pluck('action')->all());
        $this->assertSame(0, $completed->routes()->where('status', 'pending')->count());
        $this->assertSame('acknowledged', $completed->routes()->latest('id')->firstOrFail()->status);

        $this->actingAs($hrStaff)->get(route('documents.versions.download', [$completed, $versionOne]))->assertDownload('routing-v1.pdf');
        $this->actingAs($outsider)->get(route('documents.versions.download', [$completed, $versionTwo]))->assertForbidden();
        $otherDocument = $this->draftAs($outsider, $documentType);
        $this->actingAs($itStaff)->get(route('documents.versions.download', [$otherDocument, $versionOne]))->assertNotFound();
        Storage::disk('public')->assertMissing([$versionOne->storage_path, $versionTwo->storage_path]);
    }

    public function test_declining_a_forwarded_document_returns_responsibility_to_the_sender(): void
    {
        $sender = $this->employee('Test Sender', $this->division('TEST-SEND', 'Test Sender Division'));
        $handler = $this->employee('Test Handler', $this->division('TEST-HANDLE', 'Test Handler Division'));
        $recipient = $this->employee('Test Recipient', $this->division('TEST-RECIP', 'Test Recipient Division'));
        $document = $this->draftAs($sender, $this->documentType());
        $this->actingAs($sender);
        app(UploadDocumentVersion::class)->handle(
            $document,
            UploadedFile::fake()->create('decline.pdf', 20, 'application/pdf'),
        );
        $initialRoute = app(SubmitDocument::class)->handle($document->fresh(), ['recipient_id' => $handler->id]);
        $this->actingAs($handler);
        app(AcknowledgeDocumentRoute::class)->handle($initialRoute);
        $forwardRoute = app(ForwardDocument::class)->handle($document->fresh(), ['recipient_id' => $recipient->id]);

        Livewire::actingAs($recipient)->test('pages::documents.show', ['document' => $document->fresh()])
            ->set('declineReason', 'Please send this to another office')
            ->call('decline')->assertHasNoErrors();

        $this->assertSame('declined', $forwardRoute->fresh()->status);
        $this->assertSame($handler->id, $document->fresh()->current_handler_id);
        $this->assertSame('in_progress', $document->fresh()->status);
        $this->assertDatabaseHas('document_actions', [
            'document_id' => $document->id,
            'document_route_id' => $forwardRoute->id,
            'performed_by' => $recipient->id,
            'action' => 'declined',
            'remarks' => 'Please send this to another office',
        ]);
    }

    public function test_unauthorized_or_stale_users_cannot_acknowledge_or_forward_a_document(): void
    {
        $sender = $this->employee('Test Sender', $this->division('TEST-SEND', 'Test Sender Division'));
        $recipient = $this->employee('Test Recipient', $this->division('TEST-RECIP', 'Test Recipient Division'));
        $outsider = $this->employee('Test Outsider', $this->division('TEST-OUT', 'Test Outsider Division'));
        $document = $this->draftAs($sender, $this->documentType());
        $this->actingAs($sender);
        app(UploadDocumentVersion::class)->handle(
            $document,
            UploadedFile::fake()->create('secure.pdf', 20, 'application/pdf'),
        );
        $route = app(SubmitDocument::class)->handle($document->fresh(), ['recipient_id' => $recipient->id]);

        $this->actingAs($outsider);
        try {
            app(AcknowledgeDocumentRoute::class)->handle($route);
            $this->fail('An unrelated employee must not acknowledge the route.');
        } catch (AuthorizationException) {
            $this->assertSame('pending', $route->fresh()->status);
        }
        try {
            app(ForwardDocument::class)->handle($document->fresh(), ['recipient_id' => $outsider->id]);
            $this->fail('An unrelated employee must not forward the document.');
        } catch (AuthorizationException) {
            $this->assertSame($recipient->id, $document->fresh()->current_handler_id);
        }

        $this->actingAs($recipient);
        app(AcknowledgeDocumentRoute::class)->handle($route);
        try {
            app(AcknowledgeDocumentRoute::class)->handle($route->fresh());
            $this->fail('A stale acknowledgment must be rejected.');
        } catch (AuthorizationException) {
            $this->assertSame('acknowledged', $route->fresh()->status);
        }

        $this->actingAs($sender);
        try {
            app(ForwardDocument::class)->handle($document->fresh(), ['recipient_id' => $outsider->id]);
            $this->fail('The previous sender must not forward after acknowledgment changed the handler.');
        } catch (AuthorizationException) {
            $this->assertSame($recipient->id, $document->fresh()->current_handler_id);
        }
        $this->assertSame(0, $document->routes()->where('status', 'pending')->count());
    }

    public function test_division_head_can_see_documents_from_their_division_only(): void
    {
        $hrDivision = $this->division('TEST-HR', 'Test HR');
        $otherDivision = $this->division('TEST-OTHER', 'Test Other');
        $hrEmployee = $this->employee('Test HR Employee', $hrDivision);
        $hrHead = $this->employee('Test HR Head', $hrDivision, isDivisionHead: true);
        $otherEmployee = $this->employee('Test Other Employee', $otherDivision);
        $type = $this->documentType();
        $hrDocument = $this->draftAs($hrEmployee, $type);
        $otherDocument = $this->draftAs($otherEmployee, $type);

        $this->actingAs($hrHead);
        $visibleDocuments = app(ListDocuments::class)->handle();

        $this->assertTrue($visibleDocuments->contains('id', $hrDocument->id));
        $this->assertFalse($visibleDocuments->contains('id', $otherDocument->id));
    }

    private function divisions(): array
    {
        return [
            $this->division('TEST-IT', 'IT Section'),
            $this->division('TEST-HR', 'HR'),
        ];
    }

    private function division(string $code, string $name): Division
    {
        return Division::query()->create([
            'sso_division_id' => 'test-workflow:'.$code,
            'name' => $name,
            'code' => $code,
            'is_active' => true,
        ]);
    }

    private function employee(string $name, Division $division, bool $isDivisionHead = false): User
    {
        return User::factory()->create([
            'name' => $name,
            'division_id' => $division->id,
            'is_admin' => false,
            'is_division_head' => $isDivisionHead,
            'is_active' => true,
        ]);
    }

    private function documentType(): DocumentType
    {
        return DocumentType::query()->create([
            'name' => 'Test Memo '.fake()->unique()->word(),
            'code' => 'T'.fake()->unique()->numerify('####'),
            'is_active' => true,
        ]);
    }

    private function draftAs(User $creator, DocumentType $documentType): Document
    {
        $title = 'Routing test '.fake()->unique()->word();

        Livewire::actingAs($creator)->test('pages::documents.create')
            ->set('title', $title)
            ->set('documentTypeId', (string) $documentType->id)
            ->call('save')
            ->assertHasNoErrors();

        return Document::query()->where('title', $title)->sole();
    }
}
