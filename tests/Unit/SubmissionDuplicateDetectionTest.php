<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Submission;
use App\Models\Job;
use App\Models\Gene;
use App\Models\Disease;
use App\Models\Inheritance;
use App\Models\Classification;
use App\Models\Submitter;
use App\Models\User;
use App\Services\SubmissionDuplicateDetection;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SubmissionDuplicateDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Submitter $submitter;
    protected Job $job;
    protected Gene $gene;
    protected Gene $gene2;
    protected Disease $disease;
    protected Disease $disease2;
    protected Inheritance $inheritance;
    protected Inheritance $inheritance2;
    protected Classification $classification;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user and submitter
        $this->user = User::factory()->create();
        $this->submitter = Submitter::factory()->create();

        // Create test job
        $this->job = Job::factory()->create([
            'user_id' => $this->user->id,
            'submitter_id' => $this->submitter->id,
            'status' => Job::STATUS_DRAFT,
        ]);

        // Create test genes
        $this->gene = Gene::factory()->create(['hgnc_id' => 'HGNC:1']);
        $this->gene2 = Gene::factory()->create(['hgnc_id' => 'HGNC:2']);

        // Create test diseases
        $this->disease = Disease::factory()->create(['curie' => 'MONDO:0000001']);
        $this->disease2 = Disease::factory()->create(['curie' => 'MONDO:0000002']);

        // Create test inheritances
        $this->inheritance = Inheritance::factory()->create(['curie' => 'HP:0000001']);
        $this->inheritance2 = Inheritance::factory()->create(['curie' => 'HP:0000002']);

        // Create test classification
        $this->classification = Classification::factory()->create();
    }

    /**
     * Test no duplicate when no matching submissions exist
     */
    public function test_no_duplicate_when_no_matching_submissions(): void
    {
        $result = SubmissionDuplicateDetection::checkForDuplicates(
            $this->submitter->id,
            $this->gene->id,
            $this->disease->id,
            $this->inheritance->id
        );

        $this->assertFalse($result['has_blocking_duplicate']);
        $this->assertFalse($result['has_unpublished_duplicate']);
        $this->assertTrue($result['duplicates']->isEmpty());
    }

    /**
     * Test no duplicate when null fields
     */
    public function test_no_duplicate_when_null_fields(): void
    {
        $result = SubmissionDuplicateDetection::checkForDuplicates(
            $this->submitter->id,
            null,
            $this->disease->id,
            $this->inheritance->id
        );

        $this->assertFalse($result['has_blocking_duplicate']);
        $this->assertFalse($result['has_unpublished_duplicate']);
    }

    /**
     * Test blocking duplicate found for published submission
     */
    public function test_blocking_duplicate_for_published_submission(): void
    {
        // Create existing published submission
        $existingSubmission = Submission::factory()->create([
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene->id,
            'original_disease_id' => $this->disease->id,
            'inheritance_id' => $this->inheritance->id,
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        $result = SubmissionDuplicateDetection::checkForDuplicates(
            $this->submitter->id,
            $this->gene->id,
            $this->disease->id,
            $this->inheritance->id
        );

        $this->assertTrue($result['has_blocking_duplicate']);
        $this->assertFalse($result['has_unpublished_duplicate']);
        $this->assertEquals(1, $result['duplicates']->count());
        $this->assertEquals($existingSubmission->sid, $result['duplicates']->first()->sid);
    }

    /**
     * Test blocking duplicate found for draft_new submission
     */
    public function test_blocking_duplicate_for_draft_new_submission(): void
    {
        Submission::factory()->create([
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene->id,
            'original_disease_id' => $this->disease->id,
            'inheritance_id' => $this->inheritance->id,
            'status' => Submission::STATUS_DRAFT_NEW,
        ]);

        $result = SubmissionDuplicateDetection::checkForDuplicates(
            $this->submitter->id,
            $this->gene->id,
            $this->disease->id,
            $this->inheritance->id
        );

        $this->assertTrue($result['has_blocking_duplicate']);
    }

    /**
     * Test blocking duplicate found for draft_republish submission
     */
    public function test_blocking_duplicate_for_draft_republish_submission(): void
    {
        Submission::factory()->create([
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene->id,
            'original_disease_id' => $this->disease->id,
            'inheritance_id' => $this->inheritance->id,
            'status' => Submission::STATUS_DRAFT_REPUBLISH,
        ]);

        $result = SubmissionDuplicateDetection::checkForDuplicates(
            $this->submitter->id,
            $this->gene->id,
            $this->disease->id,
            $this->inheritance->id
        );

        $this->assertTrue($result['has_blocking_duplicate']);
    }

    /**
     * Test blocking duplicate found for submitted_new submission
     */
    public function test_blocking_duplicate_for_submitted_new_submission(): void
    {
        Submission::factory()->create([
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene->id,
            'original_disease_id' => $this->disease->id,
            'inheritance_id' => $this->inheritance->id,
            'status' => Submission::STATUS_SUBMITTED_NEW,
        ]);

        $result = SubmissionDuplicateDetection::checkForDuplicates(
            $this->submitter->id,
            $this->gene->id,
            $this->disease->id,
            $this->inheritance->id
        );

        $this->assertTrue($result['has_blocking_duplicate']);
    }

    /**
     * Test warning (not blocking) for unpublished submission
     */
    public function test_warning_for_unpublished_submission(): void
    {
        $existingSubmission = Submission::factory()->create([
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene->id,
            'original_disease_id' => $this->disease->id,
            'inheritance_id' => $this->inheritance->id,
            'status' => Submission::STATUS_UNPUBLISHED,
        ]);

        $result = SubmissionDuplicateDetection::checkForDuplicates(
            $this->submitter->id,
            $this->gene->id,
            $this->disease->id,
            $this->inheritance->id
        );

        $this->assertFalse($result['has_blocking_duplicate']);
        $this->assertTrue($result['has_unpublished_duplicate']);
        $this->assertEquals(1, $result['unpublished_duplicates']->count());
        $this->assertEquals($existingSubmission->sid, $result['unpublished_duplicates']->first()->sid);
    }

    /**
     * Test self-exclusion when updating
     */
    public function test_exclude_self_when_updating(): void
    {
        $submission = Submission::factory()->create([
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene->id,
            'original_disease_id' => $this->disease->id,
            'inheritance_id' => $this->inheritance->id,
            'status' => Submission::STATUS_DRAFT_NEW,
        ]);

        // Check without exclusion - should find duplicate (self)
        $resultWithoutExclusion = SubmissionDuplicateDetection::checkForDuplicates(
            $this->submitter->id,
            $this->gene->id,
            $this->disease->id,
            $this->inheritance->id
        );

        $this->assertTrue($resultWithoutExclusion['has_blocking_duplicate']);

        // Check with exclusion - should not find duplicate
        $resultWithExclusion = SubmissionDuplicateDetection::checkForDuplicates(
            $this->submitter->id,
            $this->gene->id,
            $this->disease->id,
            $this->inheritance->id,
            $submission->id
        );

        $this->assertFalse($resultWithExclusion['has_blocking_duplicate']);
    }

    /**
     * Test different submitter doesn't trigger duplicate
     */
    public function test_different_submitter_no_duplicate(): void
    {
        $otherSubmitter = Submitter::factory()->create();

        Submission::factory()->create([
            'submitter_id' => $otherSubmitter->id,
            'gene_id' => $this->gene->id,
            'original_disease_id' => $this->disease->id,
            'inheritance_id' => $this->inheritance->id,
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        $result = SubmissionDuplicateDetection::checkForDuplicates(
            $this->submitter->id,
            $this->gene->id,
            $this->disease->id,
            $this->inheritance->id
        );

        $this->assertFalse($result['has_blocking_duplicate']);
    }

    /**
     * Test different gene doesn't trigger duplicate
     */
    public function test_different_gene_no_duplicate(): void
    {
        Submission::factory()->create([
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene2->id, // Different gene
            'original_disease_id' => $this->disease->id,
            'inheritance_id' => $this->inheritance->id,
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        $result = SubmissionDuplicateDetection::checkForDuplicates(
            $this->submitter->id,
            $this->gene->id,
            $this->disease->id,
            $this->inheritance->id
        );

        $this->assertFalse($result['has_blocking_duplicate']);
    }

    /**
     * Test different disease doesn't trigger duplicate
     */
    public function test_different_disease_no_duplicate(): void
    {
        Submission::factory()->create([
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene->id,
            'original_disease_id' => $this->disease2->id, // Different disease
            'inheritance_id' => $this->inheritance->id,
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        $result = SubmissionDuplicateDetection::checkForDuplicates(
            $this->submitter->id,
            $this->gene->id,
            $this->disease->id,
            $this->inheritance->id
        );

        $this->assertFalse($result['has_blocking_duplicate']);
    }

    /**
     * Test different inheritance doesn't trigger duplicate
     */
    public function test_different_inheritance_no_duplicate(): void
    {
        Submission::factory()->create([
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene->id,
            'original_disease_id' => $this->disease->id,
            'inheritance_id' => $this->inheritance2->id, // Different inheritance
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        $result = SubmissionDuplicateDetection::checkForDuplicates(
            $this->submitter->id,
            $this->gene->id,
            $this->disease->id,
            $this->inheritance->id
        );

        $this->assertFalse($result['has_blocking_duplicate']);
    }

    /**
     * Test batch duplicate detection - intra-batch duplicates
     */
    public function test_batch_intra_batch_duplicates(): void
    {
        $submissions = [
            [
                'gene_id' => $this->gene->id,
                'original_disease_id' => $this->disease->id,
                'inheritance_id' => $this->inheritance->id,
                'row_index' => 0,
            ],
            [
                'gene_id' => $this->gene->id,
                'original_disease_id' => $this->disease->id,
                'inheritance_id' => $this->inheritance->id,
                'row_index' => 1, // Duplicate of row 0
            ],
            [
                'gene_id' => $this->gene2->id, // Different gene
                'original_disease_id' => $this->disease->id,
                'inheritance_id' => $this->inheritance->id,
                'row_index' => 2,
            ],
        ];

        $results = SubmissionDuplicateDetection::checkForDuplicatesBatch(
            $this->submitter->id,
            $submissions
        );

        // Rows 0 and 1 should be flagged as batch duplicates
        $this->assertTrue($results[0]['has_batch_duplicate']);
        $this->assertEquals([1], $results[0]['batch_duplicate_rows']);

        $this->assertTrue($results[1]['has_batch_duplicate']);
        $this->assertEquals([0], $results[1]['batch_duplicate_rows']);

        // Row 2 should not be flagged
        $this->assertFalse($results[2]['has_batch_duplicate']);
    }

    /**
     * Test batch duplicate detection - existing submission duplicates
     */
    public function test_batch_existing_duplicates(): void
    {
        // Create existing published submission
        Submission::factory()->create([
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene->id,
            'original_disease_id' => $this->disease->id,
            'inheritance_id' => $this->inheritance->id,
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        $submissions = [
            [
                'gene_id' => $this->gene->id,
                'original_disease_id' => $this->disease->id,
                'inheritance_id' => $this->inheritance->id,
                'row_index' => 0,
            ],
            [
                'gene_id' => $this->gene2->id, // Different gene - no duplicate
                'original_disease_id' => $this->disease->id,
                'inheritance_id' => $this->inheritance->id,
                'row_index' => 1,
            ],
        ];

        $results = SubmissionDuplicateDetection::checkForDuplicatesBatch(
            $this->submitter->id,
            $submissions
        );

        // Row 0 should have blocking duplicate
        $this->assertTrue($results[0]['has_blocking_duplicate']);
        $this->assertFalse($results[0]['has_batch_duplicate']);

        // Row 1 should not have any duplicates
        $this->assertFalse($results[1]['has_blocking_duplicate']);
        $this->assertFalse($results[1]['has_batch_duplicate']);
    }

    /**
     * Test batch duplicate detection with self-exclusion
     */
    public function test_batch_with_self_exclusion(): void
    {
        // Create existing submission (for republish scenario)
        $existingSubmission = Submission::factory()->create([
            'submitter_id' => $this->submitter->id,
            'gene_id' => $this->gene->id,
            'original_disease_id' => $this->disease->id,
            'inheritance_id' => $this->inheritance->id,
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        $submissions = [
            [
                'gene_id' => $this->gene->id,
                'original_disease_id' => $this->disease->id,
                'inheritance_id' => $this->inheritance->id,
                'row_index' => 0,
                'exclude_submission_id' => $existingSubmission->id, // Republishing this one
            ],
        ];

        $results = SubmissionDuplicateDetection::checkForDuplicatesBatch(
            $this->submitter->id,
            $submissions
        );

        // Should not flag as duplicate since we're excluding self
        $this->assertFalse($results[0]['has_blocking_duplicate']);
    }

    /**
     * Test error message formatting for blocking duplicate
     */
    public function test_format_blocking_error_message(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        $message = SubmissionDuplicateDetection::formatBlockingErrorMessage($submission);

        $this->assertStringContainsString($submission->sid, $message);
        $this->assertStringContainsString('Published', $message);
        $this->assertStringContainsString('Duplicate submission found', $message);
    }

    /**
     * Test warning message formatting for unpublished duplicate
     */
    public function test_format_unpublished_warning_message(): void
    {
        $submission1 = Submission::factory()->create([
            'status' => Submission::STATUS_UNPUBLISHED,
        ]);
        $submission2 = Submission::factory()->create([
            'status' => Submission::STATUS_UNPUBLISHED,
        ]);

        $duplicates = collect([$submission1, $submission2]);
        $message = SubmissionDuplicateDetection::formatUnpublishedWarningMessage($duplicates);

        $this->assertStringContainsString($submission1->sid, $message);
        $this->assertStringContainsString($submission2->sid, $message);
        $this->assertStringContainsString('unpublished submission exists', $message);
    }

    /**
     * Test batch duplicate message formatting
     */
    public function test_format_batch_duplicate_message(): void
    {
        // Row numbers are already 1-indexed from the spreadsheet
        $otherRows = [13, 25, 100];

        $message = SubmissionDuplicateDetection::formatBatchDuplicateMessage($otherRows);

        // Should display rows as-is (already 1-indexed)
        $this->assertStringContainsString('13', $message);
        $this->assertStringContainsString('25', $message);
        $this->assertStringContainsString('100', $message);
        $this->assertStringContainsString('Duplicate found within this file', $message);
    }

    /**
     * Test grouped batch duplicate message formatting
     */
    public function test_format_grouped_batch_duplicate_message(): void
    {
        // Two groups of duplicate rows
        $duplicateGroups = [
            [2067, 3377],
            [2205, 3375, 3376]
        ];

        $message = SubmissionDuplicateDetection::formatGroupedBatchDuplicateMessage($duplicateGroups);

        // Should show grouped rows in parentheses
        $this->assertStringContainsString('(2067, 3377)', $message);
        $this->assertStringContainsString('(2205, 3375, 3376)', $message);
        $this->assertStringContainsString('Duplicate rows within file', $message);
    }

    /**
     * Test all blocking statuses are correctly identified
     */
    public function test_all_blocking_statuses(): void
    {
        $blockingStatuses = SubmissionDuplicateDetection::getBlockingStatuses();

        $this->assertContains(Submission::STATUS_PUBLISHED, $blockingStatuses);
        $this->assertContains(Submission::STATUS_DRAFT_NEW, $blockingStatuses);
        $this->assertContains(Submission::STATUS_DRAFT_REPUBLISH, $blockingStatuses);
        $this->assertContains(Submission::STATUS_DRAFT_UNPUBLISH, $blockingStatuses);
        $this->assertContains(Submission::STATUS_SUBMITTED_NEW, $blockingStatuses);
        $this->assertContains(Submission::STATUS_SUBMITTED_REPUBLISH, $blockingStatuses);
        $this->assertContains(Submission::STATUS_SUBMITTED_UNPUBLISH, $blockingStatuses);
    }

    /**
     * Test warning statuses are correctly identified
     */
    public function test_warning_statuses(): void
    {
        $warningStatuses = SubmissionDuplicateDetection::getWarningStatuses();

        $this->assertContains(Submission::STATUS_UNPUBLISHED, $warningStatuses);
        $this->assertCount(1, $warningStatuses);
    }
}
