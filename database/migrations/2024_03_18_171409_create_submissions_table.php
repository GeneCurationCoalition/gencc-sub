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
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->string('ident')->unique();
            $table->tinyInteger('type')->default(0);
            $table->string('sid')->nullable();
            $table->foreignId('job_id');
            $table->foreignId('gene_id')->nullable();
            $table->foreignId('disease_id')->nullable();
            $table->foreignId('inheritance_id')->nullable();
            $table->foreignId('submitter_id');
            $table->foreignId('classification_id')->nullable();
            $table->foreignId('user_id');
            $table->timestamp('submission_date');
            $table->timestamp('publish_date')->nullable();
            $table->timestamp('posted_date')->nullable();
            $table->timestamp('report_date')->nullable();
            $table->string('report_url')->nullable();
            $table->jsonb('submission_data');
            $table->string('version')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->timestamps();
            $table->softDeletes();

            // create some index keys
            $table->index('sid');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
