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
            // Track which document a submission was created/modified from
            // Nullable because submissions can be created via API without a document
            $table->unsignedBigInteger('document_id')->nullable()->after('job_id');
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();
            $table->index('document_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
            $table->dropIndex(['document_id']);
            $table->dropColumn('document_id');
        });
    }
};
