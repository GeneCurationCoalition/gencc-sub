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
            // Add JSON field to track submissions that were successfully processed
            // Stores array of objects: [{"sid": "SGC-12345", "action": "published|republished|unpublished"}]
            // This maintains a historical record even if submissions are moved to other jobs
            $table->json('processed_submission_ids')->nullable()->after('submission_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('processed_submission_ids');
        });
    }
};
