<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Pubmed;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Unit tests for the pubmed:status artisan command
 */
class StatusPubmedCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test command runs successfully with no pubmed records
     */
    public function test_command_runs_with_no_records(): void
    {
        $this->artisan('pubmed:status')
            ->assertSuccessful()
            ->expectsOutputToContain('PubMed Status Report')
            ->expectsOutputToContain('All PubMed records are up to date');
    }

    /**
     * Test command shows correct count of synced records
     */
    public function test_command_shows_synced_count(): void
    {
        // Create some synced pubmed records
        Pubmed::create([
            'pmid' => '11111111',
            'uid' => '11111111',
            'status' => Pubmed::STATUS_SUMMARY_COMPLETE
        ]);
        Pubmed::create([
            'pmid' => '22222222',
            'uid' => '22222222',
            'status' => Pubmed::STATUS_SUMMARY_COMPLETE
        ]);

        $this->artisan('pubmed:status')
            ->assertSuccessful()
            ->expectsOutputToContain('Summary Complete: 2')
            ->expectsOutputToContain('All PubMed records are up to date');
    }

    /**
     * Test command shows pending records
     */
    public function test_command_shows_pending_records(): void
    {
        // Create a pending pubmed record
        Pubmed::create([
            'pmid' => '33333333',
            'uid' => '33333333',
            'status' => Pubmed::STATUS_INITIALIZING
        ]);

        $this->artisan('pubmed:status')
            ->assertExitCode(1)  // Returns 1 when there are pending PMIDs
            ->expectsOutputToContain('Initializing (needs fetch): 1')
            ->expectsOutputToContain('1 PMID(s) need to be fetched from NCBI');
    }

    /**
     * Test command shows affected submissions count
     */
    public function test_command_shows_affected_submissions(): void
    {
        // Create a pending pubmed record
        $pubmed = Pubmed::create([
            'pmid' => '44444444',
            'uid' => '44444444',
            'status' => Pubmed::STATUS_INITIALIZING
        ]);

        // Create submissions and link them to the pubmed
        $submission1 = Submission::factory()->create();
        $submission2 = Submission::factory()->create();

        $pubmed->submissions()->attach([$submission1->id, $submission2->id]);

        $this->artisan('pubmed:status')
            ->assertExitCode(1)
            ->expectsOutputToContain('Submissions with pending PMIDs: 2');
    }

    /**
     * Test command with --details option shows PMID list
     */
    public function test_command_with_details_shows_pmid_list(): void
    {
        Pubmed::create([
            'pmid' => '55555555',
            'uid' => '55555555',
            'status' => Pubmed::STATUS_INITIALIZING
        ]);

        $this->artisan('pubmed:status', ['--details' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('Pending PMIDs:')
            ->expectsOutputToContain('55555555');
    }

    /**
     * Test command with --details shows affected submission SIDs
     */
    public function test_command_with_details_shows_submission_sids(): void
    {
        $pubmed = Pubmed::create([
            'pmid' => '66666666',
            'uid' => '66666666',
            'status' => Pubmed::STATUS_INITIALIZING
        ]);

        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED
        ]);

        $pubmed->submissions()->attach($submission->id);

        $this->artisan('pubmed:status', ['--details' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('Affected Submissions:')
            ->expectsOutputToContain($submission->sid);
    }

    /**
     * Test command shows sync instructions when there are pending PMIDs
     */
    public function test_command_shows_sync_instructions(): void
    {
        Pubmed::create([
            'pmid' => '77777777',
            'uid' => '77777777',
            'status' => Pubmed::STATUS_INITIALIZING
        ]);

        $this->artisan('pubmed:status')
            ->assertExitCode(1)
            ->expectsOutputToContain('php artisan pubmed:sync');
    }

    /**
     * Test command returns exit code 0 when all synced
     */
    public function test_command_returns_0_when_all_synced(): void
    {
        Pubmed::create([
            'pmid' => '88888888',
            'uid' => '88888888',
            'status' => Pubmed::STATUS_SUMMARY_COMPLETE
        ]);

        $this->artisan('pubmed:status')
            ->assertExitCode(0);
    }

    /**
     * Test command returns exit code 1 when PMIDs are pending
     */
    public function test_command_returns_1_when_pmids_pending(): void
    {
        Pubmed::create([
            'pmid' => '99999999',
            'uid' => '99999999',
            'status' => Pubmed::STATUS_INITIALIZING
        ]);

        $this->artisan('pubmed:status')
            ->assertExitCode(1);
    }

    /**
     * Test command shows total count
     */
    public function test_command_shows_total_count(): void
    {
        // Mix of statuses
        Pubmed::create([
            'pmid' => '11111111',
            'uid' => '11111111',
            'status' => Pubmed::STATUS_SUMMARY_COMPLETE
        ]);
        Pubmed::create([
            'pmid' => '22222222',
            'uid' => '22222222',
            'status' => Pubmed::STATUS_INITIALIZING
        ]);

        $this->artisan('pubmed:status')
            ->expectsOutputToContain('Total: 2');
    }
}
