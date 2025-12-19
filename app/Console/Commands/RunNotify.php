<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use App\Mail\NotifyErrors;

use App\Models\Job;

class RunNotify extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'run:notify';

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
        /**
         * Nofication of any unresolved submission errors
         * 
         */

        //$jobs = Job::with(['submissions' => function ($query) {
        //    $query->whereNotNull('submission_errors');
        //}])->get();

        $jobs = Job::with(['submissions' => function ($query) {
            $query->where('sid', 'd645c7ed-51f7-4695-a47d-ec3172ab3833');
        }])->get();

        foreach ($jobs as $job)
        {
            $subs = $job->submissions->pluck('sid');

            Mail::to($job->user)
                ->queue(new NotifyErrors($job, $subs));
        }

    }
}
