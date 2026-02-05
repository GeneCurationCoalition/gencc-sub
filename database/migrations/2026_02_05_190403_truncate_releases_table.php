<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Ensures the releases table is empty (clears any test/dev data).
     */
    public function up(): void
    {
        if (Schema::hasTable('releases')) {
            DB::table('releases')->truncate();
        }
    }

    /**
     * Reverse the migrations.
     * Cannot restore truncated data - this is a one-way operation.
     */
    public function down(): void
    {
        // Data cannot be restored after truncation
    }
};
