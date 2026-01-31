<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Models\Job;
use App\Models\Submission;
use App\Models\Metric;
use App\Models\User;

use Carbon\Carbon;

class RunMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'run:metrics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // jobs - use new string status values
        $jobs_queued_users = $this->jobsByStatus(Job::STATUS_DRAFT);
        $jobs_processing_users = $this->jobsByStatus(Job::STATUS_SUBMITTED);
        $jobs_errors_users = $this->jobsWithErrors();
        $jobs_complete_users = $this->jobsByStatus(Job::STATUS_PROCESSED);
        $jobs_removed_users = []; // Removed jobs are soft-deleted

        // submissions - use status for workflow states, withErrors scope for errors
        $submissions_queued_users = $this->submissionsByStatus(Submission::STATUS_DRAFT_NEW);
        $submissions_processing_users = $this->submissionsByStatus([
            Submission::STATUS_DRAFT_REPUBLISH,
            Submission::STATUS_DRAFT_UNPUBLISH,
            Submission::STATUS_SUBMITTED_NEW,
            Submission::STATUS_SUBMITTED_REPUBLISH,
            Submission::STATUS_SUBMITTED_UNPUBLISH
        ]);
        $submissions_errors_users = $this->submissionsWithErrors();
        $submissions_published_users = $this->submissionsByStatus(Submission::STATUS_PUBLISHED);
        $submissions_removed_users = $this->submissionsByStatus(Submission::STATUS_UNPUBLISHED);

        //classifications
        $classifications_definitive_users = $this->classifications('Definitive');
        $classifications_strong_users = $this->classifications('Strong');
        $classifications_moderate_users = $this->classifications('Moderate');
        $classifications_supportive_users = $this->classifications('Supportive');
        $classifications_limited_users = $this->classifications('Limited');
        $classifications_disputed_users = $this->classifications('Disputed Evidence');
        $classifications_refuted_users = $this->classifications('Refuted Evidence');
        $classifications_animal_users = $this->classifications('Animal Model Only');
        $classifications_nodisease_users = $this->classifications('No Known Disease Relationship');

        $metric = new Metric([
            'jobs_queued' => $jobs_queued_users,
            'jobs_processing' => $jobs_processing_users,
            'jobs_errors' => $jobs_errors_users,
            'jobs_window' => [],
            'jobs_complete' => $jobs_complete_users,
            'jobs_removed' => $jobs_removed_users,
            'submissions_queued' =>  $submissions_queued_users,
            'submissions_processing' => $submissions_processing_users,
            'submissions_errors' => $submissions_errors_users,
            'submissions_window' => [],
            'submissions_published' => $submissions_published_users,
            'submissions_removed' => $submissions_removed_users,
            'classifications_definitive' => $classifications_definitive_users,
            'classifications_strong' => $classifications_strong_users,
            'classifications_moderate' => $classifications_moderate_users,
            'classifications_supportive' => $classifications_supportive_users,
            'classifications_limited' => $classifications_limited_users, 
            'classifications_disputed' => $classifications_disputed_users,
            'classifications_refuted' => $classifications_refuted_users,
            'classifications_animal' => $classifications_animal_users,
            'classifications_nodisease' => $classifications_nodisease_users,
        ]);

        $metric->save();

        // now update everyones preferences
        Foreach (User::all() as $user)
            $this->update_preferences($user, $metric);

    }

    /**
     * Count the jobs of a given status for each user
     *
     * @param string $status Status value
     */
    protected function jobsByStatus($status)
    {
        $jobs = Job::where('status', $status)->get();
        $jobs_users = [];

        foreach($jobs as $job)
        {
            if (!isset($jobs_users[$job->user_id]))
                $jobs_users[$job->user_id] = 0;

            $jobs_users[$job->user_id]++;
        }

        return $jobs_users;
    }

    /**
     * Count jobs with errors for each user
     */
    protected function jobsWithErrors()
    {
        $jobs = Job::whereHas('submissions', function($query) {
            $query->withErrors();
        })->get();
        $jobs_users = [];

        foreach($jobs as $job)
        {
            if (!isset($jobs_users[$job->user_id]))
                $jobs_users[$job->user_id] = 0;

            $jobs_users[$job->user_id]++;
        }

        return $jobs_users;
    }


    /**
     * Count the submissions by status for each user
     *
     * @param string|array $status Single status or array of statuses
     */
    protected function submissionsByStatus($status)
    {
        $query = Submission::query();

        if (is_array($status)) {
            $query->whereIn('status', $status);
        } else {
            $query->where('status', $status);
        }

        $submissions = $query->get();
        $submissions_users = [];

        foreach ($submissions as $submission) {
            if (!isset($submissions_users[$submission->user_id]))
                $submissions_users[$submission->user_id] = 0;

            $submissions_users[$submission->user_id]++;
        }

        return $submissions_users;
    }

    /**
     * Count submissions with errors for each user
     */
    protected function submissionsWithErrors()
    {
        $submissions = Submission::withErrors()->get();
        $submissions_users = [];

        foreach ($submissions as $submission) {
            if (!isset($submissions_users[$submission->user_id]))
                $submissions_users[$submission->user_id] = 0;

            $submissions_users[$submission->user_id]++;
        }

        return $submissions_users;
    }


    /**
     * Count the classifications of a given status for each user
     * 
     */
    protected function classifications($name)
    {
        $blank = [
            'definitive' => 0,
            'strong' => 0,
            'moderate' => 0,
            'supportive' => 0,
            'limited' => 0,
            'disputed' => 0,
            'refuted' => 0,
            'animal' => 0,
            'nodisease' => 0
        ];

        $submissions = Submission::with('classification')->where('status', Submission::STATUS_PUBLISHED)->get();
        $classifications_users = [];

        foreach($submissions as $submission)
        {
            if ($submission->classification->name != $name)
                continue;

            if (!isset($classifications_users[$submission->user_id]))
                $classifications_users[$submission->user_id] = 0;

            $classifications_users[$submission->user_id]++;

        }

        return $classifications_users;
    }


    /**
     * Update user preferences
     * 
     */
    protected function update_preferences($user, $metric)
    {
        $preferences = (array) $user->preferences;

        if ($preferences === null)
            $preferences = ['dash_sub_graph' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
                            'dash_class_graph' => [],
                            'sub_favorites' => [],
                            'job_favorites' => []];

        $preferences['dash_class_graph'] = [
            $metric->classifications_definitive[$user->id] ?? 0,
            $metric->classifications_strong[$user->id] ?? 0,
            $metric->classifications_moderate[$user->id] ?? 0,
            $metric->classifications_supportive[$user->id] ?? 0,
            $metric->classifications_limited[$user->id] ?? 0,
            $metric->classifications_disputed[$user->id] ?? 0,
            $metric->classifications_refuted[$user->id] ?? 0,
            $metric->classifications_animal[$user->id] ?? 0,
            $metric->classifications_nodisease[$user->id] ?? 0
        ];

        // if first of the month, cycle the values
        if (Carbon::now()->format('d') == "01")
        {
            $shifted = $preferences['dash_sub_graph'];
            array_shift($shifted);
            array_push($shifted, 0);
            $preferences['dash_sub_graph'] = $shifted;
        }

        // calculate the current month
        $total = 0;

        $records = Metric::whereBetween('created_at', [Carbon::now()->firstOfMonth(), Carbon::now()])->get();

        if (!$records->isEmpty())
        {
            foreach ($records as $record)
            {
                $total += $record->submissions_published[$user->id] ?? 0;
            }
        }

        $preferences['dash_sub_graph'][11] = $total;

        $user->preferences = $preferences;
        $user->save();
    }
}
