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
        if (Schema::hasTable('releases')) {
            return;
        }

        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->datetime('released_at');
            $table->string('release_notes_file')->nullable();
            $table->string('submissions_csv_file')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('new_count')->default(0);
            $table->integer('republish_count')->default(0);
            $table->integer('unpublish_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('total_count')->default(0);
            $table->json('jobs_processed')->nullable();
            $table->json('errors')->nullable();
            $table->json('by_submitter')->nullable();
            $table->json('cumulative_stats')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
