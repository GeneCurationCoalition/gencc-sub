<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the public listing of submissions.
 *
 * The public browsers select live, published, undeleted submissions and join
 * each to its gene, disease, inheritance, classification and submitter.  No
 * index covered those columns, so every listing read whole rows of a table
 * that is mostly JSON, about 165k row reads per query.  With this index the
 * listing reads the index alone.  Measured on staging (two vCPUs): the gene
 * list's API from 1.18 s to 0.65 s, a classification filter from 0.77 s to
 * 0.16 s, a submitter filter from 0.71 s to 0.11 s.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->index(
                ['is_live', 'status', 'deleted_at', 'gene_id', 'disease_id', 'inheritance_id', 'classification_id', 'submitter_id'],
                'submissions_public_listing_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex('submissions_public_listing_index');
        });
    }
};
