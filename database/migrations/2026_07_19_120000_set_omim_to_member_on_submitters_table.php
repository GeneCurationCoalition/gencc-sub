<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Change OMIM (GENCC:000109) from a submitter to a member by turning off
     * allow_submissions. In this schema the submitter/member distinction is
     * captured entirely by the allow_submissions flag:
     *   allow_submissions = true  -> submitter (may submit data)
     *   allow_submissions = false -> member (does not submit data)
     */
    public function up(): void
    {
        DB::table('submitters')
            ->where('curie', 'GENCC:000109')
            ->update(['allow_submissions' => false]);
    }

    /**
     * Reverse the migrations.
     *
     * Restore OMIM to a submitter.
     */
    public function down(): void
    {
        DB::table('submitters')
            ->where('curie', 'GENCC:000109')
            ->update(['allow_submissions' => true]);
    }
};
