<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sso_user_id')->nullable()->unique();
            $table->foreignId('division_id')->nullable()->constrained('divisions')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['division_id']);
            $table->dropColumn(['sso_user_id', 'division_id', 'is_active', 'last_synced_at']);
        });
    }
};
