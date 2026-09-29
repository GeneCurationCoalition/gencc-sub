<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disease_audit_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->index();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('scope');
            $table->string('app_version')->nullable();
            $table->json('source_metadata')->nullable();
            $table->unsignedInteger('examined_count')->default(0);
            $table->unsignedInteger('affected_count')->default(0);
            $table->json('case_counts')->nullable();
            $table->text('failure')->nullable();
        });

        Schema::create('disease_audit_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('disease_audit_runs')->cascadeOnDelete();
            // Snapshot identities intentionally survive later record deletion.
            $table->unsignedBigInteger('submission_id');
            $table->string('sid')->nullable();
            $table->unsignedInteger('version_number')->nullable();
            $table->string('job_slug')->nullable();
            $table->unsignedBigInteger('submitter_id')->nullable();
            $table->string('submitter_name')->nullable();
            $table->string('submission_status');
            $table->string('job_status')->nullable();
            $table->string('namespace')->nullable();
            $table->text('submitted_id')->nullable();
            $table->text('normalized_id')->nullable();
            $table->string('stored_curie')->nullable();
            $table->string('current_curie')->nullable();
            $table->json('cases');
            $table->json('evidence');
            $table->unique(['run_id', 'submission_id']);
            $table->index(['run_id', 'submitter_id']);
            $table->index(['run_id', 'submission_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disease_audit_findings');
        Schema::dropIfExists('disease_audit_runs');
    }
};
