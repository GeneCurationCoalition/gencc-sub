<?php

namespace App\Services;

use App\Models\Job;
use App\Models\Submission;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * JobStateMachine
 *
 * Manages state transitions for Job records using the status field.
 * Validates that transitions follow the defined state machine rules.
 *
 * @package App\Services
 */
class JobStateMachine
{
    /**
     * Valid state transitions
     * Format: [from_state => [allowed_to_states]]
     */
    protected static $transitions = [
        Job::STATUS_DRAFT => [
            Job::STATUS_SUBMITTED,
            'deleted' // Special case for deletion
        ],
        Job::STATUS_SUBMITTED => [
            Job::STATUS_RELEASED,
            Job::STATUS_SUBMITTED, // Can stay submitted on complete failure
            Job::STATUS_DRAFT // Cancel before run:publish
        ],
        Job::STATUS_RELEASED => [
            // Terminal state - no transitions allowed
        ]
    ];

    /**
     * Check if a state transition is valid
     *
     * @param Job|string $fromStateOrJob Current state string OR Job object
     * @param string $toState Desired state
     * @return bool
     */
    public static function canTransition($fromStateOrJob, string $toState): bool
    {
        // Handle Job object or string
        $fromState = $fromStateOrJob instanceof Job
            ? $fromStateOrJob->status
            : $fromStateOrJob;

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
            throw new Exception("Invalid job state transition from '{$fromState}' to '{$toState}'");
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
     * Check if a job can be edited (submissions can be added/removed)
     *
     * @param string $state
     * @return bool
     */
    public static function isEditable(string $state): bool
    {
        return $state === Job::STATUS_DRAFT;
    }

    /**
     * Check if a job can be deleted
     *
     * @param string $state
     * @return bool
     */
    public static function canDelete(string $state): bool
    {
        return $state === Job::STATUS_DRAFT;
    }

    /**
     * Check if a job can be submitted (staged for publishing)
     *
     * @param string $state
     * @return bool
     */
    public static function canSubmit(string $state): bool
    {
        return $state === Job::STATUS_DRAFT;
    }

    /**
     * Check if a job can be cancelled (before run:publish starts)
     *
     * @param string $state
     * @return bool
     */
    public static function canCancel(string $state): bool
    {
        return $state === Job::STATUS_SUBMITTED;
    }

    /**
     * Check if a job is immutable (cannot have fields edited).
     * Submitted and released jobs are immutable.
     *
     * @param Job|string $stateOrJob Current state string OR Job object
     * @return bool
     */
    public static function isImmutable($stateOrJob): bool
    {
        $state = $stateOrJob instanceof Job
            ? $stateOrJob->status
            : $stateOrJob;

        return in_array($state, [Job::STATUS_SUBMITTED, Job::STATUS_RELEASED]);
    }

    /**
     * Check if a job is in terminal state (released)
     *
     * @param Job|string $stateOrJob Current state string OR Job object
     * @return bool
     */
    public static function isTerminal($stateOrJob): bool
    {
        // Handle Job object or string
        $state = $stateOrJob instanceof Job
            ? $stateOrJob->status
            : $stateOrJob;

        return $state === Job::STATUS_RELEASED;
    }

    /**
     * Transition a job to a new state
     * Validates the transition and updates the model
     *
     * @param Job $job
     * @param string $toState
     * @return Job
     * @throws Exception if transition is invalid
     */
    public static function transition(Job $job, string $toState): Job
    {
        // Validate transition
        self::validateTransition($job->status, $toState);

        // Update state
        $job->status = $toState;

        return $job;
    }

    /**
     * Submit a job (transition from draft to submitted)
     * Validates all submissions are in draft state with no errors
     *
     * @param Job $job
     * @return Job
     * @throws Exception if job cannot be submitted
     */
    public static function submit(Job $job): Job
    {
        // Validate current state
        if ($job->status !== Job::STATUS_DRAFT) {
            throw new Exception("Only draft jobs can be submitted");
        }

        // Validate all submissions are draft_xxx
        foreach ($job->submissions as $submission) {
            if (!SubmissionStateMachine::isDraftState($submission->status)) {
                throw new Exception("Can only submit jobs where all submissions are in draft states");
            }

            // Check for errors using has_errors accessor
            if ($submission->has_errors) {
                throw new Exception("Cannot submit job with submissions that have errors");
            }
        }

        // Transition job
        self::transition($job, Job::STATUS_SUBMITTED);

        // With the simplified status model, submission status does NOT change when
        // job transitions from draft to submitted. Stage (draft/submitted) is now
        // derived from Job.status.
        //
        // We only need to set submitted_at timestamp on all pending submissions
        $now = now();

        // The Submit click is the boundary between editable working data and the submitted-as
        // snapshot. Copy every pending submission in one query so large uploads do not incur
        // per-submission model saves and immutability relationship lookups.
        DB::table('submissions')
            ->where('job_id', $job->id)
            ->whereIn('status', [
                Submission::STATUS_NEW,
                Submission::STATUS_REPUBLISH,
                Submission::STATUS_UNPUBLISH,
            ])
            ->update([
                'original_submission_data' => DB::raw('submission_data'),
                'submitted_at' => $now,
                'updated_at' => $now,
            ]);

        return $job;
    }


    /**
     * Complete a job after successful run:publish
     * Marks job as released
     *
     * @param Job $job
     * @return Job
     * @throws Exception if job cannot be completed
     */
    public static function complete(Job $job): Job
    {
        // Validate current state
        if ($job->status !== Job::STATUS_SUBMITTED) {
            throw new Exception("Only submitted jobs can be marked as released");
        }

        // Transition to released
        self::transition($job, Job::STATUS_RELEASED);

        // Set released_at timestamp when job is released
        $job->released_at = now();

        return $job;
    }

    /**
     * Handle partial failure of a job
     * Creates new draft job for failed submissions, completes original job
     *
     * @param Job $job
     * @param array $failedSubmissionIds
     * @return Job New draft job containing failed submissions
     * @throws Exception
     */
    public static function handlePartialFailure(Job $job, array $failedSubmissionIds): Job
    {
        // Validate current state
        if ($job->status !== Job::STATUS_SUBMITTED) {
            throw new Exception("Only submitted jobs can have partial failures");
        }

        // Create new draft job for failures
        $newJob = Job::create([
            'user_id' => $job->user_id,
            'submitter_id' => $job->submitter_id,
            'status' => Job::STATUS_DRAFT,
            // created_at is auto-set by Laravel
            'type' => $job->type
        ]);

        // Move failed submissions to new job and reset to draft state
        foreach ($failedSubmissionIds as $submissionId) {
            $submission = $job->submissions()->find($submissionId);

            if ($submission) {
                // Move to new draft job
                $submission->job_id = $newJob->id;

                // Reset to draft state
                if (SubmissionStateMachine::isSubmittedState($submission->status)) {
                    $draftState = SubmissionStateMachine::getDraftStateFor($submission->status);
                    SubmissionStateMachine::transition($submission, $draftState);
                }

                $submission->save();
            }
        }

        // Mark original job as completed (partial success)
        self::complete($job);
        $job->save();

        return $newJob;
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
            Job::STATUS_DRAFT => 'Draft - submissions can be added/edited/removed',
            Job::STATUS_SUBMITTED => 'Submitted - awaiting release processing',
            Job::STATUS_RELEASED => 'Released - all submissions released',
        ];

        return $descriptions[$state] ?? 'Unknown state';
    }

    /**
     * Get count of jobs by state
     *
     * @return array
     */
    public static function getStateCounts(): array
    {
        return [
            'draft' => Job::where('status', Job::STATUS_DRAFT)->count(),
            'submitted' => Job::where('status', Job::STATUS_SUBMITTED)->count(),
            'released' => Job::where('status', Job::STATUS_RELEASED)->count(),
        ];
    }

    /**
     * Validate that job submissions match the job state
     * - Draft jobs must only contain draft_xxx submissions
     * - Submitted jobs must only contain submitted_xxx submissions
     * - Released jobs must only contain published or unpublished submissions
     *
     * @param Job $job
     * @return bool
     * @throws Exception if validation fails
     */
    public static function validateSubmissionStates(Job $job): bool
    {
        $jobState = $job->status;
        $submissions = $job->submissions;

        foreach ($submissions as $submission) {
            $submissionState = $submission->status;

            switch ($jobState) {
                case Job::STATUS_DRAFT:
                    // Draft jobs must only contain draft_xxx submissions
                    if (!SubmissionStateMachine::isDraftState($submissionState)) {
                        throw new Exception("Draft job {$job->slug} contains non-draft submission {$submission->sid} (state: {$submissionState})");
                    }
                    break;

                case Job::STATUS_SUBMITTED:
                    // Submitted jobs must only contain submitted_xxx submissions
                    if (!SubmissionStateMachine::isSubmittedState($submissionState)) {
                        throw new Exception("Submitted job {$job->slug} contains non-submitted submission {$submission->sid} (state: {$submissionState})");
                    }
                    break;

                case Job::STATUS_RELEASED:
                    // Released jobs must only contain published or unpublished submissions
                    if (!in_array($submissionState, ['published', 'unpublished'])) {
                        throw new Exception("Released job {$job->slug} contains invalid submission {$submission->sid} (state: {$submissionState}). Released jobs can only contain published or unpublished submissions.");
                    }
                    break;
            }
        }

        return true;
    }
}
