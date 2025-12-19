<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Submission;
use Carbon\Carbon;

class BackfillReportDate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backfill:report-date {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill report_date column from submission_data.report.display_date';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in DRY RUN mode - no changes will be made');
        }

        $this->info('Finding submissions with missing report_date...');

        // Get submissions where report_date is NULL but submission_data.report.display_date exists
        $submissions = Submission::whereNull('report_date')
            ->whereNotNull('submission_data->report->display_date')
            ->get();

        $total = $submissions->count();
        $this->info("Found {$total} submissions to backfill");

        if ($total === 0) {
            $this->info('No submissions need backfilling');
            return 0;
        }

        $updated = 0;
        $failed = 0;
        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        foreach ($submissions as $submission) {
            try {
                $displayDate = $submission->submission_data->report->display_date ?? null;

                if ($displayDate) {
                    $parsedDate = Carbon::parse($displayDate);

                    if (!$dryRun) {
                        // Update directly with query builder to bypass mass assignment and avoid touching updated_at
                        Submission::where('id', $submission->id)->update([
                            'report_date' => $parsedDate
                        ]);
                    }

                    $updated++;
                } else {
                    $this->warn("\nSubmission {$submission->sid} has no display_date in JSON");
                    $failed++;
                }
            } catch (\Exception $e) {
                $this->error("\nFailed to parse date for submission {$submission->sid}: " . $e->getMessage());
                $failed++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("DRY RUN COMPLETE");
            $this->info("Would have updated: {$updated} submissions");
        } else {
            $this->info("BACKFILL COMPLETE");
            $this->info("Successfully updated: {$updated} submissions");
        }

        if ($failed > 0) {
            $this->warn("Failed: {$failed} submissions");
        }

        return 0;
    }
}
