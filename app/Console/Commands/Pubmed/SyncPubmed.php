<?php

namespace App\Console\Commands\Pubmed;

use Illuminate\Console\Command;
use App\Models\AdminLog;
use App\Models\Pubmed;
use App\Models\Submission;
use App\Services\AdminProgressTracker;

class SyncPubmed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pubmed:sync
                            {--scope=pending : Scope of PMIDs to process: pending|all|submissions}
                            {--force : Force re-fetch even if data already exists}
                            {--create-missing : Create Pubmed records for missing PMIDs found in submissions}
                            {--silent : Minimal output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync PubMed data with flexible scoping options';

    protected const PROGRESS_OPERATION = AdminLog::OP_SYNC_PUBMED;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $scope = $this->option('scope');
        $force = $this->option('force');
        $createMissing = $this->option('create-missing');
        $quiet = $this->option('silent');

        if (!$quiet) {
            $this->info('========================================');
            $this->info('PubMed Sync');
            $this->info('========================================');
            $this->newLine();
        }

        // Determine which PMIDs to process based on scope
        AdminProgressTracker::addMessage(self::PROGRESS_OPERATION, "Collecting PMIDs (scope: {$scope})...");
        $pmids = $this->collectPmids($scope, $createMissing, $quiet);

        if (empty($pmids)) {
            if (!$quiet) {
                $this->warn('No PMIDs to process');
            }
            AdminProgressTracker::addMessage(self::PROGRESS_OPERATION, 'No PMIDs to process');
            return 0;
        }

        // Handle force re-fetch option
        if ($force && $scope !== 'pending') {
            if (!$quiet) {
                $this->info('Force flag: Resetting PMIDs to INITIALIZING status...');
            }
            $reset_count = Pubmed::whereIn('pmid', $pmids)
                ->update(['status' => Pubmed::STATUS_INITIALIZING]);
            if (!$quiet) {
                $this->info("Reset {$reset_count} PMIDs");
                $this->newLine();
            }
        }

        // Fetch summary data
        if (!$quiet) {
            $this->info('Fetching PubMed summary data...');
        }

        $pmids_needing_summary = Pubmed::whereIn('pmid', $pmids)
            ->where('status', Pubmed::STATUS_INITIALIZING)
            ->pluck('pmid')
            ->toArray();

        $summary_count = count($pmids_needing_summary);

        if ($summary_count > 0) {
            if (!$quiet) {
                $this->info("Processing {$summary_count} PMIDs...");
            }
            AdminProgressTracker::updatePhase(
                self::PROGRESS_OPERATION, 'fetching', 0, $summary_count,
                "Fetching {$summary_count} PMIDs from NCBI..."
            );

            $processed = Pubmed::query_summary_batch($pmids_needing_summary, function ($processed, $total, $batchNum, $totalBatches) {
                AdminProgressTracker::updatePhase(
                    self::PROGRESS_OPERATION, 'fetching', $processed, $total,
                    "Batch {$batchNum}/{$totalBatches} complete ({$processed}/{$total} PMIDs)"
                );
            });

            AdminProgressTracker::completePhase(
                self::PROGRESS_OPERATION, 'fetching',
                "Fetched {$processed}/{$summary_count} PMIDs"
            );

            if (!$quiet) {
                $this->info("Successfully processed {$processed} PMIDs");
            }
        } else {
            if (!$quiet) {
                $this->info('All PMIDs already have summary data');
            }
            AdminProgressTracker::addMessage(self::PROGRESS_OPERATION, 'All PMIDs already have summary data');
        }

        // Report on invalid PMIDs (if processing from submissions)
        if ($scope === 'submissions' && !$quiet) {
            $this->newLine();
            $this->info('Checking for invalid PMIDs...');

            $invalid_pmids = Pubmed::whereIn('pmid', $pmids)
                ->where('status', Pubmed::STATUS_INITIALIZING)
                ->pluck('pmid')
                ->toArray();

            if (count($invalid_pmids) > 0) {
                $this->warn('The following PMIDs could not be found in PubMed:');
                foreach (array_slice($invalid_pmids, 0, 10) as $pmid) {
                    $this->warn("  - {$pmid}");
                }
                if (count($invalid_pmids) > 10) {
                    $this->warn("  ... and " . (count($invalid_pmids) - 10) . " more");
                }
            } else {
                $this->info('All PMIDs were successfully validated');
            }
        }

        // Final summary
        if (!$quiet) {
            $this->newLine();
            $summary_complete_count = Pubmed::whereIn('pmid', $pmids)
                ->where('status', Pubmed::STATUS_SUMMARY_COMPLETE)
                ->count();

            $this->info('========================================');
            $this->info('PubMed Sync Complete');
            $this->info('========================================');
            $this->info("Total PMIDs processed: " . count($pmids));
            $this->info("Successfully synced: {$summary_complete_count}");
            $this->info('========================================');
        }

        return 0;
    }

    /**
     * Collect PMIDs based on scope option
     *
     * @param string $scope
     * @param bool $createMissing
     * @param bool $quiet
     * @return array
     */
    protected function collectPmids($scope, $createMissing, $quiet)
    {
        switch ($scope) {
            case 'pending':
                // Only process existing records with INITIALIZING status
                if (!$quiet) {
                    $count = Pubmed::where('status', Pubmed::STATUS_INITIALIZING)->count();
                    $this->info("Scope: Pending records only ({$count} found)");
                    $this->newLine();
                }
                return Pubmed::where('status', Pubmed::STATUS_INITIALIZING)
                    ->pluck('pmid')
                    ->toArray();

            case 'all':
                // Process ALL PMIDs in the pubmeds table
                if (!$quiet) {
                    $count = Pubmed::count();
                    $this->info("Scope: All records in pubmeds table ({$count} found)");
                    $this->newLine();
                }
                return Pubmed::pluck('pmid')->toArray();

            case 'submissions':
                // Collect PMIDs from submissions
                if (!$quiet) {
                    $this->info('Scope: PMIDs from all submissions');
                    $this->info('Step 1: Collecting PMIDs from submissions...');
                }

                $all_pmids = collect();
                Submission::whereNotNull('evidence')
                    ->chunk(1000, function ($submissions) use ($all_pmids) {
                        foreach ($submissions as $submission) {
                            if (is_array($submission->evidence)) {
                                foreach ($submission->evidence as $pmid) {
                                    $all_pmids->put($pmid, true);
                                }
                            }
                        }
                    });

                $unique_pmids = $all_pmids->keys()->toArray();
                $total_pmids = count($unique_pmids);

                if (!$quiet) {
                    $this->info("Found {$total_pmids} unique PMIDs across all submissions");
                    $this->newLine();
                }

                // Create missing records if requested
                if ($createMissing) {
                    if (!$quiet) {
                        $this->info('Step 2: Creating Pubmed records for missing PMIDs...');
                    }

                    $existing_pmids = Pubmed::whereIn('pmid', $unique_pmids)->pluck('pmid')->toArray();
                    $missing_pmids = array_diff($unique_pmids, $existing_pmids);

                    $created_count = 0;
                    foreach ($missing_pmids as $pmid) {
                        Pubmed::create([
                            'pmid' => $pmid,
                            'uid' => $pmid,
                            'status' => Pubmed::STATUS_INITIALIZING
                        ]);
                        $created_count++;
                    }

                    if (!$quiet) {
                        $this->info("Created {$created_count} new Pubmed records");
                        $this->newLine();
                    }
                }

                return $unique_pmids;

            default:
                if (!$quiet) {
                    $this->error("Invalid scope: {$scope}");
                    $this->info('Valid options: pending, all, submissions');
                }
                return [];
        }
    }
}
