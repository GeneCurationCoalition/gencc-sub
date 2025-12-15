<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the status column to submissions table.
     * This column stores the submission state: draft_new, draft_republish, draft_unpublish,
     * submitted_new, submitted_republish, submitted_unpublish, published, unpublished
     */
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->string('status', 50)->nullable()->after('is_current');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
