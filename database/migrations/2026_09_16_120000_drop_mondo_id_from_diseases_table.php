<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop diseases.mondo_id.
 *
 * The column linked an OMIM or Orphanet row to a MONDO row.  Under the
 * exact-only mapping policy (docs/DISEASE_MAPPING.md) equivalences are read from
 * each row's own `xrefs`, so nothing writes or reads the column any more, and
 * its values were partly derived from non-exact mappings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diseases', function (Blueprint $table) {
            // SQLite, used by the test suite, cannot drop a foreign key
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['mondo_id']);
            }
            $table->dropIndex(['mondo_id']);
            $table->dropColumn('mondo_id');
        });
    }

    /**
     * Restores the column empty; its previous values are not recoverable here.
     */
    public function down(): void
    {
        Schema::table('diseases', function (Blueprint $table) {
            $table->unsignedBigInteger('mondo_id')->nullable()->after('id');
            $table->foreign('mondo_id')->references('id')->on('diseases')->onDelete('set null');
            $table->index('mondo_id');
        });
    }
};
