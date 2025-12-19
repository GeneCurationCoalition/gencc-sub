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
     * Make foreign key columns nullable to support new submissions
     * that start with empty values (user fills in later).
     */
    public function up(): void
    {
        // SQLite doesn't support ALTER COLUMN, so we need to use raw SQL for MySQL
        // and skip for SQLite (which allows NULL by default unless NOT NULL is specified)
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE submissions MODIFY gene_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE submissions MODIFY disease_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE submissions MODIFY original_disease_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE submissions MODIFY inheritance_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE submissions MODIFY classification_id BIGINT UNSIGNED NULL');
        }
        // For SQLite (used in tests), columns are already nullable unless NOT NULL was specified
        // The test database uses fresh migrations so this isn't needed
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: Reversing this migration requires all existing null values
        // to be populated first. This is a one-way migration in practice.
        if (DB::connection()->getDriverName() === 'mysql') {
            // Cannot safely reverse - would need to handle existing nulls first
            // DB::statement('ALTER TABLE submissions MODIFY gene_id BIGINT UNSIGNED NOT NULL');
            // etc.
        }
    }
};
