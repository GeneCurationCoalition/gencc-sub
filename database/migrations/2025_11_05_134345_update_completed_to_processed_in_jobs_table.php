<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Update all jobs with status_v2='completed' to status_v2='processed'
     */
    public function up(): void
    {
        DB::table('jobs')
            ->where('status_v2', 'completed')
            ->update(['status_v2' => 'processed']);
    }

    /**
     * Reverse the migrations.
     * Revert status_v2='processed' back to status_v2='completed'
     */
    public function down(): void
    {
        DB::table('jobs')
            ->where('status_v2', 'processed')
            ->update(['status_v2' => 'completed']);
    }
};
