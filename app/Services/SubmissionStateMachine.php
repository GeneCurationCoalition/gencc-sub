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
 * @package App\Services
 */
class SubmissionStateMachine
{
    /**
     * Valid state transitions
     * Format: [from_state => [allowed_to_states]]
     */
    protected static $transitions = [
        Submission::STATUS_DRAFT_NEW => [
            Submission::STATUS_SUBMITTED_NEW,
            'deleted' // Special case for deletion
        ],
        Submission::STATUS_SUBMITTED_NEW => [
            Submission::STATUS_PUBLISHED,
            Submission::STATUS_DRAFT_NEW // Cancel
        ],
        Submission::STATUS_PUBLISHED => [
            Submission::STATUS_DRAFT_REPUBLISH,
            Submission::STATUS_DRAFT_UNPUBLISH
        ],
        Submission::STATUS_DRAFT_REPUBLISH => [
            Submission::STATUS_SUBMITTED_REPUBLISH,
            Submission::STATUS_PUBLISHED, // Cancel (if origin was published)
            Submission::STATUS_UNPUBLISHED // Cancel (if origin was unpublished)
        ],
        Submission::STATUS_SUBMITTED_REPUBLISH => [
            Submission::STATUS_PUBLISHED,
            Submission::STATUS_DRAFT_REPUBLISH // Cancel
        ],
        Submission::STATUS_DRAFT_UNPUBLISH => [
            Submission::STATUS_SUBMITTED_UNPUBLISH,
            Submission::STATUS_PUBLISHED // Cancel
        ],
        Submission::STATUS_SUBMITTED_UNPUBLISH => [
            Submission::STATUS_UNPUBLISHED,
            Submission::STATUS_DRAFT_UNPUBLISH // Cancel
        ],
        Submission::STATUS_UNPUBLISHED => [
            Submission::STATUS_DRAFT_REPUBLISH
        ]
    ];

    /**
     * States that are considered "draft" states (for job submission purposes)
     */
    protected static $draftStates = [
        Submission::STATUS_DRAFT_NEW,
        Submission::STATUS_DRAFT_REPUBLISH,
        Submission::STATUS_DRAFT_UNPUBLISH,
    ];

    /**
     * States that are considered "submitted" states (awaiting processing)
     */
    protected static $submittedStates = [
        Submission::STATUS_SUBMITTED_NEW,
        Submission::STATUS_SUBMITTED_REPUBLISH,
        Submission::STATUS_SUBMITTED_UNPUBLISH,
    ];

    /**
     * States that are NOT editable
     */
    protected static $nonEditableStates = [
        Submission::STATUS_DRAFT_UNPUBLISH,
        Submission::STATUS_SUBMITTED_NEW,
        Submission::STATUS_SUBMITTED_REPUBLISH,
        Submission::STATUS_SUBMITTED_UNPUBLISH,
        Submission::STATUS_PUBLISHED,
        Submission::STATUS_UNPUBLISHED,
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
     * Check if a state is a draft state (editable)
     *
     * @param string $state
     * @return bool
     */
    public static function isDraftState(string $state): bool
    {
        return in_array($state, self::$draftStates);
    }

    /**
     * Check if a state is a submitted state (awaiting processing)
     *
     * @param string $state
     * @return bool
     */
    public static function isSubmittedState(string $state): bool
    {
        return in_array($state, self::$submittedStates);
    }

    /**
     * Check if a submission is in a draft state (alias for isDraftState)
     * Accepts Submission object or string state
     *
     * @param Submission|string $submissionOrState
     * @return bool
     */
    public static function isDraft($submissionOrState): bool
    {
        $state = $submissionOrState instanceof \App\Models\Submission
            ? $submissionOrState->status
            : $submissionOrState;

        return self::isDraftState($state);
    }

    /**
     * Check if a submission is in a submitted state (alias for isSubmittedState)
     * Accepts Submission object or string state
     *
     * @param Submission|string $submissionOrState
     * @return bool
     */
    public static function isSubmitted($submissionOrState): bool
    {
        $state = $submissionOrState instanceof \App\Models\Submission
            ? $submissionOrState->status
            : $submissionOrState;

        return self::isSubmittedState($state);
    }

    /**
     * Check if a submission can be edited in its current state
     *
     * @param string $state
     * @return bool
     */
    public static function isEditable(string $state): bool
    {
        return !in_array($state, self::$nonEditableStates);
    }

    /**
     * Check if a submission can be deleted in its current state
     * Only draft_new submissions can be deleted
     *
     * @param string $state
     * @return bool
     */
    public static function canDelete(string $state): bool
    {
        return $state === Submission::STATUS_DRAFT_NEW;
    }

    /**
     * Get the appropriate "submitted" state for a draft state
     *
     * @param string $draftState
     * @return string|null
     * @throws Exception if not a draft state
     */
    public static function getSubmittedStateFor(string $draftState): ?string
    {
        $mapping = [
            Submission::STATUS_DRAFT_NEW => Submission::STATUS_SUBMITTED_NEW,
            Submission::STATUS_DRAFT_REPUBLISH => Submission::STATUS_SUBMITTED_REPUBLISH,
            Submission::STATUS_DRAFT_UNPUBLISH => Submission::STATUS_SUBMITTED_UNPUBLISH,
        ];

        if (!isset($mapping[$draftState])) {
            throw new Exception("'{$draftState}' is not a draft state");
        }

        return $mapping[$draftState];
    }

    /**
     * Get the appropriate "draft" state for a submitted state (for cancellation)
     *
     * @param string $submittedState
     * @return string|null
     * @throws Exception if not a submitted state
     */
    public static function getDraftStateFor(string $submittedState): ?string
    {
        $mapping = [
            Submission::STATUS_SUBMITTED_NEW => Submission::STATUS_DRAFT_NEW,
            Submission::STATUS_SUBMITTED_REPUBLISH => Submission::STATUS_DRAFT_REPUBLISH,
            Submission::STATUS_SUBMITTED_UNPUBLISH => Submission::STATUS_DRAFT_UNPUBLISH,
        ];

        if (!isset($mapping[$submittedState])) {
            throw new Exception("'{$submittedState}' is not a submitted state");
        }

        return $mapping[$submittedState];
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

        // Note: origin_state is no longer needed since we now create new version records
        // when republishing/unpublishing. The previous state can be determined by looking
        // at the previous version record (version_number - 1).

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
            Submission::STATUS_DRAFT_NEW => 'New submission being drafted',
            Submission::STATUS_SUBMITTED_NEW => 'New submission awaiting publication',
            Submission::STATUS_PUBLISHED => 'Published and visible to public',
            Submission::STATUS_DRAFT_REPUBLISH => 'Published submission being updated',
            Submission::STATUS_SUBMITTED_REPUBLISH => 'Updated submission awaiting republication',
            Submission::STATUS_DRAFT_UNPUBLISH => 'Published submission marked for unpublishing',
            Submission::STATUS_SUBMITTED_UNPUBLISH => 'Submission awaiting unpublication',
            Submission::STATUS_UNPUBLISHED => 'Unpublished and hidden from public',
        ];

        return $descriptions[$state] ?? 'Unknown state';
    }
}
