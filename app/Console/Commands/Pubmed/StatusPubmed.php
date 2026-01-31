<?php

namespace App\Console\Commands\Pubmed;

use Illuminate\Console\Command;
use App\Models\Pubmed;
use App\Models\Submission;

class StatusPubmed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pubmed:status
                            {--details : Show detailed list of pending PMIDs and affected submissions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show status of PubMed records and submissions with pending PMIDs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('========================================');
        $this->info('PubMed Status Report');
        $this->info('========================================');
        $this->newLine();

        // Count Pubmed records by status
        $statusCounts = Pubmed::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $statusLabels = [
            Pubmed::STATUS_INITIALIZING => 'Initializing (needs fetch)',
            Pubmed::STATUS_SUMMARY_COMPLETE => 'Summary Complete',
            Pubmed::STATUS_ACTIVE => 'Active',
            Pubmed::STATUS_REMOVED => 'Removed',
        ];

        $this->info('PubMed Records by Status:');
        $total = 0;
        foreach ($statusCounts as $status => $count) {
            $label = $statusLabels[$status] ?? "Unknown ({$status})";
            $this->line("  {$label}: {$count}");
            $total += $count;
        }
        $this->line("  ─────────────────────────────");
        $this->line("  Total: {$total}");
        $this->newLine();

        // Get pending PMIDs
        $pendingPmids = Pubmed::where('status', Pubmed::STATUS_INITIALIZING)
            ->pluck('pmid')
            ->toArray();

        $pendingCount = count($pendingPmids);

        if ($pendingCount === 0) {
            $this->info('✓ All PubMed records are up to date');
            $this->newLine();
            return 0;
        }

        $this->warn("⚠ {$pendingCount} PMID(s) need to be fetched from NCBI");
        $this->newLine();

        // Count submissions affected
        $affectedSubmissions = Submission::whereHas('pubmeds', function($q) {
            $q->where('status', Pubmed::STATUS_INITIALIZING);
        })->get(['id', 'sid', 'status']);

        $submissionCount = $affectedSubmissions->count();
        $this->info("Submissions with pending PMIDs: {$submissionCount}");

        if ($this->option('details')) {
            $this->newLine();
            $this->info('Pending PMIDs:');
            foreach ($pendingPmids as $pmid) {
                $this->line("  - {$pmid}");
            }

            if ($submissionCount > 0) {
                $this->newLine();
                $this->info('Affected Submissions:');
                foreach ($affectedSubmissions as $sub) {
                    $this->line("  - {$sub->sid} (status: {$sub->status})");
                }
            }
        } else {
            $this->line("  (use --details to see list)");
        }

        $this->newLine();
        $this->info('To sync pending PMIDs, run:');
        $this->line('  php artisan pubmed:sync');
        $this->newLine();

        return $pendingCount > 0 ? 1 : 0;
    }
}
