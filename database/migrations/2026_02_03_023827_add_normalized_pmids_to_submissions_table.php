<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Services\PmidNormalizer;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 1. Adds normalized_pmids and pmid_issues columns to submissions table.
     * 2. Trims trailing whitespace from submitted_as fields in submission_data.
     * 3. Normalizes existing PMIDs from submission_data->evidence using PmidNormalizer.
     * 4. Re-synchronizes pubmed_submission pivot table with normalized PMIDs.
     * 5. Clears invalid_pmid errors that normalization resolved.
     */
    public function up(): void
    {
        // Add new columns (skip if they already exist from a partial prior run)
        if (!Schema::hasColumn('submissions', 'normalized_pmids')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->text('normalized_pmids')->nullable()->after('evidence');
                $table->json('pmid_issues')->nullable()->after('normalized_pmids');
            });
        }

        // Build a lookup of pmid string => pubmeds.id for re-syncing the pivot table
        $pubmedLookup = DB::table('pubmeds')
            ->select('id', 'pmid')
            ->get()
            ->keyBy('pmid');

        // Process all submissions that have evidence data
        $submissions = DB::table('submissions')
            ->whereNotNull('evidence')
            ->where('evidence', '!=', '[]')
            ->where('evidence', '!=', 'null')
            ->select('id', 'sid', 'version_number', 'evidence', 'submission_data', 'submission_errors')
            ->get();

        $normalizedCount = 0;
        $issueCount = 0;
        $errorsClearedCount = 0;

        foreach ($submissions as $submission) {
            $sgcId = $submission->sid . '.' . ($submission->version_number ?? 1);

            // Extract raw PMIDs from evidence array
            $evidence = json_decode($submission->evidence);
            if (!is_array($evidence) || empty($evidence)) {
                continue;
            }

            // Build raw PMID string from evidence entries
            $rawPmids = [];
            foreach ($evidence as $item) {
                if (is_string($item)) {
                    $rawPmids[] = $item;
                } elseif (is_object($item) && isset($item->pmid)) {
                    $rawPmids[] = $item->pmid;
                }
            }

            if (empty($rawPmids)) {
                continue;
            }

            $rawString = implode(',', $rawPmids);
            $result = PmidNormalizer::normalize($rawString);

            $normalized = !empty($result['pmids']) ? implode(',', $result['pmids']) : null;
            $issuesJson = !empty($result['issues']) ? json_encode($result['issues']) : null;

            if ($issuesJson) {
                $issueCount++;
                Log::info("Migration: PMID issues found on submission {$sgcId}: {$issuesJson}");
            }

            // Update submission with normalized data
            $updateData = [
                'normalized_pmids' => $normalized,
                'pmid_issues' => $issuesJson,
            ];

            // Clear invalid_pmid error if normalization resolved the issues
            // (i.e., we still have valid PMIDs after normalization)
            $submissionErrors = json_decode($submission->submission_errors);
            if ($submissionErrors && isset($submissionErrors->invalid_pmid) && !empty($result['pmids'])) {
                unset($submissionErrors->invalid_pmid);
                $updateData['submission_errors'] = json_encode($submissionErrors);
                $errorsClearedCount++;
            }

            // Update the evidence array to contain only normalized PMIDs
            $normalizedEvidence = array_map(fn($pmid) => $pmid, $result['pmids']);
            $updateData['evidence'] = json_encode($normalizedEvidence);

            // Update submission_data->evidence with normalized values
            $submissionData = json_decode($submission->submission_data);
            if ($submissionData) {
                $submissionData->evidence = array_map(
                    fn($pmid) => (object)['pmid' => $pmid],
                    $result['pmids']
                );
                $updateData['submission_data'] = json_encode($submissionData);
            }

            DB::table('submissions')
                ->where('id', $submission->id)
                ->update($updateData);

            // Re-synchronize pubmed_submission pivot table
            DB::table('pubmed_submission')
                ->where('submission_id', $submission->id)
                ->delete();

            foreach ($result['pmids'] as $pmid) {
                $pubmed = $pubmedLookup->get($pmid);
                if ($pubmed) {
                    DB::table('pubmed_submission')->insert([
                        'pubmed_id' => $pubmed->id,
                        'submission_id' => $submission->id,
                    ]);
                }
            }

            $normalizedCount++;
        }

        Log::info("Migration complete: Normalized {$normalizedCount} submissions, {$issueCount} had issues, {$errorsClearedCount} invalid_pmid errors cleared.");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('normalized_pmids');
            $table->dropColumn('pmid_issues');
        });
    }
};
