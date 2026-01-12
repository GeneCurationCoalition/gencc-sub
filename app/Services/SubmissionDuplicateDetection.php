<?php

namespace App\Services;

use App\Models\Submission;
use Illuminate\Support\Collection;

/**
 * Service for detecting duplicate submissions.
 *
 * A duplicate is defined as a submission with the same:
 * - submitter_id
 * - gene_id
 * - original_disease_id
 * - inheritance_id
 *
 * Blocking statuses prevent creation of a new submission.
 * Warning statuses (unpublished) allow creation but return a warning.
 */
class SubmissionDuplicateDetection
{
    /**
     * Check for duplicate submissions for a given submitter.
     *
     * @param int $submitterId The submitter organization ID
     * @param int|null $geneId The gene ID to check
     * @param int|null $originalDiseaseId The original disease ID to check
     * @param int|null $inheritanceId The inheritance/MOI ID to check
     * @param int|null $excludeSubmissionId Exclude this submission from check (for updates)
     * @return array ['has_blocking_duplicate' => bool, 'has_unpublished_duplicate' => bool, 'duplicates' => Collection]
     */
    public static function checkForDuplicates(
        int $submitterId,
        ?int $geneId,
        ?int $originalDiseaseId,
        ?int $inheritanceId,
        ?int $excludeSubmissionId = null
    ): array {
        // If any key field is null, we can't have a meaningful duplicate
        if ($geneId === null || $originalDiseaseId === null || $inheritanceId === null) {
            return [
                'has_blocking_duplicate' => false,
                'has_unpublished_duplicate' => false,
                'duplicates' => collect(),
            ];
        }

        // Find all submissions with matching key fields for this submitter
        // For released statuses (published/unpublished), only check live submissions (is_live=true)
        // For pending statuses (draft/submitted), check all regardless of is_live
        $query = Submission::where('submitter_id', $submitterId)
            ->where('gene_id', $geneId)
            ->where('original_disease_id', $originalDiseaseId)
            ->where('inheritance_id', $inheritanceId)
            ->where(function ($q) {
                // Pending statuses - check all (no is_live filter)
                $q->whereIn('status', self::getPendingStatuses())
                    // Released statuses - only check live submissions
                    ->orWhere(function ($subQ) {
                        $subQ->whereIn('status', self::getReleasedStatuses())
                            ->where('is_live', true);
                    });
            });

        // Exclude self when checking for updates
        if ($excludeSubmissionId !== null) {
            $query->where('id', '!=', $excludeSubmissionId);
        }

        $duplicates = $query->get(['id', 'sid', 'status', 'gene_id', 'original_disease_id', 'inheritance_id']);

        // Separate blocking vs warning duplicates
        $blockingDuplicates = $duplicates->filter(function ($sub) {
            return in_array($sub->status, self::getBlockingStatuses());
        });

        $unpublishedDuplicates = $duplicates->filter(function ($sub) {
            return in_array($sub->status, self::getWarningStatuses());
        });

        return [
            'has_blocking_duplicate' => $blockingDuplicates->isNotEmpty(),
            'has_unpublished_duplicate' => $unpublishedDuplicates->isNotEmpty(),
            'duplicates' => $duplicates,
            'blocking_duplicates' => $blockingDuplicates,
            'unpublished_duplicates' => $unpublishedDuplicates,
        ];
    }

    /**
     * Check for duplicates in a batch of submissions (for file upload validation).
     *
     * This method efficiently checks multiple submissions at once, also detecting
     * duplicates within the batch itself.
     *
     * @param int $submitterId The submitter organization ID
     * @param array $submissions Array of ['gene_id' => int, 'original_disease_id' => int, 'inheritance_id' => int, 'row_index' => int, 'exclude_submission_id' => int|null]
     * @return array Array of duplicate results keyed by row_index
     */
    public static function checkForDuplicatesBatch(
        int $submitterId,
        array $submissions
    ): array {
        $results = [];

        // First, check for duplicates within the batch itself
        $batchDuplicates = self::findIntraBatchDuplicates($submissions);

        // Build a query to check all combinations against existing submissions
        $existingDuplicates = self::findExistingDuplicates($submitterId, $submissions);

        // Merge results for each submission
        foreach ($submissions as $submission) {
            $rowIndex = $submission['row_index'];
            $key = self::makeKey(
                $submission['gene_id'],
                $submission['original_disease_id'],
                $submission['inheritance_id']
            );

            $result = [
                'has_blocking_duplicate' => false,
                'has_unpublished_duplicate' => false,
                'has_batch_duplicate' => false,
                'duplicates' => collect(),
                'blocking_duplicates' => collect(),
                'unpublished_duplicates' => collect(),
                'batch_duplicate_rows' => [],
            ];

            // Check for intra-batch duplicates
            if (isset($batchDuplicates[$key])) {
                $otherRows = array_filter($batchDuplicates[$key], fn($r) => $r !== $rowIndex);
                if (!empty($otherRows)) {
                    $result['has_batch_duplicate'] = true;
                    $result['batch_duplicate_rows'] = array_values($otherRows);
                }
            }

            // Check for existing submission duplicates
            if (isset($existingDuplicates[$key])) {
                $existingMatches = $existingDuplicates[$key];

                // Filter out self if exclude_submission_id is set
                if (isset($submission['exclude_submission_id']) && $submission['exclude_submission_id'] !== null) {
                    $existingMatches = $existingMatches->filter(function ($sub) use ($submission) {
                        return $sub->id !== $submission['exclude_submission_id'];
                    });
                }

                $blockingDuplicates = $existingMatches->filter(function ($sub) {
                    return in_array($sub->status, self::getBlockingStatuses());
                });

                $unpublishedDuplicates = $existingMatches->filter(function ($sub) {
                    return in_array($sub->status, self::getWarningStatuses());
                });

                $result['duplicates'] = $existingMatches;
                $result['blocking_duplicates'] = $blockingDuplicates;
                $result['unpublished_duplicates'] = $unpublishedDuplicates;
                $result['has_blocking_duplicate'] = $blockingDuplicates->isNotEmpty();
                $result['has_unpublished_duplicate'] = $unpublishedDuplicates->isNotEmpty();
            }

            $results[$rowIndex] = $result;
        }

        return $results;
    }

    /**
     * Get statuses that should block duplicate creation.
     *
     * @return array
     */
    public static function getBlockingStatuses(): array
    {
        return [
            Submission::STATUS_PUBLISHED,
            Submission::STATUS_DRAFT_NEW,
            Submission::STATUS_DRAFT_REPUBLISH,
            Submission::STATUS_DRAFT_UNPUBLISH,
            Submission::STATUS_SUBMITTED_NEW,
            Submission::STATUS_SUBMITTED_REPUBLISH,
            Submission::STATUS_SUBMITTED_UNPUBLISH,
        ];
    }

    /**
     * Get statuses that should warn but allow (unpublished).
     *
     * @return array
     */
    public static function getWarningStatuses(): array
    {
        return [
            Submission::STATUS_UNPUBLISHED,
        ];
    }

    /**
     * Get pending statuses (draft/submitted) - these always block regardless of is_live.
     *
     * @return array
     */
    public static function getPendingStatuses(): array
    {
        return [
            Submission::STATUS_DRAFT_NEW,
            Submission::STATUS_DRAFT_REPUBLISH,
            Submission::STATUS_DRAFT_UNPUBLISH,
            Submission::STATUS_SUBMITTED_NEW,
            Submission::STATUS_SUBMITTED_REPUBLISH,
            Submission::STATUS_SUBMITTED_UNPUBLISH,
        ];
    }

    /**
     * Get released statuses (published/unpublished) - these only block/warn if is_live=true.
     *
     * @return array
     */
    public static function getReleasedStatuses(): array
    {
        return [
            Submission::STATUS_PUBLISHED,
            Submission::STATUS_UNPUBLISHED,
        ];
    }

    /**
     * Format an error message for a blocking duplicate.
     *
     * @param Submission $duplicate The duplicate submission
     * @return string
     */
    public static function formatBlockingErrorMessage(Submission $duplicate): string
    {
        $statusDisplay = self::getStatusDisplayName($duplicate->status);
        return "Duplicate submission found. A submission with the same gene, disease, and mode of inheritance already exists ({$duplicate->sid}, status: {$statusDisplay}). Each submitter may only have one active submission per gene-disease-MOI combination.";
    }

    /**
     * Format a warning message for an unpublished duplicate.
     *
     * @param Collection $duplicates The unpublished duplicate submissions
     * @return string
     */
    public static function formatUnpublishedWarningMessage(Collection $duplicates): string
    {
        $sgcIds = $duplicates->pluck('sid')->join(', ');
        return "An unpublished submission exists with the same gene, disease, and mode of inheritance ({$sgcIds}). You may proceed, but consider republishing the existing submission instead of creating a new one.";
    }

    /**
     * Format a warning message for batch (intra-file) duplicates.
     *
     * @param array $otherRows Array of row numbers that are duplicates (already 1-indexed from spreadsheet)
     * @return string
     */
    public static function formatBatchDuplicateMessage(array $otherRows): string
    {
        // Row numbers are already correct from the spreadsheet (1-indexed)
        $rowList = implode(', ', $otherRows);
        return "Duplicate found within this file. Row(s) {$rowList} have the same gene, disease, and mode of inheritance combination.";
    }

    /**
     * Format grouped batch duplicates for consolidated error display.
     *
     * Takes multiple groups of duplicate rows and formats them as:
     * "Duplicate rows within file: (2067, 3377), (2205, 3375, 3376)"
     *
     * @param array $duplicateGroups Array of arrays, each containing row numbers that are duplicates of each other
     * @return string
     */
    public static function formatGroupedBatchDuplicateMessage(array $duplicateGroups): string
    {
        $groupStrings = [];
        foreach ($duplicateGroups as $group) {
            sort($group, SORT_NUMERIC);
            $groupStrings[] = '(' . implode(', ', $group) . ')';
        }
        return "Duplicate rows within file: " . implode(', ', $groupStrings) . ". Each group contains rows with the same gene, disease, and mode of inheritance combination.";
    }

    /**
     * Get a human-readable display name for a status.
     *
     * @param string $status
     * @return string
     */
    protected static function getStatusDisplayName(string $status): string
    {
        $names = [
            Submission::STATUS_PUBLISHED => 'Published',
            Submission::STATUS_DRAFT_NEW => 'Draft (New)',
            Submission::STATUS_DRAFT_REPUBLISH => 'Draft (Republish)',
            Submission::STATUS_DRAFT_UNPUBLISH => 'Draft (Unpublish)',
            Submission::STATUS_SUBMITTED_NEW => 'Submitted (New)',
            Submission::STATUS_SUBMITTED_REPUBLISH => 'Submitted (Republish)',
            Submission::STATUS_SUBMITTED_UNPUBLISH => 'Submitted (Unpublish)',
            Submission::STATUS_UNPUBLISHED => 'Unpublished',
        ];

        return $names[$status] ?? $status;
    }

    /**
     * Find duplicates within a batch of submissions.
     *
     * @param array $submissions
     * @return array Map of key => [row_indexes]
     */
    protected static function findIntraBatchDuplicates(array $submissions): array
    {
        $keyMap = [];

        foreach ($submissions as $submission) {
            // Skip if any key field is null
            if ($submission['gene_id'] === null ||
                $submission['original_disease_id'] === null ||
                $submission['inheritance_id'] === null) {
                continue;
            }

            $key = self::makeKey(
                $submission['gene_id'],
                $submission['original_disease_id'],
                $submission['inheritance_id']
            );

            if (!isset($keyMap[$key])) {
                $keyMap[$key] = [];
            }
            $keyMap[$key][] = $submission['row_index'];
        }

        // Only return entries with more than one row (actual duplicates)
        return array_filter($keyMap, fn($rows) => count($rows) > 1);
    }

    /**
     * Find existing submissions that match any of the given combinations.
     *
     * @param int $submitterId
     * @param array $submissions
     * @return array Map of key => Collection of matching submissions
     */
    protected static function findExistingDuplicates(int $submitterId, array $submissions): array
    {
        // Collect unique combinations to query
        $combinations = [];
        foreach ($submissions as $submission) {
            if ($submission['gene_id'] === null ||
                $submission['original_disease_id'] === null ||
                $submission['inheritance_id'] === null) {
                continue;
            }

            $key = self::makeKey(
                $submission['gene_id'],
                $submission['original_disease_id'],
                $submission['inheritance_id']
            );

            $combinations[$key] = [
                'gene_id' => $submission['gene_id'],
                'original_disease_id' => $submission['original_disease_id'],
                'inheritance_id' => $submission['inheritance_id'],
            ];
        }

        if (empty($combinations)) {
            return [];
        }

        // Build query for all combinations
        // For released statuses (published/unpublished), only check live submissions (is_live=true)
        // For pending statuses (draft/submitted), check all regardless of is_live
        $query = Submission::where('submitter_id', $submitterId)
            ->where(function ($q) {
                // Pending statuses - check all (no is_live filter)
                $q->whereIn('status', self::getPendingStatuses())
                    // Released statuses - only check live submissions
                    ->orWhere(function ($subQ) {
                        $subQ->whereIn('status', self::getReleasedStatuses())
                            ->where('is_live', true);
                    });
            })
            ->where(function ($q) use ($combinations) {
                foreach ($combinations as $combo) {
                    $q->orWhere(function ($subQ) use ($combo) {
                        $subQ->where('gene_id', $combo['gene_id'])
                            ->where('original_disease_id', $combo['original_disease_id'])
                            ->where('inheritance_id', $combo['inheritance_id']);
                    });
                }
            });

        $existingSubmissions = $query->get(['id', 'sid', 'status', 'gene_id', 'original_disease_id', 'inheritance_id']);

        // Group by key
        $result = [];
        foreach ($existingSubmissions as $sub) {
            $key = self::makeKey($sub->gene_id, $sub->original_disease_id, $sub->inheritance_id);
            if (!isset($result[$key])) {
                $result[$key] = collect();
            }
            $result[$key]->push($sub);
        }

        return $result;
    }

    /**
     * Create a unique key from gene, disease, and inheritance IDs.
     *
     * @param int $geneId
     * @param int $originalDiseaseId
     * @param int $inheritanceId
     * @return string
     */
    protected static function makeKey(int $geneId, int $originalDiseaseId, int $inheritanceId): string
    {
        return "{$geneId}-{$originalDiseaseId}-{$inheritanceId}";
    }
}
