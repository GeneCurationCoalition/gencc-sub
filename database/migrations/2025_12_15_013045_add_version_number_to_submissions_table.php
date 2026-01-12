<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add version_number column to track submission versions.
     * Each SGC ID (sid) can have multiple version records over time.
     * New submissions start at version 1, republished submissions increment.
     */
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // Version number - starts at 1, increments with each republish
            $table->unsignedInteger('version_number')->default(1)->after('sid');

            // Index for efficient queries by sid + version
            $table->index(['sid', 'version_number'], 'idx_submissions_sid_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex('idx_submissions_sid_version');
            $table->dropColumn('version_number');
        });
    }
};
