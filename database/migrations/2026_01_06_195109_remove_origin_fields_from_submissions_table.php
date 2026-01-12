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
     * Removes origin_job_id and origin_snapshot columns from submissions table.
     * These fields are no longer needed because:
     * - The new versioning model creates a COPY of the submission when republishing/unpublishing
     * - The original submission is preserved as a historical version
     * - No "restore" operation is needed since the original still exists
     *
     * Note: origin_state is retained as it indicates the state the submission came from
     * (published vs unpublished) which is useful for understanding the submission's history.
     */
    public function up(): void
    {
        // Only drop columns if they exist (won't exist on fresh migrations/tests)
        if (Schema::hasColumn('submissions', 'origin_job_id')) {
            // SQLite doesn't support dropping foreign keys, so check for MySQL/MariaDB
            if (DB::connection()->getDriverName() !== 'sqlite') {
                Schema::table('submissions', function (Blueprint $table) {
                    $table->dropForeign(['origin_job_id']);
                });
            }
            Schema::table('submissions', function (Blueprint $table) {
                $table->dropColumn('origin_job_id');
            });
        }

        if (Schema::hasColumn('submissions', 'origin_snapshot')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->dropColumn('origin_snapshot');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('submissions', 'origin_job_id')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->unsignedBigInteger('origin_job_id')->nullable()->after('job_id');
            });

            // Only add foreign key for non-SQLite databases
            if (DB::connection()->getDriverName() !== 'sqlite') {
                Schema::table('submissions', function (Blueprint $table) {
                    $table->foreign('origin_job_id')->references('id')->on('jobs')->onDelete('set null');
                });
            }
        }

        if (!Schema::hasColumn('submissions', 'origin_snapshot')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->json('origin_snapshot')->nullable()->after('origin_state');
            });
        }
    }
};
