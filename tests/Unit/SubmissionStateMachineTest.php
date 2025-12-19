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
        $this->assertNull($submission->origin_state);
        $this->assertNull($submission->origin_snapshot);
        $this->assertNull($submission->origin_job_id);
    }

    /**
     * Test valid transition from published to draft_republish with origin tracking
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
        $this->assertEquals('published', $submission->origin_state);
        $this->assertEquals($originalJob->id, $submission->origin_job_id);
        $this->assertNotNull($submission->origin_snapshot);
        $this->assertIsArray($submission->origin_snapshot);
    }

    /**
     * Test valid transition from draft_republish to submitted_republish
     */
    public function test_draft_republish_to_submitted_republish(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_REPUBLISH,
            'origin_state' => 'published'
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
            'status' => Submission::STATUS_SUBMITTED_REPUBLISH,
            'origin_state' => 'published',
            'origin_job_id' => 1
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_PUBLISHED)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_PUBLISHED);

        $this->assertEquals(Submission::STATUS_PUBLISHED, $submission->status);
        // Origin data should be cleared after successful republish
        $this->assertNull($submission->origin_state);
        $this->assertNull($submission->origin_job_id);
    }

    /**
     * Test cancel from draft_republish back to published
     */
    public function test_cancel_draft_republish_to_published(): void
    {
        $originalJob = Job::factory()->create();
        $draftJob = Job::factory()->create(['status' => Job::STATUS_DRAFT]);

        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_REPUBLISH,
            'origin_state' => 'published',
            'origin_job_id' => $originalJob->id,
            'job_id' => $draftJob->id,
            'origin_snapshot' => [
                'gene_id' => 1,
                'disease_id' => 1,
                'local_key' => 'TEST-001'
            ]
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_PUBLISHED)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_PUBLISHED);

        $this->assertEquals(Submission::STATUS_PUBLISHED, $submission->status);
        $this->assertEquals($originalJob->id, $submission->job_id);
        $this->assertNull($submission->origin_state);
        $this->assertNull($submission->origin_job_id);
        $this->assertNull($submission->origin_snapshot);
    }

    /**
     * Test valid transition from published to draft_unpublish
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
        $this->assertEquals('published', $submission->origin_state);
        $this->assertEquals($originalJob->id, $submission->origin_job_id);
    }

    /**
     * Test valid transition from draft_unpublish to submitted_unpublish
     */
    public function test_draft_unpublish_to_submitted_unpublish(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_UNPUBLISH,
            'origin_state' => 'published'
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
        $this->assertNull($submission->origin_state);
        $this->assertNull($submission->origin_job_id);
    }

    /**
     * Test valid transition from unpublished to draft_republish
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
        $this->assertEquals('unpublished', $submission->origin_state);
        $this->assertEquals($originalJob->id, $submission->origin_job_id);
    }

    /**
     * Test cancel from draft_republish back to unpublished
     */
    public function test_cancel_draft_republish_to_unpublished(): void
    {
        $originalJob = Job::factory()->create();
        $draftJob = Job::factory()->create(['status' => Job::STATUS_DRAFT]);

        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_REPUBLISH,
            'origin_state' => 'unpublished',
            'origin_job_id' => $originalJob->id,
            'job_id' => $draftJob->id
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_UNPUBLISHED)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_UNPUBLISHED);

        $this->assertEquals(Submission::STATUS_UNPUBLISHED, $submission->status);
        $this->assertEquals($originalJob->id, $submission->job_id);
        $this->assertNull($submission->origin_state);
        $this->assertNull($submission->origin_job_id);
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
     * Test origin_snapshot captures field values
     */
    public function test_origin_snapshot_captures_values(): void
    {
        $job = Job::factory()->create();
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED,
            'job_id' => $job->id,
            'gene_id' => 123,
            'disease_id' => 456,
            'local_key' => 'TEST-KEY',
            'submission_data' => ['test' => 'data']
        ]);

        $newJob = Job::factory()->create(['status' => Job::STATUS_DRAFT]);
        $submission->job_id = $newJob->id;
        SubmissionStateMachine::transition($submission, Submission::STATUS_DRAFT_REPUBLISH);

        $snapshot = $submission->origin_snapshot;
        $this->assertEquals(123, $snapshot['gene_id']);
        $this->assertEquals(456, $snapshot['disease_id']);
        $this->assertEquals('TEST-KEY', $snapshot['local_key']);
        $this->assertEquals(['test' => 'data'], $snapshot['submission_data']);
    }

    /**
     * Test restoreFromSnapshot restores field values
     */
    public function test_restore_from_snapshot_restores_values(): void
    {
        $originalJob = Job::factory()->create();
        $draftJob = Job::factory()->create(['status' => Job::STATUS_DRAFT]);

        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_DRAFT_REPUBLISH,
            'origin_state' => 'published',
            'origin_job_id' => $originalJob->id,
            'job_id' => $draftJob->id,
            'gene_id' => 999,
            'disease_id' => 888,
            'local_key' => 'CHANGED',
            'origin_snapshot' => [
                'gene_id' => 123,
                'disease_id' => 456,
                'local_key' => 'ORIGINAL',
                'submission_data' => ['original' => 'value']
            ]
        ]);

        $submission->restoreFromSnapshot();

        $this->assertEquals(123, $submission->gene_id);
        $this->assertEquals(456, $submission->disease_id);
        $this->assertEquals('ORIGINAL', $submission->local_key);
        // submission_data is cast as object, so it will be stdClass
        $this->assertEquals((object)['original' => 'value'], $submission->submission_data);
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
