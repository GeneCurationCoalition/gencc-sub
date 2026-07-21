<?php

namespace Tests\Feature;

use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MechanismCommentsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_and_spreadsheet_submission_documents_use_plural_comments_key(): void
    {
        $submission = new Submission;
        $submission->initialize_submission_data();
        $initialized = Submission::normalizeJsonField($submission->submission_data);

        $this->assertArrayHasKey('comments', $initialized['mechanism']);
        $this->assertArrayNotHasKey('comment', $initialized['mechanism']);

        $spreadsheet = json_decode(view('json.spreadsheet', [
            'd' => (object) ['gencc_mechanism_comment' => 'Spreadsheet mechanism comments'],
        ])->render(), true);

        $this->assertSame('Spreadsheet mechanism comments', $spreadsheet['mechanism']['comments']);
        $this->assertArrayNotHasKey('comment', $spreadsheet['mechanism']);
    }

    public function test_migration_renames_and_removes_the_singular_key_in_both_json_columns(): void
    {
        $submission = Submission::factory()->create([
            'submission_data' => [
                'mechanism' => ['id' => 'GENCC:200001', 'name' => 'Gain of Function', 'comment' => 'legacy current'],
            ],
            'original_submission_data' => [
                'mechanism' => [
                    'id' => 'GENCC:200001',
                    'name' => 'Gain of Function',
                    'comment' => 'stale legacy value',
                    'comments' => 'canonical original',
                ],
            ],
        ]);
        $nullComment = Submission::factory()->create([
            'submission_data' => ['mechanism' => ['comment' => null]],
            'original_submission_data' => null,
        ]);
        $migration = require database_path('migrations/2026_07_21_130000_canonicalize_mechanism_comments.php');

        $migration->up();

        $current = $this->jsonColumn($submission->id, 'submission_data');
        $original = $this->jsonColumn($submission->id, 'original_submission_data');
        $nullCurrent = $this->jsonColumn($nullComment->id, 'submission_data');

        $this->assertSame('legacy current', $current['mechanism']['comments']);
        $this->assertArrayNotHasKey('comment', $current['mechanism']);
        $this->assertSame('canonical original', $original['mechanism']['comments']);
        $this->assertArrayNotHasKey('comment', $original['mechanism']);
        $this->assertArrayHasKey('comments', $nullCurrent['mechanism']);
        $this->assertNull($nullCurrent['mechanism']['comments']);
        $this->assertArrayNotHasKey('comment', $nullCurrent['mechanism']);

        $afterFirstRun = [$current, $original, $nullCurrent];
        $migration->up();

        $this->assertSame($afterFirstRun, [
            $this->jsonColumn($submission->id, 'submission_data'),
            $this->jsonColumn($submission->id, 'original_submission_data'),
            $this->jsonColumn($nullComment->id, 'submission_data'),
        ]);
    }

    private function jsonColumn(int $submissionId, string $column): array
    {
        return json_decode(
            DB::table('submissions')->where('id', $submissionId)->value($column),
            true,
        );
    }
}
