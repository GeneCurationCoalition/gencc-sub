<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Renames published_at to released_at on both jobs and submissions tables.
     * This better reflects the semantic meaning:
     * - For jobs: when the job was released/processed
     * - For submissions: when the submission was released (published or unpublished)
     */
    public function up(): void
    {
        // Only rename if published_at exists (won't exist on fresh migrations if
        // the original migration that created it is updated)
        if (Schema::hasColumn('jobs', 'published_at')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->renameColumn('published_at', 'released_at');
            });
        }

        if (Schema::hasColumn('submissions', 'published_at')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->renameColumn('published_at', 'released_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('jobs', 'released_at')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->renameColumn('released_at', 'published_at');
            });
        }

        if (Schema::hasColumn('submissions', 'released_at')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->renameColumn('released_at', 'published_at');
            });
        }
    }
};
