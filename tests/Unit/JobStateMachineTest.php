<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Job;
use App\Models\Submission;
use App\Services\JobStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

class JobStateMachineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test valid transition from draft to submitted
     */
    public function test_draft_to_submitted(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_DRAFT
        ]);

        $this->assertTrue(
            JobStateMachine::canTransition($job, Job::STATUS_SUBMITTED)
        );

        JobStateMachine::transition($job, Job::STATUS_SUBMITTED);

        $this->assertEquals(Job::STATUS_SUBMITTED, $job->status);
    }

    /**
     * Test valid transition from submitted to processed
     */
    public function test_submitted_to_processed(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_SUBMITTED
        ]);

        $this->assertTrue(
            JobStateMachine::canTransition($job, Job::STATUS_PROCESSED)
        );

        JobStateMachine::transition($job, Job::STATUS_PROCESSED);

        $this->assertEquals(Job::STATUS_PROCESSED, $job->status);
    }

    /**
     * Test cancel from submitted back to draft
     */
    public function test_cancel_submitted_to_draft(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_SUBMITTED
        ]);

        $this->assertTrue(
            JobStateMachine::canTransition($job, Job::STATUS_DRAFT)
        );

        JobStateMachine::transition($job, Job::STATUS_DRAFT);

        $this->assertEquals(Job::STATUS_DRAFT, $job->status);
    }

    /**
     * Test submit() method transitions job and sets submitted_at on submissions
     *
     * With the simplified status model, submission status does NOT change when
     * the job transitions from draft to submitted. Stage (draft/submitted) is
     * derived from Job.status. Only submitted_at timestamp is set.
     */
    public function test_submit_method_transitions_all_submissions(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_DRAFT
        ]);

        // Create submissions in different pending states (using new simplified statuses)
        $sub1 = Submission::factory()->create([
            'job_id' => $job->id,
            'status' => Submission::STATUS_NEW,
            'submitted_at' => null
        ]);

        $sub2 = Submission::factory()->create([
            'job_id' => $job->id,
            'status' => Submission::STATUS_REPUBLISH,
            'origin_state' => 'published',
            'submitted_at' => null
        ]);

        $sub3 = Submission::factory()->create([
            'job_id' => $job->id,
            'status' => Submission::STATUS_UNPUBLISH,
            'origin_state' => 'published',
            'submitted_at' => null
        ]);

        JobStateMachine::submit($job);

        // Job should be submitted
        $this->assertEquals(Job::STATUS_SUBMITTED, $job->status);

        // Refresh submissions
        $sub1->refresh();
        $sub2->refresh();
        $sub3->refresh();

        // With simplified model, status does NOT change (stage derived from Job)
        $this->assertEquals(Submission::STATUS_NEW, $sub1->status);
        $this->assertEquals(Submission::STATUS_REPUBLISH, $sub2->status);
        $this->assertEquals(Submission::STATUS_UNPUBLISH, $sub3->status);

        // But submitted_at should be set
        $this->assertNotNull($sub1->submitted_at);
        $this->assertNotNull($sub2->submitted_at);
        $this->assertNotNull($sub3->submitted_at);
    }

    /**
     * Test complete() method transitions job to processed
     */
    public function test_complete_method(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_SUBMITTED
        ]);

        JobStateMachine::complete($job);

        $this->assertEquals(Job::STATUS_PROCESSED, $job->status);
    }

    /**
     * Test invalid transition is blocked
     */
    public function test_invalid_transition_is_blocked(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_PROCESSED
        ]);

        $this->assertFalse(
            JobStateMachine::canTransition($job, Job::STATUS_DRAFT)
        );

        $this->expectException(\Exception::class);
        JobStateMachine::transition($job, Job::STATUS_DRAFT);
    }

    /**
     * Test draft job cannot transition to processed directly
     */
    public function test_draft_cannot_go_to_processed_directly(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_DRAFT
        ]);

        $this->assertFalse(
            JobStateMachine::canTransition($job, Job::STATUS_PROCESSED)
        );
    }

    /**
     * Test processed is terminal state
     */
    public function test_processed_is_terminal_state(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_PROCESSED
        ]);

        $this->assertFalse(
            JobStateMachine::canTransition($job, Job::STATUS_DRAFT)
        );

        $this->assertFalse(
            JobStateMachine::canTransition($job, Job::STATUS_SUBMITTED)
        );
    }

    /**
     * Test submitted job can stay submitted (on processing failure)
     */
    public function test_submitted_can_stay_submitted(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_SUBMITTED
        ]);

        $this->assertTrue(
            JobStateMachine::canTransition($job, Job::STATUS_SUBMITTED)
        );

        JobStateMachine::transition($job, Job::STATUS_SUBMITTED);

        $this->assertEquals(Job::STATUS_SUBMITTED, $job->status);
    }

    /**
     * Test submit() blocks if job has errors
     */
    public function test_submit_blocks_if_submissions_have_errors(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_DRAFT
        ]);

        // Create submission with errors (using new simplified status)
        Submission::factory()->create([
            'job_id' => $job->id,
            'status' => Submission::STATUS_NEW,
            'submission_errors' => ['gene_hgnc_id' => 'Invalid gene']
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot submit job with submissions that have errors');

        JobStateMachine::submit($job);
    }

    /**
     * Test submit() blocks if not all submissions are pending (draft state)
     *
     * Note: "draft state" now means "pending state" in the simplified model
     */
    public function test_submit_blocks_if_submissions_not_draft(): void
    {
        $job = Job::factory()->create([
            'status' => Job::STATUS_DRAFT
        ]);

        // Create submission that's already published (released, not pending)
        Submission::factory()->create([
            'job_id' => $job->id,
            'status' => Submission::STATUS_PUBLISHED
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Can only submit jobs where all submissions are in draft states');

        JobStateMachine::submit($job);
    }

    /**
     * Test isTerminal helper method
     */
    public function test_is_terminal_helper(): void
    {
        $draft = Job::factory()->create(['status' => Job::STATUS_DRAFT]);
        $submitted = Job::factory()->create(['status' => Job::STATUS_SUBMITTED]);
        $processed = Job::factory()->create(['status' => Job::STATUS_PROCESSED]);

        $this->assertFalse(JobStateMachine::isTerminal($draft));
        $this->assertFalse(JobStateMachine::isTerminal($submitted));
        $this->assertTrue(JobStateMachine::isTerminal($processed));
    }
}
