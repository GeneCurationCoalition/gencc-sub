<?php

namespace App\Services;

use App\Models\Submission;
use Carbon\Carbon;
use Exception;

/**
 * SubmissionStateMachine
 *
 * Manages state transitions for Submission records using the status field.
 * Validates that transitions follow the defined state machine rules.
 *
 * With simplified statuses:
 * - Pending: new, republish, unpublish (action-based)
 * - Released: published, unpublished (visibility-based)
 *
 * Stage (draft/submitted) is derived from Job.status, not stored in submission.
 *
 * @package App\Services
 */
class SubmissionStateMachine
{
    /**
     * Valid state transitions
     * Format: [from_state => [allowed_to_states]]
     *
     * Note: Transitions from pending to pending are handled by Job state machine.
     * Submission status only changes when:
     * 1. Released (pending -> published/unpublished)
     * 2. Cancelled (pending -> deleted, previous version restored)
     */
    protected static $transitions = [
        // Pending states
        Submission::STATUS_NEW => [
            Submission::STATUS_PUBLISHED,
            'deleted' // Special case for deletion
        ],
        Submission::STATUS_REPUBLISH => [
            Submission::STATUS_PUBLISHED,
            'cancelled' // Deletes this version, restores previous
        ],
        Submission::STATUS_UNPUBLISH => [
            Submission::STATUS_UNPUBLISHED,
            'cancelled' // Deletes this version, restores previous
        ],
        // Released states
        Submission::STATUS_PUBLISHED => [
            Submission::STATUS_REPUBLISH,
            Submission::STATUS_UNPUBLISH
        ],
        Submission::STATUS_UNPUBLISHED => [
            Submission::STATUS_REPUBLISH
        ]
    ];

    /**
     * Pending status values (action-based)
     */
    protected static $pendingStates = [
        Submission::STATUS_NEW,
        Submission::STATUS_REPUBLISH,
        Submission::STATUS_UNPUBLISH,
    ];

    /**
     * Released status values (visibility-based)
     */
    protected static $releasedStates = [
        Submission::STATUS_PUBLISHED,
        Submission::STATUS_UNPUBLISHED,
    ];

    /**
     * States that are editable
     * Only new and republish are editable (unpublish is just marking for removal)
     */
    protected static $editableStates = [
        Submission::STATUS_NEW,
        Submission::STATUS_REPUBLISH,
    ];

    /**
     * Check if a state transition is valid
     *
     * @param Submission|string $fromStateOrSubmission Current state string OR Submission object
     * @param string $toState Desired state
     * @return bool
     */
    public static function canTransition($fromStateOrSubmission, string $toState): bool
    {
        // Handle Submission object or string
        $fromState = $fromStateOrSubmission instanceof \App\Models\Submission
            ? $fromStateOrSubmission->status
            : $fromStateOrSubmission;

        if (!isset(self::$transitions[$fromState])) {
            return false;
        }

        return in_array($toState, self::$transitions[$fromState]);
    }

    /**
     * Validate a state transition and throw exception if invalid
     *
     * @param string $fromState Current state
     * @param string $toState Desired state
     * @throws Exception if transition is not allowed
     * @return void
     */
    public static function validateTransition(string $fromState, string $toState): void
    {
        if (!self::canTransition($fromState, $toState)) {
            throw new Exception("Invalid state transition from '{$fromState}' to '{$toState}'");
        }
    }

    /**
     * Get all valid transitions from a given state
     *
     * @param string $fromState
     * @return array
     */
    public static function getValidTransitions(string $fromState): array
    {
        return self::$transitions[$fromState] ?? [];
    }

    /**
     * Check if a state is a pending state (not yet released)
     *
     * @param string $state
     * @return bool
     */
    public static function isPendingState(string $state): bool
    {
        return in_array($state, self::$pendingStates);
    }

    /**
     * Check if a state is a released state
     *
     * @param string $state
     * @return bool
     */
    public static function isReleasedState(string $state): bool
    {
        return in_array($state, self::$releasedStates);
    }

    /**
     * Check if a submission is in a pending state
     * Accepts Submission object or string state
     *
     * @param Submission|string $submissionOrState
     * @return bool
     */
    public static function isPending($submissionOrState): bool
    {
        $state = $submissionOrState instanceof \App\Models\Submission
            ? $submissionOrState->status
            : $submissionOrState;

        return self::isPendingState($state);
    }

    /**
     * Check if a submission is in a released state
     * Accepts Submission object or string state
     *
     * @param Submission|string $submissionOrState
     * @return bool
     */
    public static function isReleased($submissionOrState): bool
    {
        $state = $submissionOrState instanceof \App\Models\Submission
            ? $submissionOrState->status
            : $submissionOrState;

        return self::isReleasedState($state);
    }

    /**
     * Check if a submission is immutable (cannot have fields edited).
     * Published, unpublished, and submitted-stage submissions are immutable.
     * Accepts Submission object (checks job status too) or string state.
     *
     * @param Submission|string $submissionOrState
     * @return bool
     */
    public static function isImmutable($submissionOrState): bool
    {
        if ($submissionOrState instanceof \App\Models\Submission) {
            // Released states are always immutable
            if (self::isReleasedState($submissionOrState->status)) {
                return true;
            }
            // Submissions in a submitted job are immutable (pending release)
            if ($submissionOrState->job &&
                $submissionOrState->job->status === \App\Models\Job::STATUS_SUBMITTED) {
                return true;
            }
            return false;
        }

        // String-only check (no job context available)
        return self::isReleasedState($submissionOrState);
    }

    /**
     * Check if a submission can be edited in its current state
     * Only new and republish are editable (must be in draft job)
     *
     * @param Submission|string $submissionOrState
     * @return bool
     */
    public static function isEditable($submissionOrState): bool
    {
        $state = $submissionOrState instanceof \App\Models\Submission
            ? $submissionOrState->status
            : $submissionOrState;

        return in_array($state, self::$editableStates);
    }

    /**
     * Check if a submission can be deleted in its current state
     * Only new submissions can be deleted (v1 drafts)
     *
     * @param string $state
     * @return bool
     */
    public static function canDelete(string $state): bool
    {
        return $state === Submission::STATUS_NEW;
    }

    /**
     * Transition a submission to a new state
     * Validates the transition and updates the model
     *
     * @param Submission $submission
     * @param string $toState
     * @return Submission
     * @throws Exception if transition is invalid
     */
    public static function transition(Submission $submission, string $toState): Submission
    {
        // Capture the current state before transition
        $fromState = $submission->status;

        // Validate transition
        self::validateTransition($fromState, $toState);

        // Update state
        $submission->status = $toState;

        return $submission;
    }

    /**
     * Get a human-readable description of the state
     *
     * @param string $state
     * @return string
     */
    public static function getStateDescription(string $state): string
    {
        $descriptions = [
            Submission::STATUS_NEW => 'New submission (v1) awaiting release',
            Submission::STATUS_REPUBLISH => 'Update to existing submission awaiting release',
            Submission::STATUS_UNPUBLISH => 'Submission marked for unpublishing',
            Submission::STATUS_PUBLISHED => 'Published and visible to public',
            Submission::STATUS_UNPUBLISHED => 'Unpublished and hidden from public',
        ];

        return $descriptions[$state] ?? 'Unknown state';
    }

    // =========================================================================
    // Legacy compatibility methods
    // These maintain backwards compatibility during migration
    // =========================================================================

    /**
     * @deprecated Use isPendingState() instead
     * Check if a state is a "draft" state
     * In the new model, this means pending state where job is draft
     */
    public static function isDraftState(string $state): bool
    {
        return self::isPendingState($state);
    }

    /**
     * @deprecated Use isPendingState() instead
     * Check if a state is a "submitted" state
     * In the new model, this means pending state where job is submitted
     */
    public static function isSubmittedState(string $state): bool
    {
        return self::isPendingState($state);
    }

    /**
     * @deprecated Use isPending() instead
     */
    public static function isDraft($submissionOrState): bool
    {
        return self::isPending($submissionOrState);
    }

    /**
     * @deprecated Use isPending() instead
     */
    public static function isSubmitted($submissionOrState): bool
    {
        return self::isPending($submissionOrState);
    }

    /**
     * @deprecated No longer needed - status doesn't change between draft/submitted
     * Get the appropriate "submitted" state for a draft state
     * With simplified statuses, this is a no-op (status doesn't change)
     */
    public static function getSubmittedStateFor(string $state): ?string
    {
        // Status doesn't change - just return the same state
        if (!self::isPendingState($state)) {
            throw new Exception("'{$state}' is not a pending state");
        }
        return $state;
    }

    /**
     * @deprecated No longer needed - status doesn't change between draft/submitted
     * Get the appropriate "draft" state for a submitted state (for cancellation)
     * With simplified statuses, this is a no-op (status doesn't change)
     */
    public static function getDraftStateFor(string $state): ?string
    {
        // Status doesn't change - just return the same state
        if (!self::isPendingState($state)) {
            throw new Exception("'{$state}' is not a pending state");
        }
        return $state;
    }
}
