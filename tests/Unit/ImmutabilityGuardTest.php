<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Submission;
use App\Models\Job;
use App\Services\SubmissionStateMachine;
use App\Services\JobStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Tests for immutability guards on published, unpublished, and submitted records.
 *
 * These guards ensure that submissions and jobs in released or submitted states
 * cannot have their data fields modified, while still allowing status transitions
 * by the release process.
 */
class ImmutabilityGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Always reset bypass flags after each test
        Submission::$bypassImmutability = false;
        Job::$bypassImmutability = false;
        parent::tearDown();
    }

    // -------------------------------------------------------
    // Submission Model Guard Tests
    // -------------------------------------------------------

    public function test_published_submission_cannot_change_gene_id(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot modify immutable submission/');

        $submission->gene_id = 999;
        $submission->save();
    }

    public function test_published_submission_cannot_change_disease_id(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        $this->expectException(\RuntimeException::class);

        $submission->disease_id = 999;
        $submission->save();
    }

    public function test_published_submission_cannot_change_classification_id(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        $this->expectException(\RuntimeException::class);

        $submission->classification_id = 999;
        $submission->save();
    }

    public function test_published_submission_cannot_change_submission_data(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        $this->expectException(\RuntimeException::class);

        $submission->submission_data = (object) ['test' => 'changed'];
        $submission->save();
    }

    public function test_unpublished_submission_cannot_change_gene_id(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_UNPUBLISHED,
        ]);

        $this->expectException(\RuntimeException::class);

        $submission->gene_id = 999;
        $submission->save();
    }

    public function test_submission_in_submitted_job_cannot_change_gene_id(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_SUBMITTED,
        ]);

        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_NEW,
            'job_id' => $job->id,
        ]);

        $this->expectException(\RuntimeException::class);

        $submission->gene_id = 999;
        $submission->save();
    }

    public function test_published_submission_allows_status_change(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        // The state machine transition (republish) is allowed
        // Only changing status field — should pass the guard
        $submission->status = Submission::STATUS_REPUBLISH;
        $submission->save();

        $this->assertEquals(Submission::STATUS_REPUBLISH, $submission->fresh()->status);
    }

    public function test_published_submission_allows_is_most_recent_change(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'is_most_recent' => true,
        ]);

        $submission->is_most_recent = false;
        $submission->is_live = false;
        $submission->save();

        $this->assertFalse($submission->fresh()->is_most_recent);
    }

    public function test_published_submission_allows_submission_errors_change(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        $submission->submission_errors = (object) ['publish_error' => 'test error'];
        $submission->save();

        $this->assertNotNull($submission->fresh()->submission_errors);
    }

    public function test_draft_submission_can_change_any_field(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_NEW,
        ]);

        $submission->gene_id = 999;
        $submission->disease_id = 999;
        $submission->classification_id = 999;
        $submission->save();

        $fresh = $submission->fresh();
        $this->assertEquals(999, $fresh->gene_id);
        $this->assertEquals(999, $fresh->disease_id);
        $this->assertEquals(999, $fresh->classification_id);
    }

    public function test_bypass_flag_allows_published_submission_changes(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
        ]);

        Submission::$bypassImmutability = true;

        $submission->gene_id = 999;
        $submission->save();

        $this->assertEquals(999, $submission->fresh()->gene_id);
    }

    // -------------------------------------------------------
    // Job Model Guard Tests
    // -------------------------------------------------------

    public function test_submitted_job_cannot_change_friendly_name(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_SUBMITTED,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot modify immutable job/');

        $job->friendly = 'new name';
        $job->save();
    }

    public function test_released_job_cannot_change_friendly_name(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_RELEASED,
        ]);

        $this->expectException(\RuntimeException::class);

        $job->friendly = 'new name';
        $job->save();
    }

    public function test_submitted_job_allows_status_change(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_SUBMITTED,
        ]);

        $job->status = Job::STATUS_RELEASED;
        $job->save();

        $this->assertEquals(Job::STATUS_RELEASED, $job->fresh()->status);
    }

    public function test_submitted_job_allows_is_publishing_change(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_SUBMITTED,
        ]);

        $job->is_publishing = true;
        $job->save();

        $this->assertTrue($job->fresh()->is_publishing);
    }

    public function test_submitted_job_allows_processed_submission_ids_change(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_SUBMITTED,
        ]);

        $job->processed_submission_ids = [['sid' => 'SGC-100001', 'action' => 'published']];
        $job->save();

        $this->assertNotNull($job->fresh()->processed_submission_ids);
    }

    public function test_draft_job_can_change_any_field(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_DRAFT,
        ]);

        $job->friendly = 'test name';
        $job->save();

        $this->assertEquals('test name', $job->fresh()->friendly);
    }

    public function test_bypass_flag_allows_submitted_job_changes(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_SUBMITTED,
        ]);

        Job::$bypassImmutability = true;

        $job->friendly = 'bypass test';
        $job->save();

        $this->assertEquals('bypass test', $job->fresh()->friendly);
    }

    // -------------------------------------------------------
    // State Machine isImmutable() Tests
    // -------------------------------------------------------

    public function test_submission_state_machine_is_immutable_published(): void
    {
        $this->assertTrue(SubmissionStateMachine::isImmutable(Submission::STATUS_PUBLISHED));
    }

    public function test_submission_state_machine_is_immutable_unpublished(): void
    {
        $this->assertTrue(SubmissionStateMachine::isImmutable(Submission::STATUS_UNPUBLISHED));
    }

    public function test_submission_state_machine_not_immutable_new(): void
    {
        $this->assertFalse(SubmissionStateMachine::isImmutable(Submission::STATUS_NEW));
    }

    public function test_submission_state_machine_not_immutable_republish(): void
    {
        $this->assertFalse(SubmissionStateMachine::isImmutable(Submission::STATUS_REPUBLISH));
    }

    public function test_submission_state_machine_is_immutable_with_submitted_job(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_SUBMITTED,
        ]);

        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_NEW,
            'job_id' => $job->id,
        ]);

        $this->assertTrue(SubmissionStateMachine::isImmutable($submission));
    }

    public function test_submission_state_machine_not_immutable_with_draft_job(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_DRAFT,
        ]);

        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_NEW,
            'job_id' => $job->id,
        ]);

        $this->assertFalse(SubmissionStateMachine::isImmutable($submission));
    }

    public function test_job_state_machine_is_immutable_submitted(): void
    {
        $this->assertTrue(JobStateMachine::isImmutable(Job::STATUS_SUBMITTED));
    }

    public function test_job_state_machine_is_immutable_released(): void
    {
        $this->assertTrue(JobStateMachine::isImmutable(Job::STATUS_RELEASED));
    }

    public function test_job_state_machine_not_immutable_draft(): void
    {
        $this->assertFalse(JobStateMachine::isImmutable(Job::STATUS_DRAFT));
    }
}
