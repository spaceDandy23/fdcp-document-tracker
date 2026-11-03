<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_route_id')->nullable()->constrained('document_routes')->restrictOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 50);
            $table->text('remarks')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_actions');
    }
};
