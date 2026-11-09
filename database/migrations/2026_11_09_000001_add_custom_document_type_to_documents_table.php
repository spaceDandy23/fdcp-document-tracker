<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignId('document_type_id')->nullable()->change();
            $table->string('custom_document_type', 120)->nullable()->after('document_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('custom_document_type');
            $table->foreignId('document_type_id')->nullable(false)->change();
        });
    }
};
