<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds mondo_id foreign key to diseases table to enable fast equivalence lookups
     * between OMIM/Orphanet diseases and their canonical MONDO representations.
     *
     * - MONDO diseases: mondo_id = NULL (they are the canonical reference)
     * - OMIM diseases: mondo_id = ID of equivalent MONDO disease
     * - Orphanet diseases: mondo_id = ID of equivalent MONDO disease
     */
    public function up(): void
    {
        Schema::table('diseases', function (Blueprint $table) {
            $table->unsignedBigInteger('mondo_id')->nullable()->after('id');
            $table->foreign('mondo_id')->references('id')->on('diseases')->onDelete('set null');
            $table->index('mondo_id');
        });

        // Only populate mondo_id on MySQL (skip for SQLite tests)
        // SQLite tests use empty databases, so no data to populate
        if (\DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Populate mondo_id for existing OMIM diseases
        // Find the MONDO disease that has this OMIM ID in its xrefs->omim_id array
        // Uses temporary table to avoid MySQL error 1093 (can't update table being selected from)
        \DB::statement("
            CREATE TEMPORARY TABLE mondo_omim_lookup AS
            SELECT
                id as mondo_id,
                JSON_EXTRACT(xrefs, '$.omim_id') as omim_ids
            FROM diseases
            WHERE type = 1
            AND deleted_at IS NULL
            AND xrefs IS NOT NULL
        ");

        \DB::statement("
            UPDATE diseases AS omim
            INNER JOIN mondo_omim_lookup
            ON JSON_CONTAINS(mondo_omim_lookup.omim_ids, JSON_QUOTE(SUBSTRING(omim.curie, 6)))
            SET omim.mondo_id = mondo_omim_lookup.mondo_id
            WHERE omim.type IN (10, 11, 12, 13, 14, 15)
            AND omim.deleted_at IS NULL
        ");

        \DB::statement("DROP TEMPORARY TABLE mondo_omim_lookup");

        // Populate mondo_id for existing Orphanet diseases
        // Find the MONDO disease that has this Orphanet ID in its xrefs->orpha_id
        \DB::statement("
            CREATE TEMPORARY TABLE mondo_orphanet_lookup AS
            SELECT
                id as mondo_id,
                JSON_EXTRACT(xrefs, '$.orpha_id') as orpha_ids
            FROM diseases
            WHERE type = 1
            AND deleted_at IS NULL
            AND xrefs IS NOT NULL
        ");

        \DB::statement("
            UPDATE diseases AS orpha
            INNER JOIN mondo_orphanet_lookup
            ON JSON_CONTAINS(mondo_orphanet_lookup.orpha_ids, JSON_QUOTE(SUBSTRING(orpha.curie, 10)))
            OR JSON_CONTAINS(mondo_orphanet_lookup.orpha_ids, JSON_QUOTE(SUBSTRING(orpha.curie, 7)))
            SET orpha.mondo_id = mondo_orphanet_lookup.mondo_id
            WHERE orpha.type = 20
            AND orpha.deleted_at IS NULL
        ");

        \DB::statement("DROP TEMPORARY TABLE mondo_orphanet_lookup");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('diseases', function (Blueprint $table) {
            $table->dropForeign(['mondo_id']);
            $table->dropIndex(['mondo_id']);
            $table->dropColumn('mondo_id');
        });
    }
};
