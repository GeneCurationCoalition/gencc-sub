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
        // Actions table - no indices existed at all
        Schema::table('actions', function (Blueprint $table) {
            $table->index(['status', 'type'], 'idx_actions_status_type');
        });

        // Submissions table - missing single-column indices for JOIN targets
        Schema::table('submissions', function (Blueprint $table) {
            if (!Schema::hasIndex('submissions', 'idx_submissions_classification_id')) {
                $table->index('classification_id', 'idx_submissions_classification_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->dropIndex('idx_actions_status_type');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex('idx_submissions_classification_id');
        });
    }
};
