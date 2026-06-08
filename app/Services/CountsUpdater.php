<?php

namespace App\Services;

use App\Models\Submission;
use App\Models\Submitter;
use Illuminate\Support\Facades\DB;

class CountsUpdater
{
    /**
     * Update the counts JSON field on each submitter with their curation statistics.
     *
     * Writes the {total, by_classification} format consumed by gencc-search.
     *
     * @return int Number of submitters updated
     */
    public static function updateSubmitterCounts(): int
    {
        // Query live, published submissions grouped by submitter and classification
        $counts = DB::table('submissions')
            ->join('classifications', 'submissions.classification_id', '=', 'classifications.id')
            ->select(
                'submissions.submitter_id',
                'classifications.name as classification_name',
                'classifications.abbreviation as classification_abbr',
                DB::raw('COUNT(*) as count')
            )
            ->where('submissions.is_live', true)
            ->where('submissions.status', Submission::STATUS_PUBLISHED)
            ->whereNull('submissions.deleted_at')
            ->groupBy('submissions.submitter_id', 'classifications.name', 'classifications.abbreviation')
            ->get();

        // Total live, published count per submitter
        $totals = DB::table('submissions')
            ->select('submitter_id', DB::raw('COUNT(*) as total'))
            ->where('is_live', true)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->whereNull('deleted_at')
            ->groupBy('submitter_id')
            ->pluck('total', 'submitter_id');

        // Build {total, by_classification} structure per submitter
        $submitterCounts = [];
        foreach ($counts as $row) {
            if (!isset($submitterCounts[$row->submitter_id])) {
                $submitterCounts[$row->submitter_id] = [
                    'total' => $totals[$row->submitter_id] ?? 0,
                    'by_classification' => [],
                ];
            }
            $submitterCounts[$row->submitter_id]['by_classification'][$row->classification_name] = [
                'count' => $row->count,
                'abbreviation' => $row->classification_abbr,
            ];
        }

        // Update each submitter's counts field
        $updated = 0;
        foreach ($submitterCounts as $submitterId => $countsData) {
            Submitter::where('id', $submitterId)->update([
                'counts' => json_encode($countsData),
            ]);
            $updated++;
        }

        // Clear counts for submitters with no qualifying submissions
        $submittersWithCounts = array_keys($submitterCounts);
        if (!empty($submittersWithCounts)) {
            Submitter::whereNotIn('id', $submittersWithCounts)
                ->where('counts', '!=', '[]')
                ->update(['counts' => '[]']);
        } else {
            // No submitters have data — clear all
            Submitter::where('counts', '!=', '[]')
                ->update(['counts' => '[]']);
        }

        return $updated;
    }
}
