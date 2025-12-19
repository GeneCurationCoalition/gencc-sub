<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Remove legacy API keys from settings table.
     * These are now sourced from environment variables:
     * - GENCC_PUBLISH_TOKEN (replaces 'publish' setting)
     * - OMIM_API_KEY (replaces 'omim' setting)
     */
    public function up(): void
    {
        DB::table('settings')->whereIn('key', ['publish', 'omim'])->delete();
    }

    /**
     * Reverse the migrations.
     *
     * Note: This does not restore the original values since they should
     * now be configured via environment variables in .env
     */
    public function down(): void
    {
        // Values must be restored manually from .env if needed
    }
};
