<?php

namespace App\Console\Commands;

use App\Models\Disease;
use App\Models\Gene;
use App\Models\Submission;
use App\Services\CountsUpdater;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateCounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:counts {--force : Force update even if no changes pending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update aggregated curation counts on genes, diseases, and submitters';

    /**
     * Classification slug to counts key mapping.
     */
    protected $classificationKeys = [
        'definitive' => 'curations_definitive',
        'strong' => 'curations_strong',
        'moderate' => 'curations_moderate',
        'limited' => 'curations_limited',
        'disputed' => 'curations_disputed',
        'refuted' => 'curations_refuted',
        'animal-model-only' => 'curations_animal',
        'no-known' => 'curations_noknown',
        'supportive' => 'curations_supportive',
        'nul' => 'curations_nul',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);

        $this->updateGeneCounts();
        $this->updateSubmitterCounts();
        $this->updateDiseaseCounts();

        $elapsed = round(microtime(true) - $startTime, 2);
        $this->line("Count updates completed in {$elapsed} seconds");

        return 0;
    }

    /**
     * Build an empty counts structure with all keys zeroed.
     */
    protected function emptyCounts(array $extraKeys = []): array
    {
        $counts = [];
        foreach ($this->classificationKeys as $key) {
            $counts[$key] = 0;
        }
        foreach ($extraKeys as $key) {
            $counts[$key] = 0;
        }

        return $counts;
    }

    /**
     * Update gene counts using SQL aggregation, stored in the JSON counts column.
     */
    protected function updateGeneCounts(): void
    {
        $this->line('Updating Gene Counts...');

        $extraKeys = ['count_submissions', 'count_unique_submitters', 'count_unique_diseases'];

        // Get classification counts per gene
        $classificationCounts = DB::table('submissions')
            ->join('classifications', 'submissions.classification_id', '=', 'classifications.id')
            ->select(
                'submissions.gene_id',
                'classifications.slug',
                DB::raw('COUNT(*) as count')
            )
            ->where('submissions.is_live', true)
            ->where('submissions.status', Submission::STATUS_PUBLISHED)
            ->groupBy('submissions.gene_id', 'classifications.slug')
            ->get();

        $geneData = [];
        foreach ($classificationCounts as $row) {
            if (!isset($geneData[$row->gene_id])) {
                $geneData[$row->gene_id] = $this->emptyCounts($extraKeys);
            }
            if (isset($this->classificationKeys[$row->slug])) {
                $geneData[$row->gene_id][$this->classificationKeys[$row->slug]] = $row->count;
            }
        }

        // Total submissions per gene
        $submissionCounts = DB::table('submissions')
            ->select('gene_id', DB::raw('COUNT(*) as count'))
            ->where('is_live', true)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->groupBy('gene_id')
            ->get();

        foreach ($submissionCounts as $row) {
            if (!isset($geneData[$row->gene_id])) {
                $geneData[$row->gene_id] = $this->emptyCounts($extraKeys);
            }
            $geneData[$row->gene_id]['count_submissions'] = $row->count;
        }

        // Unique submitters per gene
        $uniqueSubmitters = DB::table('submissions')
            ->select('gene_id', DB::raw('COUNT(DISTINCT submitter_id) as count'))
            ->where('is_live', true)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->groupBy('gene_id')
            ->get();

        foreach ($uniqueSubmitters as $row) {
            if (!isset($geneData[$row->gene_id])) {
                $geneData[$row->gene_id] = $this->emptyCounts($extraKeys);
            }
            $geneData[$row->gene_id]['count_unique_submitters'] = $row->count;
        }

        // Unique diseases per gene
        $uniqueDiseases = DB::table('submissions')
            ->select('gene_id', DB::raw('COUNT(DISTINCT disease_id) as count'))
            ->where('is_live', true)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->groupBy('gene_id')
            ->get();

        foreach ($uniqueDiseases as $row) {
            if (!isset($geneData[$row->gene_id])) {
                $geneData[$row->gene_id] = $this->emptyCounts($extraKeys);
            }
            $geneData[$row->gene_id]['count_unique_diseases'] = $row->count;
        }

        // Reset genes with no live submissions, update those that do
        Gene::query()->update(['counts' => json_encode($this->emptyCounts($extraKeys))]);

        foreach ($geneData as $geneId => $counts) {
            Gene::where('id', $geneId)->update(['counts' => json_encode($counts)]);
        }

        $this->line('Gene Counts Completed (' . count($geneData) . ' genes updated)');
    }

    /**
     * Update submitter counts using shared service.
     * Delegates to CountsUpdater (single source of truth for the schema expected by gencc-search).
     */
    protected function updateSubmitterCounts(): void
    {
        $this->line('Updating Submitter Counts...');

        $updated = CountsUpdater::updateSubmitterCounts();

        $this->line('Submitter Counts Completed (' . $updated . ' submitters updated)');
    }

    /**
     * Update disease counts using SQL aggregation, stored in the JSON counts column.
     */
    protected function updateDiseaseCounts(): void
    {
        $this->line('Updating Disease Counts...');

        $extraKeys = ['count_submissions', 'count_unique_submitters', 'count_unique_genes'];

        // Get classification counts per disease
        $classificationCounts = DB::table('submissions')
            ->join('classifications', 'submissions.classification_id', '=', 'classifications.id')
            ->select(
                'submissions.disease_id',
                'classifications.slug',
                DB::raw('COUNT(*) as count')
            )
            ->where('submissions.is_live', true)
            ->where('submissions.status', Submission::STATUS_PUBLISHED)
            ->groupBy('submissions.disease_id', 'classifications.slug')
            ->get();

        $diseaseData = [];
        foreach ($classificationCounts as $row) {
            if (!isset($diseaseData[$row->disease_id])) {
                $diseaseData[$row->disease_id] = $this->emptyCounts($extraKeys);
            }
            if (isset($this->classificationKeys[$row->slug])) {
                $diseaseData[$row->disease_id][$this->classificationKeys[$row->slug]] = $row->count;
            }
        }

        // Total submissions per disease
        $submissionCounts = DB::table('submissions')
            ->select('disease_id', DB::raw('COUNT(*) as count'))
            ->where('is_live', true)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->groupBy('disease_id')
            ->get();

        foreach ($submissionCounts as $row) {
            if (!isset($diseaseData[$row->disease_id])) {
                $diseaseData[$row->disease_id] = $this->emptyCounts($extraKeys);
            }
            $diseaseData[$row->disease_id]['count_submissions'] = $row->count;
        }

        // Unique submitters per disease
        $uniqueSubmitters = DB::table('submissions')
            ->select('disease_id', DB::raw('COUNT(DISTINCT submitter_id) as count'))
            ->where('is_live', true)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->groupBy('disease_id')
            ->get();

        foreach ($uniqueSubmitters as $row) {
            if (!isset($diseaseData[$row->disease_id])) {
                $diseaseData[$row->disease_id] = $this->emptyCounts($extraKeys);
            }
            $diseaseData[$row->disease_id]['count_unique_submitters'] = $row->count;
        }

        // Unique genes per disease
        $uniqueGenes = DB::table('submissions')
            ->select('disease_id', DB::raw('COUNT(DISTINCT gene_id) as count'))
            ->where('is_live', true)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->groupBy('disease_id')
            ->get();

        foreach ($uniqueGenes as $row) {
            if (!isset($diseaseData[$row->disease_id])) {
                $diseaseData[$row->disease_id] = $this->emptyCounts($extraKeys);
            }
            $diseaseData[$row->disease_id]['count_unique_genes'] = $row->count;
        }

        // Reset all, then update
        Disease::query()->update(['counts' => json_encode($this->emptyCounts($extraKeys))]);

        foreach ($diseaseData as $diseaseId => $counts) {
            Disease::where('id', $diseaseId)->update(['counts' => json_encode($counts)]);
        }

        $this->line('Disease Counts Completed (' . count($diseaseData) . ' diseases updated)');
    }
}
