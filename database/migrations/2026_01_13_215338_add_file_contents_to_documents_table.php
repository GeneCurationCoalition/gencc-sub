<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Store file contents as LONGBLOB (up to 4GB)
            $table->longText('file_contents')->nullable()->after('local_path');
        });

        // Make local_path nullable using raw SQL (avoids doctrine/dbal dependency)
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE documents MODIFY local_path VARCHAR(255) NULL');
        }
        // SQLite columns are nullable by default when no NOT NULL constraint, handled in original migration
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('file_contents');
        });
    }
};
