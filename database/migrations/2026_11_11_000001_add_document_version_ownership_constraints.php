<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $invalidDocumentIds = DB::table('documents')
            ->leftJoin('document_versions', function (JoinClause $join): void {
                $join->on('document_versions.id', '=', 'documents.current_version_id')
                    ->on('document_versions.document_id', '=', 'documents.id');
            })
            ->whereNotNull('documents.current_version_id')
            ->whereNull('document_versions.id')
            ->limit(10)
            ->pluck('documents.id');

        if ($invalidDocumentIds->isNotEmpty()) {
            throw new RuntimeException('Cannot add document version ownership constraints: invalid current_version_id references for document IDs '
                .$invalidDocumentIds->implode(', ').'. Correct these rows before retrying the migration.');
        }

        $invalidRouteIds = DB::table('document_routes')
            ->leftJoin('document_versions', function (JoinClause $join): void {
                $join->on('document_versions.id', '=', 'document_routes.document_version_id')
                    ->on('document_versions.document_id', '=', 'document_routes.document_id');
            })
            ->whereNull('document_versions.id')
            ->limit(10)
            ->pluck('document_routes.id');

        if ($invalidRouteIds->isNotEmpty()) {
            throw new RuntimeException('Cannot add document version ownership constraints: invalid document route version references for route IDs '
                .$invalidRouteIds->implode(', ').'. Correct these rows before retrying the migration.');
        }

        Schema::table('documents', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign('documents_current_version_id_foreign');
            }
            $table->index(['id', 'current_version_id'], 'documents_current_version_owner_index');
            $table->foreign(['id', 'current_version_id'], 'documents_current_version_owner_foreign')
                ->references(['document_id', 'id'])
                ->on('document_versions')
                ->restrictOnDelete();
        });

        Schema::table('document_routes', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign('document_routes_document_version_id_foreign');
            }
            $table->index(['document_id', 'document_version_id'], 'document_routes_version_owner_index');
            $table->foreign(['document_id', 'document_version_id'], 'document_routes_version_owner_foreign')
                ->references(['document_id', 'id'])
                ->on('document_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_routes', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign('document_routes_version_owner_foreign');
            }
            $table->dropIndex('document_routes_version_owner_index');
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('document_version_id', 'document_routes_document_version_id_foreign')
                    ->references('id')->on('document_versions')->restrictOnDelete();
            }
        });

        Schema::table('documents', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign('documents_current_version_owner_foreign');
            }
            $table->dropIndex('documents_current_version_owner_index');
            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('current_version_id', 'documents_current_version_id_foreign')
                    ->references('id')->on('document_versions')->restrictOnDelete();
            }
        });
    }
};
