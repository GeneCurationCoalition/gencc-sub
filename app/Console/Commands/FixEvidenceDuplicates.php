<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Submission;

class FixEvidenceDuplicates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:evidence-duplicates {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix duplicated PMIDs in the evidence field of submissions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $this->info('Analyzing submissions with evidence field...');

        $submissions = Submission::whereNotNull('evidence')->get();
        $this->info("Found {$submissions->count()} submissions with evidence field");

        $fixed = 0;
        $alreadyClean = 0;
        $errors = 0;

        $this->info('Processing submissions...');
        $bar = $this->output->createProgressBar($submissions->count());

        foreach ($submissions as $submission) {
            try {
                $evidence = $submission->evidence;

                if (empty($evidence) || !is_array($evidence)) {
                    $alreadyClean++;
                    $bar->advance();
                    continue;
                }

                $originalCount = count($evidence);
                $uniqueEvidence = array_values(array_unique($evidence));
                $uniqueCount = count($uniqueEvidence);

                if ($originalCount !== $uniqueCount) {
                    // Has duplicates - needs fixing
                    $this->newLine();
                    $this->line("  {$submission->sid}: {$originalCount} total → {$uniqueCount} unique");

                    if (!$dryRun) {
                        $submission->evidence = $uniqueEvidence;
                        $submission->save();
                    }

                    $fixed++;
                } else {
                    $alreadyClean++;
                }

                $bar->advance();

            } catch (\Exception $e) {
                $this->newLine();
                $this->error("  Error processing {$submission->sid}: {$e->getMessage()}");
                $errors++;
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine();
        $this->newLine();

        // Summary
        $this->info('=' . str_repeat('=', 79));
        $this->info('SUMMARY');
        $this->info('=' . str_repeat('=', 79));
        $this->info("Total submissions processed: {$submissions->count()}");
        $this->info("Fixed (had duplicates): {$fixed}");
        $this->info("Already clean (no duplicates): {$alreadyClean}");
        $this->info("Errors: {$errors}");

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY RUN - No changes were made. Run without --dry-run to apply fixes.');
        } else {
            $this->newLine();
            $this->info('All fixes applied successfully!');
        }

        return 0;
    }
}
