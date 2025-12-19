#!/usr/bin/env php
<?php
/**
 * Export ClinGen submissions (GENCC:000102) from database in Downloads format
 *
 * This script exports all submissions for submitter GENCC:000102 in the same
 * format as the Downloads feature in the web interface.
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Submission;
use App\Models\Submitter;

// Map SOP version to assertion criteria URL
function getSopUrl($sopVersion) {
    $sopMap = [
        'SOP4' => 'https://clinicalgenome.org/docs/gene-disease-validity-sop-version-4/',
        'SOP5' => 'https://clinicalgenome.org/docs/gene-disease-validity-sop-version-5/',
        'SOP6' => 'https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedures-version-6/',
        'SOP7' => 'https://clinicalgenome.org/docs/summary-of-updates-to-the-clingen-gene-clinical-validity-curation-sop-version-7/',
        'SOP8' => 'https://clinicalgenome.org/docs/summary-of-updates-to-the-clingen-gene-clinical-validity-curation-sop-version-8/',
        'SOP9' => 'https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedure-version-9/',
        'SOP10' => 'https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedures-version-10/',
        'SOP11' => 'https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedures-version-11/',
    ];

    return $sopMap[$sopVersion] ?? 'https://www.clinicalgenome.org/docs/?doc-type=curation-activity-procedures&curation-procedure=gene-disease-validity';
}

// Load SOP mapping from ClinGen source CSV
echo "Loading SOP mapping from ClinGen source file...\n";
$sopMapping = [];
$csvPath = __DIR__ . '/../../data/Clingen-Gene-Disease-Summary.csv';

if (file_exists($csvPath)) {
    $fp_csv = fopen($csvPath, 'r');

    // Skip header rows (first 6 rows)
    for ($n = 0; $n < 6; $n++) {
        fgetcsv($fp_csv);
    }

    // Build mapping of gene-disease-moi to SOP version
    while (($data = fgetcsv($fp_csv)) !== false) {
        $geneId = $data[1];      // HGNC ID
        $diseaseId = $data[3];   // Disease ID
        $moi = $data[4];         // Mode of Inheritance
        $sopVersion = $data[5];  // SOP version

        $key = "{$geneId}|{$diseaseId}|{$moi}";
        $sopMapping[$key] = $sopVersion;
    }

    fclose($fp_csv);
    echo "Loaded " . count($sopMapping) . " SOP mappings\n";
} else {
    echo "WARNING: ClinGen source CSV not found at {$csvPath}\n";
    echo "Assertion criteria URLs will be taken from database only\n";
}

// Find the submitter
$submitter = Submitter::where('curie', 'GENCC:000102')->first();

if (!$submitter) {
    echo "ERROR: Submitter GENCC:000102 not found\n";
    exit(1);
}

echo "Found submitter: " . $submitter->name . " (ID: " . $submitter->id . ")\n";

// Get all PUBLISHED submissions for this submitter
// Only include submissions that are processed AND published (status = 'published')
// Exclude unpublished submissions (status = 'unpublished') - these are no longer active
$submissions = Submission::where('submitter_id', $submitter->id)
    ->where('status', 'published')  // Only published submissions
    ->with(['gene', 'disease', 'inheritance', 'classification', 'pubmeds'])
    ->get();

echo "Found " . $submissions->count() . " published submissions\n";

// Output file
$outputFile = __DIR__ . '/../../data/clingen/database_submissions_export.tsv';

// Open output file
$fp = fopen($outputFile, 'w');

// Column headers - include SGC ID for mapping
$headers = [
    'sgc_id',        // Add SGC ID as first column
    'local_id',
    'gene_id',
    'disease_id',
    'mode_of_inheritance',
    'submitter_id',
    'classification_id',
    'classification',
    'report_date',
    'public_report_url',
    'public_note',   // Separate public note
    'private_note',  // Separate private note
    'pubmed_ids',
    'assertion_criteria_url'
];

fputcsv($fp, $headers, "\t");

// Process each submission
foreach ($submissions as $submission) {
    // Format date as YYYY/MM/DD - match UI logic exactly
    $reportDate = '';

    // Use submission_data as the authoritative source
    $displayDate = $submission->submission_data?->report?->display_date ?? null;

    if ($displayDate) {
        try {
            $date = new DateTime($displayDate);
            $reportDate = $date->format('Y/m/d');
        } catch (Exception $e) {
            $reportDate = '';
        }
    }

    // Get PMIDs - use evidence array if available (matches UI)
    $pmids = '';
    if (is_array($submission->evidence)) {
        $sortedPmids = $submission->evidence;
        sort($sortedPmids, SORT_NUMERIC);
        $pmids = implode(', ', $sortedPmids);
    } else {
        $sortedPmids = $submission->pubmeds->pluck('pmid')->sort(SORT_NUMERIC)->values()->all();
        $pmids = implode(', ', $sortedPmids);
    }

    // Get assertion criteria URL from submission_data
    $assertionCriteriaUrlRaw = $submission->submission_data?->criteria?->url ?? '';
    $assertionCriteriaUrl = is_array($assertionCriteriaUrlRaw) ? implode(' ', $assertionCriteriaUrlRaw) : (string)$assertionCriteriaUrlRaw;

    // If assertion_criteria_url is empty or default, try to get it from SOP mapping
    $defaultUrl = 'https://www.clinicalgenome.org/docs/?doc-type=curation-activity-procedures&curation-procedure=gene-disease-validity';
    if (empty($assertionCriteriaUrl) || $assertionCriteriaUrl === $defaultUrl) {
        // Build key from gene-disease-moi
        $geneId = $submission->gene ? $submission->gene->hgnc_id : '';
        $diseaseId = $submission->disease ? $submission->disease->curie : '';
        $moiId = $submission->inheritance ? $submission->inheritance->curie : '';

        if ($geneId && $diseaseId) {
            // Try exact match first with MOI
            if ($moiId) {
                $key = "{$geneId}|{$diseaseId}|{$moiId}";
                if (isset($sopMapping[$key])) {
                    $sopVersion = $sopMapping[$key];
                    $assertionCriteriaUrl = getSopUrl($sopVersion);
                }
            }

            // If no match, try without MOI (find any match for this gene-disease pair)
            if (empty($assertionCriteriaUrl) || $assertionCriteriaUrl === $defaultUrl) {
                $keyPrefix = "{$geneId}|{$diseaseId}|";
                foreach ($sopMapping as $mapKey => $sopVersion) {
                    if (strpos($mapKey, $keyPrefix) === 0) {
                        $assertionCriteriaUrl = getSopUrl($sopVersion);
                        break;
                    }
                }
            }
        }
    }

    // Get public report URL from submission_data
    $publicReportUrlRaw = $submission->submission_data?->report?->ext_url ?? '';
    $publicReportUrl = is_array($publicReportUrlRaw) ? implode(' ', $publicReportUrlRaw) : (string)$publicReportUrlRaw;

    // Get notes - separate public and private
    $notesRaw = $submission->submission_data?->notes ?? '';

    $publicNote = '';
    $privateNote = '';

    // Handle notes as object, array, or string
    if (is_object($notesRaw)) {
        // Notes is typically an object with 'display' and 'private' properties
        $publicNote = (string)($notesRaw->display ?? '');
        $privateNote = (string)($notesRaw->private ?? '');
    } elseif (is_array($notesRaw)) {
        $publicNote = implode(' ', $notesRaw);
    } else {
        $publicNote = (string)$notesRaw;
    }

    $row = [
        (string)($submission->sid ?? ''),  // SGC ID
        (string)($submission->local_key ?? ''),  // local_id (UUID)
        (string)($submission->gene ? $submission->gene->hgnc_id : ''),
        (string)($submission->disease ? $submission->disease->curie : ''),
        (string)($submission->inheritance ? $submission->inheritance->curie : ''),
        (string)$submitter->curie,
        (string)($submission->classification ? $submission->classification->curie : ''),
        (string)($submission->classification ? $submission->classification->name : ''),
        (string)$reportDate,
        (string)$publicReportUrl,
        (string)$publicNote,
        (string)$privateNote,
        (string)$pmids,
        (string)$assertionCriteriaUrl
    ];

    fputcsv($fp, $row, "\t");
}

fclose($fp);

echo "Export complete: $outputFile\n";
echo "Total records exported: " . $submissions->count() . "\n";

// Also export unpublished submissions (just local_id) for republish detection
$unpublishedSubmissions = Submission::where('submitter_id', $submitter->id)
    ->where('status', 'unpublished')
    ->get(['local_key']);

$unpublishedOutputFile = __DIR__ . '/../../data/clingen/database_unpublished_local_ids.txt';
$fpUnpub = fopen($unpublishedOutputFile, 'w');

foreach ($unpublishedSubmissions as $sub) {
    fwrite($fpUnpub, ($sub->local_key ?? '') . "\n");
}

fclose($fpUnpub);

echo "Unpublished local IDs exported: $unpublishedOutputFile\n";
echo "Total unpublished: " . $unpublishedSubmissions->count() . "\n";
