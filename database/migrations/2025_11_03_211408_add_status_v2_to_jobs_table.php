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
        Schema::table('jobs', function (Blueprint $table) {
            // Add new string-based status column
            $table->string('status_v2', 50)->default('draft')->after('status');

            // Add index for performance
            $table->index('status_v2');
            $table->index(['status_v2', 'submitter_id']); // Compound index for common queries
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex(['jobs_status_v2_index']);
            $table->dropIndex(['jobs_status_v2_submitter_id_index']);
            $table->dropColumn('status_v2');
        });
    }
};
