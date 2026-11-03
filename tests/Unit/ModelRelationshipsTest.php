<?php

namespace Tests\Unit;

use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentAction;
use App\Models\DocumentAttachment;
use App\Models\DocumentRoute;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

class ModelRelationshipsTest extends TestCase
{
    public function test_division_relationships_use_the_schema_foreign_keys(): void
    {
        $division = new Division;

        $this->assertHasMany($division->users(), User::class, 'division_id');
        $this->assertHasMany($division->originatingDocuments(), Document::class, 'origin_division_id');
        $this->assertHasMany($division->sentRoutes(), DocumentRoute::class, 'from_division_id');
        $this->assertHasMany($division->receivedRoutes(), DocumentRoute::class, 'to_division_id');
    }

    public function test_user_relationships_cover_documents_routes_and_uploaded_files(): void
    {
        $user = new User;

        $this->assertBelongsTo($user->division(), Division::class, 'division_id');
        $this->assertHasMany($user->createdDocuments(), Document::class, 'created_by');
        $this->assertHasMany($user->assignedDocuments(), Document::class, 'current_handler_id');
        $this->assertHasMany($user->uploadedVersions(), DocumentVersion::class, 'uploaded_by');
        $this->assertHasMany($user->sentRoutes(), DocumentRoute::class, 'from_user_id');
        $this->assertHasMany($user->receivedRoutes(), DocumentRoute::class, 'to_user_id');
        $this->assertHasMany($user->performedActions(), DocumentAction::class, 'performed_by');
        $this->assertHasMany($user->uploadedAttachments(), DocumentAttachment::class, 'uploaded_by');
    }

    public function test_document_type_and_document_relationships_cover_the_document_graph(): void
    {
        $documentType = new DocumentType;
        $document = new Document;

        $this->assertHasMany($documentType->documents(), Document::class, 'document_type_id');
        $this->assertBelongsTo($document->documentType(), DocumentType::class, 'document_type_id');
        $this->assertBelongsTo($document->originDivision(), Division::class, 'origin_division_id');
        $this->assertBelongsTo($document->creator(), User::class, 'created_by');
        $this->assertBelongsTo($document->currentHandler(), User::class, 'current_handler_id');
        $this->assertBelongsTo($document->currentVersion(), DocumentVersion::class, 'current_version_id');
        $this->assertHasMany($document->versions(), DocumentVersion::class, 'document_id');
        $this->assertHasMany($document->routes(), DocumentRoute::class, 'document_id');
        $this->assertHasMany($document->actions(), DocumentAction::class, 'document_id');
        $this->assertHasMany($document->attachments(), DocumentAttachment::class, 'document_id');
    }

    public function test_version_route_action_and_attachment_relationships_use_their_explicit_keys(): void
    {
        $version = new DocumentVersion;
        $route = new DocumentRoute;
        $action = new DocumentAction;
        $attachment = new DocumentAttachment;

        $this->assertBelongsTo($version->document(), Document::class, 'document_id');
        $this->assertBelongsTo($version->uploader(), User::class, 'uploaded_by');
        $this->assertHasMany($version->routes(), DocumentRoute::class, 'document_version_id');

        $this->assertBelongsTo($route->document(), Document::class, 'document_id');
        $this->assertBelongsTo($route->documentVersion(), DocumentVersion::class, 'document_version_id');
        $this->assertBelongsTo($route->sender(), User::class, 'from_user_id');
        $this->assertBelongsTo($route->recipient(), User::class, 'to_user_id');
        $this->assertBelongsTo($route->fromDivision(), Division::class, 'from_division_id');
        $this->assertBelongsTo($route->toDivision(), Division::class, 'to_division_id');
        $this->assertHasMany($route->actions(), DocumentAction::class, 'document_route_id');

        $this->assertBelongsTo($action->document(), Document::class, 'document_id');
        $this->assertBelongsTo($action->documentRoute(), DocumentRoute::class, 'document_route_id');
        $this->assertBelongsTo($action->performer(), User::class, 'performed_by');

        $this->assertBelongsTo($attachment->document(), Document::class, 'document_id');
        $this->assertBelongsTo($attachment->uploader(), User::class, 'uploaded_by');
    }

    public function test_models_cast_schema_values_to_domain_friendly_types(): void
    {
        $this->assertFalse((new Division(['is_active' => 0]))->is_active);
        $this->assertFalse((new User(['is_active' => 0]))->is_active);
        $this->assertTrue((new DocumentType(['is_active' => 1]))->is_active);
        $this->assertSame(2, (new DocumentVersion(['version_number' => '2']))->version_number);
        $this->assertSame(1024, (new DocumentAttachment(['file_size' => '1024']))->file_size);
        $this->assertSame(['source' => 'test'], (new DocumentAction(['metadata' => ['source' => 'test']]))->metadata);
        $this->assertInstanceOf(\DateTimeInterface::class, (new Document(['submitted_at' => '2026-11-03 08:00:00']))->submitted_at);
        $this->assertInstanceOf(\DateTimeInterface::class, (new DocumentRoute(['forwarded_at' => '2026-11-03 08:00:00']))->forwarded_at);
    }

    public function test_primary_keys_and_timestamps_are_not_mass_assignable(): void
    {
        foreach ([
            new Division,
            new User,
            new DocumentType,
            new Document,
            new DocumentVersion,
            new DocumentRoute,
            new DocumentAction,
            new DocumentAttachment,
        ] as $model) {
            $this->assertNotContains('id', $model->getFillable());
            $this->assertNotContains('created_at', $model->getFillable());
            $this->assertNotContains('updated_at', $model->getFillable());
        }
    }

    /**
     * @param  class-string<Model>  $relatedModel
     */
    private function assertBelongsTo(BelongsTo $relationship, string $relatedModel, string $foreignKey): void
    {
        $this->assertInstanceOf($relatedModel, $relationship->getRelated());
        $this->assertSame($foreignKey, $relationship->getForeignKeyName());
    }

    /**
     * @param  class-string<Model>  $relatedModel
     */
    private function assertHasMany(HasMany $relationship, string $relatedModel, string $foreignKey): void
    {
        $this->assertInstanceOf($relatedModel, $relationship->getRelated());
        $this->assertSame($foreignKey, $relationship->getForeignKeyName());
    }
}
