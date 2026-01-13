<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Rename Job status from 'processed' to 'released' for consistency
     * with submission terminology (released_at timestamp).
     *
     * Job statuses after this migration:
     * - 'draft': Submissions can be added/edited/removed
     * - 'submitted': Awaiting release processing
     * - 'released': All submissions released (was 'processed')
     */
    public function up(): void
    {
        DB::table('jobs')
            ->where('status', 'processed')
            ->update(['status' => 'released']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('jobs')
            ->where('status', 'released')
            ->update(['status' => 'processed']);
    }
};
