<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PmidNormalizer
{
    /**
     * Normalize raw PMID string(s) into an array of valid PMIDs and issues.
     *
     * @param string|null $raw Raw PMID input
     * @return array ['pmids' => string[], 'issues' => [['value' => string, 'reason' => string]]]
     */
    public static function normalize(?string $raw): array
    {
        // Step 1: If null or empty after trimming, return empty
        if ($raw === null || trim($raw) === '') {
            return ['pmids' => [], 'issues' => []];
        }

        $validPmids = [];
        $issues = [];

        // Step 2: Replace non-breaking spaces with regular spaces
        $val = str_replace("\xC2\xA0", ' ', $raw);

        // Step 3: Split on commas, semicolons, underscores, and whitespace
        $parts = preg_split('/[,;_\s]+/', $val, -1, PREG_SPLIT_NO_EMPTY);

        // Step 4: Process each part
        foreach ($parts as $part) {
            // Step 4a: Trim whitespace
            $trimmed = trim($part);

            // Step 4b: Save original for issue reporting
            $original = $trimmed;

            // Step 4c: Strip [PMID] suffix (case-insensitive)
            $trimmed = preg_replace('/\[PMID\]$/i', '', $trimmed);

            // Step 4d: Strip PMID: prefix (case-insensitive)
            if (stripos($trimmed, 'PMID:') === 0) {
                $trimmed = substr($trimmed, 5);
            }

            // Step 4e: Trim again after stripping
            $trimmed = trim($trimmed);

            // Step 4f: If literal "NULL" (case-insensitive) → add issue
            if (strtoupper($trimmed) === 'NULL') {
                $issues[] = ['value' => $original, 'reason' => 'literal_null'];
                continue;
            }

            // Step 4g: If empty after all stripping → skip
            if ($trimmed === '') {
                continue;
            }

            // Step 4h: If scientific notation → add issue
            if (preg_match('/\d+\.\d+E\+\d+/i', $trimmed)) {
                $issues[] = ['value' => $original, 'reason' => 'scientific_notation'];
                continue;
            }

            // Step 4i: If not purely numeric → add issue
            if (!preg_match('/^\d+$/', $trimmed)) {
                $issues[] = ['value' => $original, 'reason' => 'non_numeric'];
                continue;
            }

            // Step 4j: If intval is 0 → add issue
            if (intval($trimmed) === 0) {
                $issues[] = ['value' => $original, 'reason' => 'zero_value'];
                continue;
            }

            // Step 4k: Strip leading zeros and use the stripped version going forward
            $numericStr = ltrim($trimmed, '0');

            // If stripping changed the value, record a warning issue
            if ($numericStr !== $trimmed) {
                $issues[] = ['value' => $original, 'reason' => 'leading_zeros_stripped'];
                $trimmed = $numericStr;
            }

            // Step 4l: If strlen > 8 → add issue
            if (strlen($numericStr) > 8) {
                $issues[] = ['value' => $original, 'reason' => 'exceeds_max_digits'];
                continue;
            }

            // Step 4m: If strlen < 7 → log warning (NOT an issue)
            if (strlen($numericStr) < 7) {
                Log::warning("Suspect PMID (fewer than 7 digits): {$trimmed}");
            }

            // Step 4n: Add to valid PMIDs list (with leading zeros stripped)
            $validPmids[] = $trimmed;
        }

        // Step 5: Remove duplicates
        $validPmids = array_unique($validPmids);

        // Step 6: Sort valid PMIDs ascending by numeric value
        usort($validPmids, fn($a, $b) => intval($a) - intval($b));

        // Step 7: Return result
        return [
            'pmids' => array_values($validPmids),
            'issues' => $issues
        ];
    }
}
