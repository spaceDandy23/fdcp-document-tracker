<?php

namespace Tests\Feature\Actions\Documents;

use App\Actions\Documents\DownloadDocumentVersion;
use App\Actions\Documents\UploadDocumentVersion;
use App\Models\Division;
use App\Models\Document;
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

class DocumentVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_upload_private_pdf_and_docx_versions_without_overwriting_history(): void
    {
        Storage::fake('local');
        $creator = $this->createEmployee();
        $document = $this->createDraft($creator);
        $this->actingAs($creator);

        $first = app(UploadDocumentVersion::class)->handle(
            $document,
            UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'),
        );
        $second = app(UploadDocumentVersion::class)->handle(
            $document->fresh(),
            UploadedFile::fake()->create(
                'replacement.docx',
                100,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ),
        );

        $this->assertSame(1, $first->version_number);
        $this->assertSame(2, $second->version_number);
        $this->assertNotSame($first->storage_path, $second->storage_path);
        $this->assertSame($second->id, $document->fresh()->current_version_id);
        $this->assertDatabaseCount('document_versions', 2);
        Storage::disk('local')->assertExists([$first->storage_path, $second->storage_path]);
        Storage::disk('public')->assertMissing([$first->storage_path, $second->storage_path]);
    }

    public function test_upload_rejects_invalid_types_and_files_over_the_configured_limit(): void
    {
        Storage::fake('local');
        config(['documents.max_upload_kb' => 10]);
        $creator = $this->createEmployee();
        $document = $this->createDraft($creator);
        $this->actingAs($creator);

        foreach ([
            UploadedFile::fake()->create('notes.txt', 5, 'text/plain'),
            UploadedFile::fake()->create('large.pdf', 11, 'application/pdf'),
        ] as $file) {
            try {
                app(UploadDocumentVersion::class)->handle($document, $file);
                $this->fail('Expected invalid upload validation to fail.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }

        $this->assertDatabaseCount('document_versions', 0);
        Storage::disk('local')->assertEmpty();
    }

    public function test_database_failure_removes_the_orphaned_file(): void
    {
        Storage::fake('local');
        $creator = $this->createEmployee();
        $document = $this->createDraft($creator);
        $this->actingAs($creator);
        DocumentVersion::creating(function (): void {
            throw new RuntimeException('Version insert failed.');
        });

        try {
            app(UploadDocumentVersion::class)->handle(
                $document,
                UploadedFile::fake()->create('draft.pdf', 100, 'application/pdf'),
            );
            $this->fail('Expected version persistence to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Version insert failed.', $exception->getMessage());
        }

        Storage::disk('local')->assertEmpty();
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertNull($document->fresh()->current_version_id);
    }

    public function test_download_requires_document_visibility_and_uses_the_private_disk(): void
    {
        Storage::fake('local');
        $creator = $this->createEmployee();
        $outsider = $this->createEmployee();
        $document = $this->createDraft($creator);
        $this->actingAs($creator);
        $version = app(UploadDocumentVersion::class)->handle(
            $document,
            UploadedFile::fake()->create('secure.pdf', 100, 'application/pdf'),
        );

        $response = app(DownloadDocumentVersion::class)->handle($version);
        $this->assertSame('attachment; filename=secure.pdf', $response->headers->get('content-disposition'));

        $this->actingAs($outsider);
        $this->expectException(AuthorizationException::class);
        app(DownloadDocumentVersion::class)->handle($version);
    }

    public function test_pdf_version_preview_uses_private_inline_response_and_rejects_invalid_access(): void
    {
        Storage::fake('local');
        $creator = $this->createEmployee();
        $outsider = $this->createEmployee();
        $document = $this->createDraft($creator);
        $otherDocument = $this->createDraft($creator);
        $this->actingAs($creator);
        $version = app(UploadDocumentVersion::class)->handle($document, UploadedFile::fake()->create('preview.pdf', 100, 'application/pdf'));

        $response = $this->get(route('documents.versions.preview', [$document, $version]));
        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'inline; filename=preview.pdf')
            ->assertHeader('content-security-policy', "sandbox; default-src 'none'")
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('x-frame-options', 'SAMEORIGIN');
        $this->get(route('documents.versions.preview', [$otherDocument, $version]))->assertNotFound();
        $this->actingAs($outsider)->get(route('documents.versions.preview', [$document, $version]))->assertForbidden();

        $this->actingAs($creator);
        Storage::disk('local')->delete($version->storage_path);
        $this->get(route('documents.versions.preview', [$document, $version]))->assertNotFound();
    }

    public function test_only_pdf_versions_can_be_previewed(): void
    {
        Storage::fake('local');
        $creator = $this->createEmployee();
        $document = $this->createDraft($creator);
        $this->actingAs($creator);
        $version = app(UploadDocumentVersion::class)->handle($document, UploadedFile::fake()->create('preview.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));

        $this->get(route('documents.versions.preview', [$document, $version]))->assertStatus(415);
    }

    private function createEmployee(): User
    {
        $division = Division::create([
            'sso_division_id' => fake()->uuid(),
            'name' => fake()->unique()->company(),
        ]);

        return User::factory()->create(['division_id' => $division->id]);
    }

    private function createDraft(User $creator): Document
    {
        $type = DocumentType::create([
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->lexify('TYPE-???'),
        ]);

        return Document::create([
            'title' => 'Draft',
            'document_type_id' => $type->id,
            'origin_division_id' => $creator->division_id,
            'created_by' => $creator->id,
            'current_handler_id' => $creator->id,
            'status' => 'draft',
        ]);
    }
}
