<?php

namespace Tests\Feature;

use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_version_must_belong_to_the_document(): void
    {
        [$owner, $type] = $this->employeeAndType();
        $first = $this->document($owner, $type, 'First');
        $second = $this->document($owner, $type, 'Second');
        $version = $this->version($first, $owner);

        $this->expectException(QueryException::class);
        $second->update(['current_version_id' => $version->id]);
    }

    public function test_route_version_must_belong_to_the_route_document(): void
    {
        [$owner, $type] = $this->employeeAndType();
        $first = $this->document($owner, $type, 'First');
        $second = $this->document($owner, $type, 'Second');
        $version = $this->version($first, $owner);
        $recipient = $this->employee()[0];

        $this->expectException(QueryException::class);
        DocumentRoute::query()->create([
            'document_id' => $second->id,
            'document_version_id' => $version->id,
            'from_user_id' => $owner->id,
            'to_user_id' => $recipient->id,
            'from_division_id' => $owner->division_id,
            'to_division_id' => $recipient->division_id,
            'route_type' => 'forward',
            'status' => 'pending',
            'forwarded_at' => now(),
        ]);
    }

    private function employeeAndType(): array
    {
        $employee = $this->employee()[0];
        $type = DocumentType::query()->create(['name' => 'Memorandum', 'code' => fake()->unique()->lexify('MEM-???'), 'is_active' => true]);

        return [$employee, $type];
    }

    private function employee(): array
    {
        $division = Division::query()->create([
            'sso_division_id' => fake()->uuid(),
            'name' => fake()->company(),
            'code' => fake()->unique()->lexify('DIV-???'),
            'is_active' => true,
        ]);

        return [User::factory()->create(['division_id' => $division->id]), $division];
    }

    private function document(User $owner, DocumentType $type, string $title): Document
    {
        return Document::query()->create([
            'tracking_number' => 'FDCP-'.strtoupper(fake()->unique()->bothify('########')),
            'title' => $title,
            'document_type_id' => $type->id,
            'origin_division_id' => $owner->division_id,
            'created_by' => $owner->id,
            'current_handler_id' => $owner->id,
            'status' => 'in_progress',
        ]);
    }

    private function version(Document $document, User $uploader): DocumentVersion
    {
        $version = DocumentVersion::query()->create([
            'document_id' => $document->id,
            'version_number' => 1,
            'uploaded_by' => $uploader->id,
            'storage_path' => 'documents/integrity.pdf',
            'disk' => 'local',
            'original_filename' => 'integrity.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1,
        ]);
        $document->update(['current_version_id' => $version->id]);

        return $version;
    }
}
