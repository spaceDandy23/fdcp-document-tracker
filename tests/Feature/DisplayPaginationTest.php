<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentAction;
use App\Models\DocumentAttachment;
use App\Models\DocumentRoute;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DisplayPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_paginates_assigned_documents(): void
    {
        $user = $this->employee();
        $type = $this->type();
        foreach (range(1, 16) as $number) {
            $this->document($user, $type, sprintf('Inbox %02d', $number), 'in_progress');
        }

        Livewire::actingAs($user)->test('pages::inbox')
            ->assertSee('Inbox 16')->assertDontSee('Inbox 01')->assertSee('Next')
            ->call('setPage', 2, 'inboxPage')->assertSee('Inbox 01');
    }

    public function test_outgoing_routes_are_paginated(): void
    {
        $sender = $this->employee();
        $recipient = $this->employee();
        $document = $this->document($sender, $this->type(), 'Routed document', 'in_progress');
        $version = $this->version($document, $sender, 1);
        foreach (range(1, 21) as $number) {
            DocumentRoute::query()->create([
                'document_id' => $document->id, 'document_version_id' => $version->id,
                'from_user_id' => $sender->id, 'to_user_id' => $recipient->id,
                'from_division_id' => $sender->division_id, 'to_division_id' => $recipient->division_id,
                'route_type' => 'forward', 'status' => 'acknowledged', 'remarks' => sprintf('Route %02d', $number),
                'forwarded_at' => now()->addMinutes($number), 'acknowledged_at' => now()->addMinutes($number),
            ]);
        }

        Livewire::actingAs($sender)->test('pages::outgoing')
            ->assertSee('Next')
            ->call('setPage', 2, 'outgoingPage')
            ->assertSee('Showing')->assertSee('21');
    }

    public function test_document_types_are_paginated_for_administrators(): void
    {
        $admin = $this->employee(['is_admin' => true]);
        foreach (range(1, 21) as $number) {
            DocumentType::query()->create(['name' => sprintf('Type %02d', $number), 'code' => sprintf('T%02d', $number), 'is_active' => true]);
        }

        Livewire::actingAs($admin)->test('pages::admin.document-types')
            ->assertSee('Type 01')->assertDontSee('Type 21')->assertSee('Next')
            ->call('setPage', 2, 'typesPage')->assertSee('Type 21');
    }

    public function test_document_detail_lists_use_independent_paginators(): void
    {
        $owner = $this->employee();
        $document = $this->document($owner, $this->type(), 'Large history', 'in_progress');
        foreach (range(1, 11) as $number) {
            $this->version($document, $owner, $number);
            $this->attachment($document, $owner, sprintf('active-%02d.pdf', $number));
            $removed = $this->attachment($document, $owner, sprintf('removed-%02d.pdf', $number));
            $removed->delete();
        }
        foreach (range(1, 21) as $number) {
            DocumentAction::query()->create(['document_id' => $document->id, 'performed_by' => $owner->id, 'action' => sprintf('audit_%02d', $number)]);
        }

        $component = Livewire::actingAs($owner)->test('pages::documents.show', ['document' => $document])
            ->assertSee('Version 11 ·')->assertDontSee('Version 1 ·')
            ->assertSee('active-11.pdf')->assertDontSee('active-01.pdf')
            ->assertSee('removed-11.pdf')->assertDontSee('removed-01.pdf')
            ->assertSee('Audit 21')->assertDontSee('Audit 01');

        $component->call('setPage', 2, 'versionsPage')->assertSee('Version 1 ·');
        $component->call('setPage', 2, 'attachmentsPage')->assertSee('active-01.pdf');
        $component->call('setPage', 2, 'removedAttachmentsPage')->assertSee('removed-01.pdf');
        $component->call('setPage', 2, 'auditPage')->assertSee('Audit 01');
    }

    private function employee(array $attributes = []): User
    {
        $division = Division::query()->create(['sso_division_id' => fake()->uuid(), 'name' => fake()->company(), 'code' => fake()->unique()->lexify('???'), 'is_active' => true]);

        return User::factory()->create(array_merge(['division_id' => $division->id], $attributes));
    }

    private function type(): DocumentType
    {
        return DocumentType::query()->create(['name' => fake()->unique()->word(), 'code' => fake()->unique()->lexify('???'), 'is_active' => true]);
    }

    private function document(User $owner, DocumentType $type, string $title, string $status): Document
    {
        return Document::query()->create(['tracking_number' => fake()->unique()->numerify('FDCP-########'), 'title' => $title, 'document_type_id' => $type->id, 'origin_division_id' => $owner->division_id, 'created_by' => $owner->id, 'current_handler_id' => $owner->id, 'status' => $status, 'submitted_at' => now()]);
    }

    private function version(Document $document, User $owner, int $number): DocumentVersion
    {
        return DocumentVersion::query()->create(['document_id' => $document->id, 'version_number' => $number, 'uploaded_by' => $owner->id, 'storage_path' => "documents/{$document->id}/version-{$number}.pdf", 'disk' => 'local', 'original_filename' => "version-{$number}.pdf", 'mime_type' => 'application/pdf', 'file_size' => 1]);
    }

    private function attachment(Document $document, User $owner, string $filename): DocumentAttachment
    {
        return DocumentAttachment::query()->create(['document_id' => $document->id, 'uploaded_by' => $owner->id, 'storage_path' => "documents/{$document->id}/{$filename}", 'disk' => 'local', 'original_filename' => $filename, 'mime_type' => 'application/pdf', 'file_size' => 1]);
    }
}
