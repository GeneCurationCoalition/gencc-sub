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
            // Add origin_job_id to track original job when moving to draft_republish/draft_unpublish
            $table->unsignedBigInteger('origin_job_id')->nullable()->after('origin_state');
            $table->foreign('origin_job_id')->references('id')->on('jobs')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropForeign(['origin_job_id']);
            $table->dropColumn('origin_job_id');
        });
    }
};
