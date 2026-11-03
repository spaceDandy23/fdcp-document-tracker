<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('storage_path');
            $table->string('disk', 50)->default('local');
            $table->string('original_filename');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('file_size');
            $table->text('change_summary')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['document_id', 'version_number']);
            $table->unique(['document_id', 'id']); // allows composite FK for current version integrity
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
