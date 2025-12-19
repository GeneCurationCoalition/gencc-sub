<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // Add original_disease_id to store the disease record for the exact CURIE that was uploaded
            // - disease_id: always normalized to MONDO (foreign key to Disease table)
            // - original_disease_id: the disease record matching the exact uploaded CURIE (could be MONDO, OMIM, or Orphanet)
            // - If MONDO uploaded: both fields point to the same record
            // - If OMIM/Orphanet uploaded: original_disease_id = OMIM/Orphanet record, disease_id = mapped MONDO record
            $table->foreignId('original_disease_id')->nullable()->after('disease_id');
            $table->index('original_disease_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['original_disease_id']);
            $table->dropColumn('original_disease_id');
        });
    }
};
