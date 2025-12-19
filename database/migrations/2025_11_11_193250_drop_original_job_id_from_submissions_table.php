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
            // Drop index first for SQLite compatibility
            if (Schema::hasColumn('submissions', 'original_job_id')) {
                $table->dropIndex(['original_job_id']);
                $table->dropColumn('original_job_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->bigInteger('original_job_id')->unsigned()->nullable();
        });
    }
};
