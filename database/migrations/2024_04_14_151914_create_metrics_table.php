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
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->string('ident')->unique();
            $table->tinyInteger('type')->default(1);
            $table->jsonb('jobs_queued')->nullable();
            $table->jsonb('jobs_processing')->nullable();
            $table->jsonb('jobs_errors')->nullable();
            $table->jsonb('jobs_window')->nullable();
            $table->jsonb('jobs_complete')->nullable();
            $table->jsonb('jobs_removed')->nullable();
            $table->jsonb('submissions_queued')->nullable();
            $table->jsonb('submissions_processing')->nullable();
            $table->jsonb('submissions_errors')->nullable();
            $table->jsonb('submissions_window')->nullable();
            $table->jsonb('submissions_published')->nullable();
            $table->jsonb('submissions_removed')->nullable();
            $table->jsonb('classifications_definitive')->nullable();
            $table->jsonb('classifications_strong')->nullable();
            $table->jsonb('classifications_moderate')->nullable();
            $table->jsonb('classifications_supportive')->nullable();
            $table->jsonb('classifications_limited')->nullable();
            $table->jsonb('classifications_disputed')->nullable();
            $table->jsonb('classifications_refuted')->nullable();
            $table->jsonb('classifications_animal')->nullable();
            $table->jsonb('classifications_nodisease')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metrics');
    }
};
