<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * These indexes optimize the most common query patterns in file upload processing:
     * - Duplicate detection queries (gene_id, original_disease_id, inheritance_id)
     * - SGC ID lookups for republish/unpublish actions
     * - Status + is_live filtering
     */
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // Composite index for duplicate detection queries
            // Supports: WHERE submitter_id = ? AND gene_id = ? AND original_disease_id = ? AND inheritance_id = ?
            // This is the most critical index - duplicate detection runs for every row in file uploads
            $table->index(
                ['submitter_id', 'gene_id', 'original_disease_id', 'inheritance_id'],
                'idx_duplicate_detection'
            );

            // Index for SGC ID lookups by submitter (republish/unpublish actions)
            // Supports: WHERE submitter_id = ? AND sid = ?
            $table->index(['submitter_id', 'sid'], 'idx_submitter_sid');

            // Index for status + is_live filtering
            // Supports: WHERE status IN (...) AND is_live = ? AND submitter_id = ?
            $table->index(['status', 'is_live', 'submitter_id'], 'idx_status_live_submitter');

            // Index for job submissions with status filtering
            // Supports: WHERE job_id = ? AND status = ?
            $table->index(['job_id', 'status'], 'idx_job_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex('idx_duplicate_detection');
            $table->dropIndex('idx_submitter_sid');
            $table->dropIndex('idx_status_live_submitter');
            $table->dropIndex('idx_job_status');
        });
    }
};
