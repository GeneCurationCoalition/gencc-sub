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
     * @param string|null $originState Optional origin state to store for cancel operations
     * @return Submission
     * @throws Exception if transition is invalid
     */
    public static function transition(Submission $submission, string $toState, ?string $originState = null): Submission
    {
        // Capture the current state before transition
        $fromState = $submission->status;

        // Validate transition
        self::validateTransition($fromState, $toState);

        // Store origin job_id and snapshot when entering draft_republish or draft_unpublish
        if (($toState === Submission::STATUS_DRAFT_REPUBLISH || $toState === Submission::STATUS_DRAFT_UNPUBLISH)
            && $submission->origin_job_id === null) {
            // Use the original job_id before it was changed (dirty value)
            // If job_id was just changed, getOriginal() returns the old value
            $submission->origin_job_id = $submission->getOriginal('job_id') ?? $submission->job_id;
            $submission->origin_state = $originState ?? $fromState;

            // Create snapshot of current editable fields as array
            $submission->origin_snapshot = [
                'local_key' => $submission->local_key,
                'gene_id' => $submission->gene_id,
                'disease_id' => $submission->disease_id,
                'inheritance_id' => $submission->inheritance_id,
                'classification_id' => $submission->classification_id,
                'report_date' => $submission->report_date,
                'report_url' => $submission->report_url,
                'submission_data' => $submission->submission_data,
                'pubmed_ids' => $submission->pubmeds()->pluck('pubmeds.id')->toArray(), // Store PubMed IDs
            ];
        }

        // Update state
        $submission->status = $toState;

        // When transitioning FROM draft_republish/draft_unpublish TO a non-draft/non-submitted state,
        // restore job_id and clear origin tracking fields
        if (in_array($fromState, [Submission::STATUS_DRAFT_REPUBLISH, Submission::STATUS_DRAFT_UNPUBLISH])
            && !in_array($toState, [Submission::STATUS_DRAFT_REPUBLISH, Submission::STATUS_DRAFT_UNPUBLISH,
                                    Submission::STATUS_SUBMITTED_REPUBLISH, Submission::STATUS_SUBMITTED_UNPUBLISH])) {

            // Restore original job_id if it was stored
            if ($submission->origin_job_id !== null) {
                $submission->job_id = $submission->origin_job_id;
                $submission->origin_job_id = null;
            }

            // Clear origin state and snapshot
            $submission->origin_state = null;
            $submission->origin_snapshot = null;
        }

        // When transitioning FROM submitted_republish/submitted_unpublish TO terminal states,
        // clear origin tracking fields (but don't restore job_id since we're completing the operation)
        if (in_array($fromState, [Submission::STATUS_SUBMITTED_REPUBLISH, Submission::STATUS_SUBMITTED_UNPUBLISH])
            && in_array($toState, [Submission::STATUS_PUBLISHED, Submission::STATUS_UNPUBLISHED])) {

            // Clear origin tracking - the operation is complete
            $submission->origin_state = null;
            $submission->origin_job_id = null;
            $submission->origin_snapshot = null;
        }

        return $submission;
    }

    /**
     * Cancel a draft operation and return to origin state
     * Only works for draft_republish and draft_unpublish states
     *
     * @param Submission $submission
     * @return Submission
     * @throws Exception if not in a cancellable state
     */
    public static function cancel(Submission $submission): Submission
    {
        $state = $submission->status;

        if ($state === Submission::STATUS_DRAFT_REPUBLISH) {
            // Revert to origin state (published or unpublished)
            if (!$submission->origin_state) {
                throw new Exception("Cannot cancel draft_republish: origin_state not set");
            }

            $targetState = $submission->origin_state;
            self::validateTransition($state, $targetState);

            $submission->status = $targetState;
            $submission->origin_state = null;

            // Restore original job_id if it was stored
            if ($submission->origin_job_id !== null) {
                $submission->job_id = $submission->origin_job_id;
                $submission->origin_job_id = null;
            }

            // Restore original field values from snapshot
            self::restoreFromSnapshot($submission);

        } elseif ($state === Submission::STATUS_DRAFT_UNPUBLISH) {
            // Always returns to published
            self::validateTransition($state, Submission::STATUS_PUBLISHED);
            $submission->status = Submission::STATUS_PUBLISHED;

            // Restore original job_id if it was stored
            if ($submission->origin_job_id !== null) {
                $submission->job_id = $submission->origin_job_id;
                $submission->origin_job_id = null;
            }

            // Restore original field values from snapshot
            self::restoreFromSnapshot($submission);

        } else {
            throw new Exception("Cannot cancel from state '{$state}'");
        }

        return $submission;
    }

    /**
     * Check if a submission is in a state that can be cancelled
     *
     * @param string $state
     * @return bool
     */
    public static function isCancellable(string $state): bool
    {
        return in_array($state, [
            Submission::STATUS_DRAFT_REPUBLISH,
            Submission::STATUS_DRAFT_UNPUBLISH,
        ]);
    }

    /**
     * Restore submission fields from origin_snapshot
     * Used when cancelling draft_republish or draft_unpublish operations
     *
     * @param Submission $submission
     * @return void
     */
    protected static function restoreFromSnapshot(Submission $submission): void
    {
        if ($submission->origin_snapshot === null) {
            // No snapshot to restore from - this is okay for backwards compatibility
            return;
        }

        $snapshot = $submission->origin_snapshot;

        // Restore database fields
        if (isset($snapshot->local_key)) {
            $submission->local_key = $snapshot->local_key;
        }
        if (isset($snapshot->gene_id)) {
            $submission->gene_id = $snapshot->gene_id;
        }
        if (isset($snapshot->disease_id)) {
            $submission->disease_id = $snapshot->disease_id;
        }
        if (isset($snapshot->inheritance_id)) {
            $submission->inheritance_id = $snapshot->inheritance_id;
        }
        if (isset($snapshot->classification_id)) {
            $submission->classification_id = $snapshot->classification_id;
        }
        if (isset($snapshot->report_date)) {
            // Convert to Carbon if it's a string, then format for MySQL
            $submission->report_date = $snapshot->report_date instanceof Carbon
                ? $snapshot->report_date
                : Carbon::parse($snapshot->report_date);
        }
        if (isset($snapshot->report_url)) {
            $submission->report_url = $snapshot->report_url;
        }

        // Restore submission_data JSON
        if (isset($snapshot->submission_data)) {
            $submission->submission_data = $snapshot->submission_data;
        }

        // Restore PubMed IDs (many-to-many relationship)
        if (isset($snapshot->pubmed_ids)) {
            // Sync will remove all current associations and add only the ones in the snapshot
            $submission->pubmeds()->sync($snapshot->pubmed_ids);
        }

        // Clear the snapshot after restoring
        $submission->origin_snapshot = null;
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
