<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds is_most_recent flag to jobs table.
     * For jobs, this indicates whether a job is the "current working draft" or
     * has been superseded. All existing jobs default to true since there's no
     * version history for jobs yet.
     */
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->boolean('is_most_recent')->default(true)->after('status');
        });

        // All existing jobs are considered "most recent" by default
        // Since jobs don't have version history like submissions, this is primarily
        // for future consistency if job versioning is ever implemented
        DB::table('jobs')->update(['is_most_recent' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('is_most_recent');
        });
    }
};
