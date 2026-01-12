<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Submission;
use App\Models\Job;
use App\Services\SubmissionStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * SubmissionStateMachineTest
 *
 * Tests for the simplified submission status model:
 * - Pending statuses (action-based): new, republish, unpublish
 * - Released statuses (visibility-based): published, unpublished
 *
 * Stage (draft/submitted) is derived from Job.status, not submission status.
 */
class SubmissionStateMachineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test valid transition from new to published (releasing a v1 submission)
     */
    public function test_new_to_published(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_NEW
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_PUBLISHED)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_PUBLISHED);

        $this->assertEquals(Submission::STATUS_PUBLISHED, $submission->status);
    }

    /**
     * Test valid transition from republish to published (releasing an update)
     */
    public function test_republish_to_published(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_REPUBLISH
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_PUBLISHED)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_PUBLISHED);

        $this->assertEquals(Submission::STATUS_PUBLISHED, $submission->status);
    }

    /**
     * Test valid transition from unpublish to unpublished (releasing an unpublish action)
     */
    public function test_unpublish_to_unpublished(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_UNPUBLISH
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_UNPUBLISHED)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_UNPUBLISHED);

        $this->assertEquals(Submission::STATUS_UNPUBLISHED, $submission->status);
    }

    /**
     * Test valid transition from published to republish (starting an update)
     */
    public function test_published_to_republish(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_REPUBLISH)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_REPUBLISH);

        $this->assertEquals(Submission::STATUS_REPUBLISH, $submission->status);
    }

    /**
     * Test valid transition from published to unpublish (starting an unpublish)
     */
    public function test_published_to_unpublish(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_UNPUBLISH)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_UNPUBLISH);

        $this->assertEquals(Submission::STATUS_UNPUBLISH, $submission->status);
    }

    /**
     * Test valid transition from unpublished to republish (re-publishing after unpublish)
     */
    public function test_unpublished_to_republish(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_UNPUBLISHED
        ]);

        $this->assertTrue(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_REPUBLISH)
        );

        SubmissionStateMachine::transition($submission, Submission::STATUS_REPUBLISH);

        $this->assertEquals(Submission::STATUS_REPUBLISH, $submission->status);
    }

    /**
     * Test invalid transition is blocked
     */
    public function test_invalid_transition_is_blocked(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_PUBLISHED
        ]);

        // published cannot go directly to new
        $this->assertFalse(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_NEW)
        );

        $this->expectException(\Exception::class);
        SubmissionStateMachine::transition($submission, Submission::STATUS_NEW);
    }

    /**
     * Test new cannot transition to unpublished
     * (new submissions must go to published first)
     */
    public function test_new_cannot_unpublish(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_NEW
        ]);

        $this->assertFalse(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_UNPUBLISHED)
        );
    }

    /**
     * Test unpublished cannot go directly to published
     * (must go through republish first)
     */
    public function test_unpublished_cannot_go_to_published_directly(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_UNPUBLISHED
        ]);

        $this->assertFalse(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_PUBLISHED)
        );
    }

    /**
     * Test unpublished cannot be unpublished again
     */
    public function test_unpublished_cannot_unpublish_again(): void
    {
        $submission = Submission::factory()->create([
            'status' => Submission::STATUS_UNPUBLISHED
        ]);

        $this->assertFalse(
            SubmissionStateMachine::canTransition($submission, Submission::STATUS_UNPUBLISH)
        );
    }

    /**
     * Test isPending helper method
     */
    public function test_is_pending_helper(): void
    {
        $new = Submission::factory()->create(['status' => Submission::STATUS_NEW]);
        $republish = Submission::factory()->create(['status' => Submission::STATUS_REPUBLISH]);
        $unpublish = Submission::factory()->create(['status' => Submission::STATUS_UNPUBLISH]);
        $published = Submission::factory()->create(['status' => Submission::STATUS_PUBLISHED]);
        $unpublished = Submission::factory()->create(['status' => Submission::STATUS_UNPUBLISHED]);

        $this->assertTrue(SubmissionStateMachine::isPending($new));
        $this->assertTrue(SubmissionStateMachine::isPending($republish));
        $this->assertTrue(SubmissionStateMachine::isPending($unpublish));
        $this->assertFalse(SubmissionStateMachine::isPending($published));
        $this->assertFalse(SubmissionStateMachine::isPending($unpublished));
    }

    /**
     * Test isReleased helper method
     */
    public function test_is_released_helper(): void
    {
        $new = Submission::factory()->create(['status' => Submission::STATUS_NEW]);
        $republish = Submission::factory()->create(['status' => Submission::STATUS_REPUBLISH]);
        $unpublish = Submission::factory()->create(['status' => Submission::STATUS_UNPUBLISH]);
        $published = Submission::factory()->create(['status' => Submission::STATUS_PUBLISHED]);
        $unpublished = Submission::factory()->create(['status' => Submission::STATUS_UNPUBLISHED]);

        $this->assertFalse(SubmissionStateMachine::isReleased($new));
        $this->assertFalse(SubmissionStateMachine::isReleased($republish));
        $this->assertFalse(SubmissionStateMachine::isReleased($unpublish));
        $this->assertTrue(SubmissionStateMachine::isReleased($published));
        $this->assertTrue(SubmissionStateMachine::isReleased($unpublished));
    }

    /**
     * Test isEditable helper method
     * Only new and republish are editable (unpublish just marks for removal)
     */
    public function test_is_editable_helper(): void
    {
        $new = Submission::factory()->create(['status' => Submission::STATUS_NEW]);
        $republish = Submission::factory()->create(['status' => Submission::STATUS_REPUBLISH]);
        $unpublish = Submission::factory()->create(['status' => Submission::STATUS_UNPUBLISH]);
        $published = Submission::factory()->create(['status' => Submission::STATUS_PUBLISHED]);

        $this->assertTrue(SubmissionStateMachine::isEditable($new));
        $this->assertTrue(SubmissionStateMachine::isEditable($republish));
        $this->assertFalse(SubmissionStateMachine::isEditable($unpublish));
        $this->assertFalse(SubmissionStateMachine::isEditable($published));
    }

    /**
     * Test canDelete helper method
     * Only new (v1) submissions can be deleted
     */
    public function test_can_delete_helper(): void
    {
        $this->assertTrue(SubmissionStateMachine::canDelete(Submission::STATUS_NEW));
        $this->assertFalse(SubmissionStateMachine::canDelete(Submission::STATUS_REPUBLISH));
        $this->assertFalse(SubmissionStateMachine::canDelete(Submission::STATUS_UNPUBLISH));
        $this->assertFalse(SubmissionStateMachine::canDelete(Submission::STATUS_PUBLISHED));
        $this->assertFalse(SubmissionStateMachine::canDelete(Submission::STATUS_UNPUBLISHED));
    }

    /**
     * Test getValidTransitions method
     */
    public function test_get_valid_transitions(): void
    {
        $newTransitions = SubmissionStateMachine::getValidTransitions(Submission::STATUS_NEW);
        $this->assertContains(Submission::STATUS_PUBLISHED, $newTransitions);
        $this->assertContains('deleted', $newTransitions);

        $publishedTransitions = SubmissionStateMachine::getValidTransitions(Submission::STATUS_PUBLISHED);
        $this->assertContains(Submission::STATUS_REPUBLISH, $publishedTransitions);
        $this->assertContains(Submission::STATUS_UNPUBLISH, $publishedTransitions);

        $unpublishedTransitions = SubmissionStateMachine::getValidTransitions(Submission::STATUS_UNPUBLISHED);
        $this->assertContains(Submission::STATUS_REPUBLISH, $unpublishedTransitions);
    }

    /**
     * Test getStateDescription method
     */
    public function test_get_state_description(): void
    {
        $this->assertStringContainsString('New', SubmissionStateMachine::getStateDescription(Submission::STATUS_NEW));
        $this->assertStringContainsString('Update', SubmissionStateMachine::getStateDescription(Submission::STATUS_REPUBLISH));
        $this->assertStringContainsString('Published', SubmissionStateMachine::getStateDescription(Submission::STATUS_PUBLISHED));
    }

    // =========================================================================
    // Legacy compatibility tests
    // These test the deprecated methods that exist for backwards compatibility
    // =========================================================================

    /**
     * Test deprecated isDraft method (now maps to isPending)
     */
    public function test_is_draft_legacy_helper(): void
    {
        $new = Submission::factory()->create(['status' => Submission::STATUS_NEW]);
        $republish = Submission::factory()->create(['status' => Submission::STATUS_REPUBLISH]);
        $published = Submission::factory()->create(['status' => Submission::STATUS_PUBLISHED]);

        // isDraft now means isPending
        $this->assertTrue(SubmissionStateMachine::isDraft($new));
        $this->assertTrue(SubmissionStateMachine::isDraft($republish));
        $this->assertFalse(SubmissionStateMachine::isDraft($published));
    }

    /**
     * Test deprecated isSubmitted method (now maps to isPending)
     * Note: In the old model, isDraft and isSubmitted were mutually exclusive.
     * In the new model, both map to isPending because stage is derived from Job.
     */
    public function test_is_submitted_legacy_helper(): void
    {
        $new = Submission::factory()->create(['status' => Submission::STATUS_NEW]);
        $republish = Submission::factory()->create(['status' => Submission::STATUS_REPUBLISH]);
        $published = Submission::factory()->create(['status' => Submission::STATUS_PUBLISHED]);

        // isSubmitted now means isPending (same as isDraft)
        $this->assertTrue(SubmissionStateMachine::isSubmitted($new));
        $this->assertTrue(SubmissionStateMachine::isSubmitted($republish));
        $this->assertFalse(SubmissionStateMachine::isSubmitted($published));
    }

    /**
     * Test deprecated isDraftState method
     */
    public function test_is_draft_state_legacy(): void
    {
        $this->assertTrue(SubmissionStateMachine::isDraftState(Submission::STATUS_NEW));
        $this->assertTrue(SubmissionStateMachine::isDraftState(Submission::STATUS_REPUBLISH));
        $this->assertTrue(SubmissionStateMachine::isDraftState(Submission::STATUS_UNPUBLISH));
        $this->assertFalse(SubmissionStateMachine::isDraftState(Submission::STATUS_PUBLISHED));
    }

    /**
     * Test deprecated isSubmittedState method
     */
    public function test_is_submitted_state_legacy(): void
    {
        // In the new model, isSubmittedState maps to isPendingState
        $this->assertTrue(SubmissionStateMachine::isSubmittedState(Submission::STATUS_NEW));
        $this->assertTrue(SubmissionStateMachine::isSubmittedState(Submission::STATUS_REPUBLISH));
        $this->assertFalse(SubmissionStateMachine::isSubmittedState(Submission::STATUS_PUBLISHED));
    }
}
