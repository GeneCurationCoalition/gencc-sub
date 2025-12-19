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
        Schema::table('documents', function (Blueprint $table) {
            // Upload state tracking
            $table->string('upload_state')->nullable()->after('processing_errors')
                ->comment('State: validating, validation_failed, validated, uploading, upload_complete, upload_partial');
            $table->integer('processed_submissions')->nullable()->after('upload_state');
            $table->integer('total_submissions')->nullable()->after('processed_submissions');
            $table->timestamp('upload_started_at')->nullable()->after('total_submissions');
            $table->timestamp('upload_completed_at')->nullable()->after('upload_started_at');
        });

        Schema::table('jobs', function (Blueprint $table) {
            // Lock job during upload processing
            $table->boolean('is_processing')->default(false)->after('status')
                ->comment('True when submissions are being uploaded - job is read-only');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'upload_state',
                'processed_submissions',
                'total_submissions',
                'upload_started_at',
                'upload_completed_at',
            ]);
        });

        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('is_processing');
        });
    }
};
