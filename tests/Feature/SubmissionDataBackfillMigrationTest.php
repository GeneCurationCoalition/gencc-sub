<?php

namespace Tests\Feature;

use App\Models\Job;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Exercises the release-snapshot rename migration:
 *  - renames the physical column without losing released values;
 *  - clears redundant upload-era snapshots from new and republish drafts;
 *  - preserves copied snapshots on unpublish drafts and released versions.
 */
class SubmissionDataBackfillMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_07_20_120000_rename_original_submission_data_to_released_submission_data.php');
        $migration->up();
    }

    private function snapshot(string $value): array
    {
        return ['notes' => ['display' => $value]];
    }

    private function seedSubmission(string $status, string $snapshot): Submission
    {
        $job = Job::factory()->create(['status' => Job::STATUS_DRAFT]);

        return Submission::factory()->create([
            'job_id' => $job->id,
            'status' => $status,
            'released_submission_data' => $this->snapshot($snapshot),
        ]);
    }

    public function test_renames_the_physical_column_without_losing_released_data(): void
    {
        $submission = $this->seedSubmission(Submission::STATUS_PUBLISHED, 'published snapshot');

        Schema::table('submissions', function ($table) {
            $table->renameColumn('released_submission_data', 'original_submission_data');
        });

        $this->runMigration();

        $this->assertTrue(Schema::hasColumn('submissions', 'released_submission_data'));
        $this->assertFalse(Schema::hasColumn('submissions', 'original_submission_data'));
        $this->assertSame(
            'published snapshot',
            json_decode(DB::table('submissions')->find($submission->id)->released_submission_data)->notes->display
        );
    }

    public function test_clears_new_and_republish_draft_snapshots_but_preserves_unpublish_and_released_rows(): void
    {
        $new = $this->seedSubmission(Submission::STATUS_NEW, 'new upload');
        $republish = $this->seedSubmission(Submission::STATUS_REPUBLISH, 'republish draft');
        $unpublish = $this->seedSubmission(Submission::STATUS_UNPUBLISH, 'copied release');
        $published = $this->seedSubmission(Submission::STATUS_PUBLISHED, 'published release');
        $unpublished = $this->seedSubmission(Submission::STATUS_UNPUBLISHED, 'unpublished release');

        $this->runMigration();

        $this->assertNull($new->fresh()->released_submission_data);
        $this->assertNull($republish->fresh()->released_submission_data);
        $this->assertSame('copied release', $unpublish->fresh()->released_submission_data->notes->display);
        $this->assertSame('published release', $published->fresh()->released_submission_data->notes->display);
        $this->assertSame('unpublished release', $unpublished->fresh()->released_submission_data->notes->display);
    }

    public function test_cleanup_is_idempotent(): void
    {
        $new = $this->seedSubmission(Submission::STATUS_NEW, 'new upload');
        $unpublish = $this->seedSubmission(Submission::STATUS_UNPUBLISH, 'copied release');

        $this->runMigration();
        $this->runMigration();

        $this->assertNull($new->fresh()->released_submission_data);
        $this->assertSame('copied release', $unpublish->fresh()->released_submission_data->notes->display);
    }
}
