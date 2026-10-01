<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the submission <-> PubMed link table.
 *
 * The table was created with no keys, so every read or delete of a
 * submission's links scanned the whole table (about 150k rows).  Plain
 * indexes, not unique ones: existing data may hold duplicate pairs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pubmed_submission', function (Blueprint $table) {
            $table->index(['submission_id', 'pubmed_id']);
            $table->index('pubmed_id');
        });
    }

    public function down(): void
    {
        Schema::table('pubmed_submission', function (Blueprint $table) {
            $table->dropIndex(['submission_id', 'pubmed_id']);
            $table->dropIndex(['pubmed_id']);
        });
    }
};
