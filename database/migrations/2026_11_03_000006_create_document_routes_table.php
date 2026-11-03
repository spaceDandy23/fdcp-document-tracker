<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_version_id')->constrained('document_versions')->restrictOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('from_division_id')->constrained('divisions')->restrictOnDelete();
            $table->foreignId('to_division_id')->constrained('divisions')->restrictOnDelete();
            $table->string('route_type', 40)->default('forward');
            $table->string('status', 40)->default('pending');
            $table->text('remarks')->nullable();
            $table->timestamp('forwarded_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'status']);
            $table->index(['to_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_routes');
    }
};
