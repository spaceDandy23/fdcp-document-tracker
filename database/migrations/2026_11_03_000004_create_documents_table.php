<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_number')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('origin_division_id')->constrained('divisions')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('current_handler_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable(); // FK added after versions table
            $table->string('status', 40)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['current_handler_id', 'status']);
            $table->index(['origin_division_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
