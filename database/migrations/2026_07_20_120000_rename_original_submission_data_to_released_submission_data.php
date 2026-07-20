<?php

use App\Models\Submission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename the frozen release snapshot and remove upload-era snapshots from drafts that will
     * be frozen again at publication. Draft unpublishes retain their copied prior snapshot,
     * because processing an unpublish does not create a new release snapshot.
     */
    public function up(): void
    {
        if (Schema::hasColumn('submissions', 'original_submission_data')
            && !Schema::hasColumn('submissions', 'released_submission_data')) {
            Schema::table('submissions', function ($table) {
                $table->renameColumn('original_submission_data', 'released_submission_data');
            });
        }

        if (Schema::hasColumn('submissions', 'released_submission_data')) {
            DB::table('submissions')
                ->whereIn('status', [Submission::STATUS_NEW, Submission::STATUS_REPUBLISH])
                ->whereNotNull('released_submission_data')
                ->update(['released_submission_data' => null]);
        }
    }

    /**
     * The column name can be restored, but cleared draft snapshots are intentionally
     * irreversible because their contents are redundant pre-release upload data.
     */
    public function down(): void
    {
        if (Schema::hasColumn('submissions', 'released_submission_data')
            && !Schema::hasColumn('submissions', 'original_submission_data')) {
            Schema::table('submissions', function ($table) {
                $table->renameColumn('released_submission_data', 'original_submission_data');
            });
        }
    }
};
