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
        if (Schema::hasTable('admin_logs')) {
            return;
        }

        Schema::create('admin_logs', function (Blueprint $table) {
            $table->id();
            $table->string('operation');  // run_publish, sync_pubmed, update_diseases, update_genes
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->boolean('success')->default(false);
            $table->integer('exit_code')->nullable();
            $table->text('output')->nullable();  // Console output
            $table->text('summary')->nullable();  // Markdown summary
            $table->timestamp('executed_at');
            $table->integer('duration_seconds')->nullable();  // How long the operation took
            $table->timestamps();

            // Index for quick lookups of latest by operation
            $table->index(['operation', 'executed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_logs');
    }
};
