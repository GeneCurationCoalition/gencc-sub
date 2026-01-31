<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add submitter_id column
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('submitter_id')->nullable()->after('user_id')->constrained('submitters')->nullOnDelete();
        });

        // Backfill submitter_id from the team owner's submitter_id
        $teams = DB::table('teams')
            ->join('users', 'teams.user_id', '=', 'users.id')
            ->whereNotNull('users.submitter_id')
            ->select('teams.id', 'users.submitter_id')
            ->get();

        foreach ($teams as $team) {
            DB::table('teams')
                ->where('id', $team->id)
                ->update(['submitter_id' => $team->submitter_id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropForeign(['submitter_id']);
            $table->dropColumn('submitter_id');
        });
    }
};
