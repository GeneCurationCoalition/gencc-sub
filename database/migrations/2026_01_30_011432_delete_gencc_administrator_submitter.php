<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Remove the "GenCC Administrator" submitter record (GENCC:000100).
     * Admin status is now determined by Team membership, so this
     * fake submitter is no longer needed.
     */
    public function up(): void
    {
        $submitter = DB::table('submitters')
            ->where('name', 'GenCC Administrator')
            ->orWhere('curie', 'GENCC:000100')
            ->first();

        if (!$submitter) {
            return;
        }

        // Clear submitter_id on any users that reference this submitter
        DB::table('users')
            ->where('submitter_id', $submitter->id)
            ->update(['submitter_id' => null]);

        // Remove pivot entries in submitter_user
        DB::table('submitter_user')
            ->where('submitter_id', $submitter->id)
            ->delete();

        // Clear submitter_id on any teams that reference this submitter
        if (Schema::hasColumn('teams', 'submitter_id')) {
            DB::table('teams')
                ->where('submitter_id', $submitter->id)
                ->update(['submitter_id' => null]);
        }

        // Delete the submitter record
        DB::table('submitters')
            ->where('id', $submitter->id)
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-create the GenCC Administrator submitter
        DB::table('submitters')->insert([
            'ident' => \Illuminate\Support\Str::uuid()->toString(),
            'curie' => 'GENCC:000100',
            'name' => 'GenCC Administrator',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
