<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->index(['status', 'submitted_at'], 'documents_status_submitted_at_index');
            $table->index(['document_type_id', 'submitted_at'], 'documents_type_submitted_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex('documents_status_submitted_at_index');
            $table->dropIndex('documents_type_submitted_at_index');
        });
    }
};
