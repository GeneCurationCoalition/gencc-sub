<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration:
     * 1. Drops the index on the legacy 'status' column
     * 2. Drops the legacy integer 'status' column (no longer used in runtime code)
     * 3. Renames 'status_v2' to 'status' (the new string-based status)
     */
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // First drop the index on the legacy status column
            $table->dropIndex(['status']);
        });

        Schema::table('jobs', function (Blueprint $table) {
            // Drop the legacy integer status column
            $table->dropColumn('status');
        });

        Schema::table('jobs', function (Blueprint $table) {
            // Rename status_v2 to status
            $table->renameColumn('status_v2', 'status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            // Rename status back to status_v2
            $table->renameColumn('status', 'status_v2');
        });

        Schema::table('jobs', function (Blueprint $table) {
            // Recreate the legacy status column
            $table->integer('status')->default(2)->after('status_v2');
        });

        Schema::table('jobs', function (Blueprint $table) {
            // Recreate the index
            $table->index('status');
        });
    }
};
