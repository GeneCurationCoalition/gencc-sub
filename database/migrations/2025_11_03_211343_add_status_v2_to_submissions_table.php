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
        Schema::table('submissions', function (Blueprint $table) {
            // Add new string-based status column
            $table->string('status_v2', 50)->default('draft_new')->after('status');

            // Add origin_state to track where draft_republish came from
            $table->string('origin_state', 50)->nullable()->after('status_v2');

            // Add indexes for performance
            $table->index('status_v2');
            $table->index(['status_v2', 'job_id']); // Compound index for common queries
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['submissions_status_v2_index']);
            $table->dropIndex(['submissions_status_v2_job_id_index']);
            $table->dropColumn(['status_v2', 'origin_state']);
        });
    }
};
