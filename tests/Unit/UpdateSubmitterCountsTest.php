<?php

namespace Tests\Unit;

use App\Models\Classification;
use App\Models\Disease;
use App\Models\Gene;
use App\Models\Job;
use App\Models\Submission;
use App\Models\Submitter;
use App\Services\CountsUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for CountsUpdater::updateSubmitterCounts() to verify that
 * submitter curation counts are computed correctly.
 *
 * Key scenarios:
 * - Only published + is_live submissions are counted
 * - Unpublished submissions (even if is_live) are excluded
 * - Non-live submissions are excluded
 * - Counts are grouped correctly by classification
 * - Submitters with no qualifying submissions get cleared counts
 * - Multiple submitters are counted independently
 */
class UpdateSubmitterCountsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a submission with specific status and is_live values.
     */
    protected function createSubmission(
        Submitter $submitter,
        Classification $classification,
        string $status,
        bool $isLive,
        ?Gene $gene = null,
        ?Disease $disease = null,
    ): Submission {
        $gene = $gene ?? Gene::factory()->create();
        $disease = $disease ?? Disease::factory()->create();
        $job = Job::factory()->create(['submitter_id' => $submitter->id]);

        return Submission::factory()->create([
            'submitter_id' => $submitter->id,
            'classification_id' => $classification->id,
            'gene_id' => $gene->id,
            'disease_id' => $disease->id,
            'job_id' => $job->id,
            'user_id' => $job->user_id,
            'status' => $status,
            'is_live' => $isLive,
        ]);
    }

    /**
     * Decode the raw counts JSON from a submitter.
     */
    protected function getCounts(Submitter $submitter): ?array
    {
        $submitter->refresh();
        $raw = $submitter->getRawOriginal('counts');
        return ($raw === '[]') ? null : json_decode($raw, true);
    }

    // =========================================================================
    // Core Filtering Tests
    // =========================================================================

    /** @test */
    public function it_counts_published_live_submissions()
    {
        $submitter = Submitter::factory()->create();
        $classification = Classification::factory()->create();

        $this->createSubmission($submitter, $classification, Submission::STATUS_PUBLISHED, true);
        $this->createSubmission($submitter, $classification, Submission::STATUS_PUBLISHED, true);

        CountsUpdater::updateSubmitterCounts();

        $counts = $this->getCounts($submitter);

        $this->assertEquals(2, $counts['total']);
        $this->assertEquals(2, $counts['by_classification'][$classification->name]['count']);
    }

    /** @test */
    public function it_excludes_unpublished_live_submissions()
    {
        $submitter = Submitter::factory()->create();
        $classification = Classification::factory()->create();

        // One published, one unpublished — both is_live
        $this->createSubmission($submitter, $classification, Submission::STATUS_PUBLISHED, true);
        $this->createSubmission($submitter, $classification, Submission::STATUS_UNPUBLISHED, true);

        CountsUpdater::updateSubmitterCounts();

        $counts = $this->getCounts($submitter);

        $this->assertEquals(1, $counts['total']);
        $this->assertEquals(1, $counts['by_classification'][$classification->name]['count']);
    }

    /** @test */
    public function it_excludes_non_live_published_submissions()
    {
        $submitter = Submitter::factory()->create();
        $classification = Classification::factory()->create();

        // Published but not is_live (superseded by a newer version)
        $this->createSubmission($submitter, $classification, Submission::STATUS_PUBLISHED, false);

        CountsUpdater::updateSubmitterCounts();

        $this->assertNull($this->getCounts($submitter));
    }

    /** @test */
    public function it_excludes_non_published_submissions()
    {
        $submitter = Submitter::factory()->create();
        $classification = Classification::factory()->create();

        $this->createSubmission($submitter, $classification, Submission::STATUS_DRAFT_NEW, true);
        $this->createSubmission($submitter, $classification, Submission::STATUS_SUBMITTED_NEW, true);

        CountsUpdater::updateSubmitterCounts();

        $this->assertNull($this->getCounts($submitter));
    }

    // =========================================================================
    // Grouping Tests
    // =========================================================================

    /** @test */
    public function it_groups_counts_by_classification()
    {
        $submitter = Submitter::factory()->create();
        $definitive = Classification::factory()->create(['name' => 'Definitive', 'abbreviation' => 'DEF']);
        $strong = Classification::factory()->create(['name' => 'Strong', 'abbreviation' => 'STR']);

        $this->createSubmission($submitter, $definitive, Submission::STATUS_PUBLISHED, true);
        $this->createSubmission($submitter, $definitive, Submission::STATUS_PUBLISHED, true);
        $this->createSubmission($submitter, $strong, Submission::STATUS_PUBLISHED, true);

        CountsUpdater::updateSubmitterCounts();

        $counts = $this->getCounts($submitter);

        $this->assertEquals(3, $counts['total']);
        $this->assertEquals(2, $counts['by_classification']['Definitive']['count']);
        $this->assertEquals('DEF', $counts['by_classification']['Definitive']['abbreviation']);
        $this->assertEquals(1, $counts['by_classification']['Strong']['count']);
        $this->assertEquals('STR', $counts['by_classification']['Strong']['abbreviation']);
    }

    /** @test */
    public function it_counts_submitters_independently()
    {
        $submitterA = Submitter::factory()->create();
        $submitterB = Submitter::factory()->create();
        $classification = Classification::factory()->create();

        $this->createSubmission($submitterA, $classification, Submission::STATUS_PUBLISHED, true);
        $this->createSubmission($submitterA, $classification, Submission::STATUS_PUBLISHED, true);
        $this->createSubmission($submitterB, $classification, Submission::STATUS_PUBLISHED, true);

        CountsUpdater::updateSubmitterCounts();

        $countsA = $this->getCounts($submitterA);
        $countsB = $this->getCounts($submitterB);

        $this->assertEquals(2, $countsA['total']);
        $this->assertEquals(1, $countsB['total']);
    }

    // =========================================================================
    // Clearing Tests
    // =========================================================================

    /** @test */
    public function it_clears_counts_for_submitters_with_no_qualifying_submissions()
    {
        $submitterWithData = Submitter::factory()->create();
        $submitterWithout = Submitter::factory()->create();
        // Set stale counts directly to avoid cast double-encoding
        Submitter::where('id', $submitterWithout->id)
            ->update(['counts' => json_encode(['total' => 5, 'by_classification' => ['Old' => ['count' => 5]]])]);

        $classification = Classification::factory()->create();
        $this->createSubmission($submitterWithData, $classification, Submission::STATUS_PUBLISHED, true);

        CountsUpdater::updateSubmitterCounts();

        $this->assertNull($this->getCounts($submitterWithout));
    }

    /** @test */
    public function it_clears_counts_when_all_submissions_become_unpublished()
    {
        $submitter = Submitter::factory()->create();
        $classification = Classification::factory()->create();
        $gene = Gene::factory()->create();
        $disease = Disease::factory()->create();

        // Create a published+live submission so the submitter gets counts
        $pub = $this->createSubmission($submitter, $classification, Submission::STATUS_PUBLISHED, true, $gene, $disease);

        CountsUpdater::updateSubmitterCounts();
        $counts = $this->getCounts($submitter);
        $this->assertEquals(1, $counts['total'], 'Precondition: submitter should have counts');

        // Now unpublish it (simulate the real workflow)
        $pub->update(['status' => Submission::STATUS_UNPUBLISHED]);

        // Need another submitter with data so the clearing branch executes
        $otherSubmitter = Submitter::factory()->create();
        $this->createSubmission($otherSubmitter, $classification, Submission::STATUS_PUBLISHED, true);

        CountsUpdater::updateSubmitterCounts();

        $this->assertNull($this->getCounts($submitter));
    }

    // =========================================================================
    // Mixed Scenario Tests
    // =========================================================================

    /** @test */
    public function it_handles_mix_of_statuses_and_live_flags()
    {
        $submitter = Submitter::factory()->create();
        $classification = Classification::factory()->create();

        // Should count: published + is_live
        $this->createSubmission($submitter, $classification, Submission::STATUS_PUBLISHED, true);
        $this->createSubmission($submitter, $classification, Submission::STATUS_PUBLISHED, true);

        // Should NOT count: unpublished + is_live
        $this->createSubmission($submitter, $classification, Submission::STATUS_UNPUBLISHED, true);

        // Should NOT count: published + not is_live (old version)
        $this->createSubmission($submitter, $classification, Submission::STATUS_PUBLISHED, false);

        // Should NOT count: draft + is_live
        $this->createSubmission($submitter, $classification, Submission::STATUS_DRAFT_NEW, true);

        CountsUpdater::updateSubmitterCounts();

        $counts = $this->getCounts($submitter);

        $this->assertEquals(2, $counts['total']);
        $this->assertEquals(2, $counts['by_classification'][$classification->name]['count']);
    }

    /** @test */
    public function it_handles_no_submissions_at_all()
    {
        $submitter = Submitter::factory()->create();

        // Should not throw — just a no-op
        $updated = CountsUpdater::updateSubmitterCounts();

        $this->assertEquals(0, $updated);
    }
}
