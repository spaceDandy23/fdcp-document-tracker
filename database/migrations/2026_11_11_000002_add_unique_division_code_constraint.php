<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('divisions')
            ->whereNotNull('code')
            ->select('code')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('code');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Cannot add the unique divisions.code constraint. Duplicate non-null codes found: '
                .$duplicates->implode(', ').'. Resolve these records without merging or deleting them, then retry the migration.');
        }

        Schema::table('divisions', function (Blueprint $table): void {
            $table->unique('code', 'divisions_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('divisions', function (Blueprint $table): void {
            $table->dropUnique('divisions_code_unique');
        });
    }
};
