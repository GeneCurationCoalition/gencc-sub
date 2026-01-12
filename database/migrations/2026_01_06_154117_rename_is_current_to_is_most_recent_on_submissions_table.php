<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Renames is_current to is_most_recent to better reflect its meaning:
     * indicates whether this version of a submission (by SID) is the most recent.
     *
     * For fresh installs/tests, adds the column if it doesn't exist.
     */
    public function up(): void
    {
        if (Schema::hasColumn('submissions', 'is_current')) {
            // Existing database - rename the column
            Schema::table('submissions', function (Blueprint $table) {
                $table->renameColumn('is_current', 'is_most_recent');
            });
        } elseif (!Schema::hasColumn('submissions', 'is_most_recent')) {
            // Fresh install/test - add the column directly
            Schema::table('submissions', function (Blueprint $table) {
                $table->boolean('is_most_recent')->default(true)->after('version_number');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('submissions', 'is_most_recent')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->renameColumn('is_most_recent', 'is_current');
            });
        }
    }
};
