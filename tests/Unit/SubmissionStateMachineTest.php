<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Submission;
use App\Models\Job;
use App\Services\SubmissionStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SubmissionStateMachineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test valid transition from draft_new to submitted_new
     */
    public function test_draft_new_to_submitted_new(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_NEW
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_SUBMITTED_NEW)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_SUBMITTED_NEW);

        $this->assertEquals(Submission::STATUS_SUBMITTED_NEW, $submission->status);
    }

    /**
     * Test valid transition from submitted_new to published
     */
    public function test_submitted_new_to_published(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_SUBMITTED_NEW
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_PUBLISHED)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_PUBLISHED);

        $this->assertEquals(Submission::STATUS_PUBLISHED, $submission->status);
    }

    /**
     * Test valid transition from published to draft_republish
     * Note: With versioning, a NEW submission record is created for republish.
     * The state machine only handles status transitions - version creation is handled
     * by the controller.
     */
    public function test_published_to_draft_republish(): void
    {
        $originalJob = Job::factory()->create();
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'job_id' => $originalJob->id,
            'gene_id' => 1,
            'disease_id' => 1
        ]);

        $newJob = Job::factory()->create([
            'status' => Job::STATUS_DRAFT
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_DRAFT_REPUBLISH)
        );

        $submission->job_id = $newJob->id;
        SubmissionStateMachine::transition($submission, Submission::STATUS_DRAFT_REPUBLISH);

        $this->assertEquals(Submission::STATUS_DRAFT_REPUBLISH, $submission->status);
    }

    /**
     * Test valid transition from draft_republish to submitted_republish
     */
    public function test_draft_republish_to_submitted_republish(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_REPUBLISH
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_SUBMITTED_REPUBLISH)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_SUBMITTED_REPUBLISH);

        $this->assertEquals(Submission::STATUS_SUBMITTED_REPUBLISH, $submission->status);
    }

    /**
     * Test valid transition from submitted_republish to published
     */
    public function test_submitted_republish_to_published(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_SUBMITTED_REPUBLISH
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_PUBLISHED)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_PUBLISHED);

        $this->assertEquals(Submission::STATUS_PUBLISHED, $submission->status);
    }

    /**
     * Test transition from draft_republish back to published (cancel scenario)
     * Note: With versioning, cancelling deletes the draft version and restores
     * is_most_recent on the original. This test validates the state transition only.
     */
    public function test_cancel_draft_republish(): void
    {
        $draftJob = Job::factory()->create(['status' => Job::STATUS_DRAFT]);

        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_REPUBLISH,
            'job_id' => $draftJob->id
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_PUBLISHED)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_PUBLISHED);

        $this->assertEquals(Submission::STATUS_PUBLISHED, $submission->status);
    }

    /**
     * Test valid transition from published to draft_unpublish
     * Note: With versioning, a NEW submission record is created for unpublish.
     */
    public function test_published_to_draft_unpublish(): void
    {
        $originalJob = Job::factory()->create();
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'job_id' => $originalJob->id
        ]);

        $newJob = Job::factory()->create(['status' => Job::STATUS_DRAFT]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_DRAFT_UNPUBLISH)
        );

        $submission->job_id = $newJob->id;
        SubmissionStateMachine::transition($submission, Submission::STATUS_DRAFT_UNPUBLISH);

        $this->assertEquals(Submission::STATUS_DRAFT_UNPUBLISH, $submission->status);
    }

    /**
     * Test valid transition from draft_unpublish to submitted_unpublish
     */
    public function test_draft_unpublish_to_submitted_unpublish(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_UNPUBLISH
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_SUBMITTED_UNPUBLISH)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_SUBMITTED_UNPUBLISH);

        $this->assertEquals(Submission::STATUS_SUBMITTED_UNPUBLISH, $submission->status);
    }

    /**
     * Test valid transition from submitted_unpublish to unpublished
     */
    public function test_submitted_unpublish_to_unpublished(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_SUBMITTED_UNPUBLISH
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_UNPUBLISHED)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_UNPUBLISHED);

        $this->assertEquals(Submission::STATUS_UNPUBLISHED, $submission->status);
    }

    /**
     * Test valid transition from unpublished to draft_republish
     * Note: With versioning, a NEW submission record is created for republish.
     */
    public function test_unpublished_to_draft_republish(): void
    {
        $originalJob = Job::factory()->create();
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_UNPUBLISHED,
            'job_id' => $originalJob->id
        ]);

        $newJob = Job::factory()->create(['status' => Job::STATUS_DRAFT]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_DRAFT_REPUBLISH)
        );

        $submission->job_id = $newJob->id;
        SubmissionStateMachine::transition($submission, Submission::STATUS_DRAFT_REPUBLISH);

        $this->assertEquals(Submission::STATUS_DRAFT_REPUBLISH, $submission->status);
    }

    /**
     * Test cancel from draft_republish back to unpublished
     * Note: With versioning, the draft version is deleted and original submission restored.
     */
    public function test_cancel_draft_republish_from_unpublished(): void
    {
        $draftJob = Job::factory()->create(['status' => Job::STATUS_DRAFT]);

        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_REPUBLISH,
            'job_id' => $draftJob->id
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_UNPUBLISHED)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_UNPUBLISHED);

        $this->assertEquals(Submission::STATUS_UNPUBLISHED, $submission->status);
    }

    /**
     * Test invalid transition is blocked
     */
    public function test_invalid_transition_is_blocked(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED
        ]);

        $this->assertFalse(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_SUBMITTED_NEW)
        );

        $this->expectException(\Exception::class);
        SubmissionStateMachine::transition($submission, Submission::STATUS_SUBMITTED_NEW);
    }

    /**
     * Test draft_new cannot transition to unpublished
     */
    public function test_draft_new_cannot_unpublish(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_NEW
        ]);

        $this->assertFalse(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_UNPUBLISHED)
        );
    }

    /**
     * Test isDraft helper method
     */
    public function test_is_draft_helper(): void
    {
        $draftNew = Submission::factory()->create(['status' => Submission::STATUS_DRAFT_NEW]);
        $draftRepublish = Submission::factory()->create(['status' => Submission::STATUS_DRAFT_REPUBLISH]);
        $draftUnpublish = Submission::factory()->create(['status' => Submission::STATUS_DRAFT_UNPUBLISH]);
        $published = Submission::factory()->create(['status' => Submission::STATUS_PUBLISHED]);

        $this->assertTrue(SubmissionStateMachine::isDraft($draftNew));
        $this->assertTrue(SubmissionStateMachine::isDraft($draftRepublish));
        $this->assertTrue(SubmissionStateMachine::isDraft($draftUnpublish));
        $this->assertFalse(SubmissionStateMachine::isDraft($published));
    }

    /**
     * Test isSubmitted helper method
     */
    public function test_is_submitted_helper(): void
    {
        $submittedNew = Submission::factory()->create(['status' => Submission::STATUS_SUBMITTED_NEW]);
        $submittedRepublish = Submission::factory()->create(['status' => Submission::STATUS_SUBMITTED_REPUBLISH]);
        $submittedUnpublish = Submission::factory()->create(['status' => Submission::STATUS_SUBMITTED_UNPUBLISH]);
        $draft = Submission::factory()->create(['status' => Submission::STATUS_DRAFT_NEW]);

        $this->assertTrue(SubmissionStateMachine::isSubmitted($submittedNew));
        $this->assertTrue(SubmissionStateMachine::isSubmitted($submittedRepublish));
        $this->assertTrue(SubmissionStateMachine::isSubmitted($submittedUnpublish));
        $this->assertFalse(SubmissionStateMachine::isSubmitted($draft));
    }
}
