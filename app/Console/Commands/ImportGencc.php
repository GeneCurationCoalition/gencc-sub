<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Imports\GenccImport;
use Maatwebsite\Excel\Facades\Excel;

use Carbon\Carbon;

use Log;

use App\Models\Gene;
use App\Models\Disease;
use App\Models\Inheritance;
use App\Models\Classification;
use App\Models\Submission;
use App\Models\Job;
use App\Models\Submitter;
use App\Models\Pubmed;

class ImportGencc extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:gencc';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Track UUIDs that had date issues and were corrected
     *
     * @var array
     */
    private $dateCorrectionLog = [];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $worksheets = Excel::toCollection(new GenccImport, base_path() . '/data/gencc-submissions.xlsx');

        $firstsheet = $worksheets[0];

        $records = Submitter::all()->pluck('curie');

        foreach ($records as $record)
        {

            $submitter = Submitter::curie($record)->first();

            echo "$record \n";

            $coordinator = $submitter->users->first();

            $rows = $firstsheet->where('submitter_curie', $record);

            $job = new Job([
                    'friendly' => 'GenCC Import ' . Carbon::now()->format('Y-m-d'),
                    'type' => Job::TYPE_GENCC_IMPORT,
                    'user_id' => $coordinator->id ?? 1,
                    'submitter_id' => $submitter->id,
                    // created_at is auto-set by Laravel
                    'status' => Job::STATUS_PROCESSED,
            ]);
            $job->save();

            // Track most recent evaluation date for the job (to use as released_at)
            $most_recent_evaluation_date = null;

            // Track processed submissions for this import job
            $processedSubmissions = [];

            // process the rows
            $totalRows = $rows->count();
            $processedRows = 0;

            foreach ($rows as $row)
            {
                try {

                    // Get SGC ID and version number from separate columns
                    $uuid = $row['sgc_id'];
                    $versionNumber = (int) ($row['version_number'] ?? 1);

                    $gene = Gene::hgnc_id($row['gene_curie'])->first();
                    if (!$gene) {
                        throw new \Exception("Gene not found: {$row['gene_curie']}");
                    }

                    // Normalized disease (always MONDO)
                    $disease = Disease::curie($row['disease_curie'])->first();
                    if (!$disease) {
                        throw new \Exception("Disease not found: {$row['disease_curie']}");
                    }

                    // Original disease as submitted (could be MONDO, OMIM, or Orphanet)
                    // Use disease_original_curie column and do DIRECT lookup to preserve the original disease type
                    // Do NOT use rosetta() here as it resolves to MONDO
                    $original_disease = Disease::curie($row['disease_original_curie'])->first();

                    // If not found, fall back to the normalized disease
                    if (!$original_disease) {
                        $original_disease = $disease;
                        $this->warn("  Original disease not found for {$row['disease_original_curie']}, using normalized disease {$disease->curie}");
                    }

                    $classification = Classification::curie($row['classification_curie'])->first();
                    if (!$classification) {
                        throw new \Exception("Classification not found: {$row['classification_curie']}");
                    }

                    $inheritance = Inheritance::curie($row['moi_curie'])->first();
                    if (!$inheritance) {
                        throw new \Exception("Inheritance not found: {$row['moi_curie']}");
                    }

                    //if (!empty($row['submitted_as_pmids']))
                    //    echo $row['submitted_as_pmids'] . "\n";

                    $pmids = $this->process_pmids($row['submitted_as_pmids']);

                    // Validate and fix dates (handle typos like 3016 -> 2016)
                    // Use submitted_run_date as fallback for invalid report_date
                    $publish_date = $this->validate_and_fix_date($row['submitted_run_date'], $uuid);
                    $report_date = $this->validate_and_fix_date($row['submitted_as_date'], $uuid, $publish_date);

                    $submission = new Submission([
                        'type' => Submission::TYPE_GENCC_IMPORT,
                        'friendly' => $uuid,
                        'local_key' => $row['submitted_as_submission_id'],
                        'job_id' => $job->id,
                        'user_id' => $coordinator->id ?? 1,
                        'gene_id' => $gene->id,
                        'disease_id' => $disease->id,
                        'original_disease_id' => $original_disease->id,
                        'inheritance_id' => $inheritance->id,
                        'submitter_id' => $submitter->id,
                        'classification_id' => $classification->id,
                        'mechanism_id' => null,
                        'evidence' => (!empty($pmids) ? explode(',', $pmids) : []),
                        // created_at is auto-set by Laravel
                        'publish_date' => $publish_date,
                        'report_date' => $report_date,
                        'report_url' => $row['submitted_as_public_report_url'],
                        'version' => '1.0.0',
                        'status' => Submission::STATUS_PUBLISHED
                    ]);

                    $evidence = [];
                    if (!empty($pmids)) {
                        $list = explode(',', $pmids);
                        foreach ($list as $item) {
                            $trimmed = trim($item);
                            if (empty($trimmed))
                                continue;
                            $evidence[] = ['pmid' => $trimmed];
                        }
                    }

                    // Fix dates in display strings too
                    $display_date = $this->fix_date_string($row['submitted_as_date']);

                    $submission->original_submission_data = [
                        "moi" => ["id" => $row['submitted_as_moi_id'], "name" => $row['submitted_as_moi_name']],
                        "submission_id" => $row['submitted_as_submission_id'],
                        "submission_label" => '',
                        "local_key" => $row['submitted_as_submission_id'],
                        "gene" => ["id" => $row['submitted_as_hgnc_id'], "symbol" => $row['submitted_as_hgnc_symbol']],
                        "type" => "Reserved",
                        "notes" => ["display" => $row['submitted_as_notes'], "private" => ""],
                        "report" => ["ext_url" => $row['submitted_as_public_report_url'], "display_date" => $display_date],
                        "disease" => ["id" => $row['submitted_as_disease_id'], "name" => $row['submitted_as_disease_name']],
                        "version" => ["display" => "1.0", "reasons" => ["LEGACY_IMPORT"], "internal" => "1.0.0.0", "description" => "Imported from gencc-search"],
                        "criteria" => ["url" => $row['submitted_as_assertion_criteria_url'], "name" => ""],
                        "evidence" => $evidence,
                        "workflow" => ["evaluation_date" => $report_date, "publish_date" => $publish_date],
                        "contributors" => ["primary" => ["id" => "", "name" => ""]],
                        "classification" => ["id" => $row['submitted_as_classification_id'], "name" => $row['submitted_as_classification_name']],
                        "mechanism" => ["id" => "", "name" => "", "comment" => ""],
                        "additional_information" => ['submitter_curie' => $row['submitter_curie'], 'submitter_title' => $row['submitter_title'],
                            'submitted_as_submission_id' => $row['submitted_as_submission_id']]
                    ];

                    $submission->submission_data = [
                        "moi" => ["id" => $row['moi_curie'], "name" => $row['moi_title']],
                        "submission_id" => '',
                        "submission_label" => $uuid,
                        "local_key" => $row['submitted_as_submission_id'],
                        "gene" => ["id" => $row['gene_curie'], "symbol" => $row['gene_symbol']],
                        "type" => "Reserved",
                        "notes" => ["display" => $row['submitted_as_notes'], "private" => ""],
                        "report" => ["ext_url" => $row['submitted_as_public_report_url'], "display_date" => $display_date],
                        "disease" => ["id" => $row['disease_original_curie'], "name" => $row['disease_original_title']],
                        "version" => ["display" => "1.0", "reasons" => ["LEGACY_IMPORT"], "internal" => "1.0.0.0", "description" => "Imported from gencc-search"],
                        "criteria" => ["url" => $row['submitted_as_assertion_criteria_url'], "name" => ""],
                        "evidence" => $evidence,
                        "workflow" => ["evaluation_date" => $report_date, "publish_date" => $publish_date],
                        "contributors" => ["primary" => ["id" => "", "name" => ""]],
                        "classification" => ["id" => $row['classification_curie'], "name" => $row['classification_title']],
                        "mechanism" => ["id" => "", "name" => "", "comment" => ""],
                        "additional_information" => ['submitter_curie' => $row['submitter_curie'], 'submitter_title' => $row['submitter_title'],
                            'submitted_as_submission_id' => $row['submitted_as_submission_id']],
                        "search_row_id" => $row['rowid'] ?? null,
                    ];


                    // the date can get tricky due to excels auto format
                    /*if (is_numeric($row['date']))
                        $date = Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row['date']));
                    else
                    {
                        try {
                            $date = Carbon::parse($row['date']);
                        } catch (InvalidFormatException $_) {
                            $date = null;
                        }
                    }*/

                    // Set timestamps to historical dates to preserve import accuracy
                    $submission->created_at = Carbon::parse($row['submitted_run_date']);
                    $submission->updated_at = Carbon::parse($row['submitted_run_date']);

                    $job->submissions()->save($submission);

                    // Track this submission as processed (imported = published)
                    $processedSubmissions[] = [
                        'sid' => $submission->sid,
                        'action' => 'published',
                        'classification_id' => $classification->id
                    ];

                    // Track most recent evaluation date (submitted_as_date) for released_at
                    $evaluation_date = $report_date;
                    if ($most_recent_evaluation_date === null || $evaluation_date->gt($most_recent_evaluation_date)) {
                        $most_recent_evaluation_date = $evaluation_date;
                    }

                    // add evidence to the pubmed list and pivot table
                    // Disable touching to preserve historical timestamps during import
                    $submission::withoutTouching(function () use ($submission) {
                        foreach ($submission->evidence as $evidence) {
                            $pubmed = Pubmed::firstOrCreate(['pmid' => $evidence, 'uid' => $evidence],
                                ['status' => Pubmed::STATUS_INITIALIZING]);

                            if ($pubmed === null)
                                continue;

                            $submission->pubmeds()->attach($pubmed->id);
                        }
                    });

                    // Update progress counter
                    $processedRows++;
                    if ($processedRows % 5 == 0 || $processedRows == $totalRows) {
                        $this->getOutput()->write("\r  Progress: {$processedRows}/{$totalRows}");
                    }
                }
                catch (\Exception $e) {
                    $this->error("Error processing spreadsheet row with SGC ID: {$row['sgc_id']}");
                    $this->error($e);
                }
            }

            // Add newline after progress complete
            $this->getOutput()->write("\n");

            // Set the job's released_at to the most recent submission evaluation date
            // NOTE: This is ONLY for the initial import job created during makeproddb
            // All subsequent jobs processed through the normal workflow will have released_at
            // set to now() by the JobStateMachine when they are completed
            if ($most_recent_evaluation_date !== null) {
                $job->released_at = $most_recent_evaluation_date;
                $job->save(['timestamps' => false]);
                $this->info("  Set job {$job->slug} released_at to {$most_recent_evaluation_date->toDateString()} (initial import historical date)");
            }

            // Save the processed submissions list to the job for audit history
            if (!empty($processedSubmissions)) {
                $job->processed_submission_ids = $processedSubmissions;
                $job->save(['timestamps' => false]);
                $this->info("  Tracked " . count($processedSubmissions) . " imported submissions in job {$job->slug}");
            }
        }

        // Backfill released_at for all imported submissions based on their updated_at timestamps
        $this->info("Backfilling released_at timestamps from updated_at...");
        $this->call('backfill:released-at');

        // Report date corrections if any occurred
        if (!empty($this->dateCorrectionLog)) {
            $this->info("\n=== Date Correction Report ===");
            $this->warn("Found " . count($this->dateCorrectionLog) . " records with invalid report_date values");
            $this->warn("These records had their report_date replaced with submitted_run_date:");
            foreach ($this->dateCorrectionLog as $entry) {
                $this->line("  - {$entry['uuid']}: '{$entry['original']}' → '{$entry['corrected']}'");
            }
            $this->info("=== End Date Correction Report ===\n");
        }

        // Post-import: Process all unique PMIDs from submissions
        $this->info("\n=== Processing PubMed IDs ===");

        // Get all unique PMIDs that need processing (status = INITIALIZING)
        // Filter out invalid PMIDs (0, empty, or non-numeric)
        $pmidsNeedingSummary = Pubmed::where('status', Pubmed::STATUS_INITIALIZING)
            ->whereNotNull('pmid')
            ->where('pmid', '!=', '')
            ->where('pmid', '!=', '0')
            ->whereRaw('pmid REGEXP \'^[0-9]+$\'')
            ->pluck('pmid')
            ->toArray();

        if (!empty($pmidsNeedingSummary)) {
            $this->info("Found " . count($pmidsNeedingSummary) . " PMIDs needing summary data");
            $this->info("Fetching PubMed summary data (batches of 200)...");
            Pubmed::query_summary_batch($pmidsNeedingSummary);
            $this->info("\n=== PubMed Processing Complete ===\n");
        } else {
            $this->info("No PMIDs need summary data");
            $this->info("=== PubMed Processing Complete ===\n");
        }
    }


    /**
     * The original GenCC spreadsheeds did not enforce a format, so the PMID
     * list is all over the place.  Some use ; as a separator.  Some include
     * [PMID].  Some include _.  This function attempts to clean it all up.
     */
    public function process_pmids($list)
    {
        if (empty(trim($list)) || $list == "NULL")
            return "";

        $matches = [];

        $stat = preg_match_all('/[\s,]*([0-9]+)[[a-zA-Z\[\]_,;\s]*/', $list, $matches);

        if ($stat)
            return implode(',', $matches[1]);

        // Suppress error output for specific known values like "neant"
        if (strtolower(trim($list)) !== 'neant') {
            echo "PMID ERROR:  $list \n";
        }

        return "";
    }

    /**
     * Validate and fix date values, handling common typos like 3016 -> 2016
     * Returns a Carbon instance, uses fallback if provided, or throws exception if date is invalid
     *
     * @param string $date_string The date string to validate
     * @param string $uuid The UUID for error reporting
     * @param Carbon|null $fallback_date Optional fallback date to use if primary date is invalid
     * @return Carbon|null
     */
    private function validate_and_fix_date($date_string, $uuid, $fallback_date = null)
    {
        if (empty($date_string)) {
            return $fallback_date;
        }

        // Fix common year typos (3016 -> 2016, etc)
        $fixed_date = $this->fix_date_string($date_string);

        try {
            $date = Carbon::parse($fixed_date);

            // Validate year is reasonable
            // MySQL TIMESTAMP range: 1970-01-01 to 2038-01-19
            // We allow slightly broader range: 1970 to current year + 10
            $current_year = (int) date('Y');
            if ($date->year < 1970 || $date->year > ($current_year + 10)) {
                if ($fallback_date !== null) {
                    // Log the correction silently (report at end)
                    $reason = $date->year < 1970
                        ? "(before MySQL TIMESTAMP range)"
                        : "(invalid future year)";
                    $this->dateCorrectionLog[] = [
                        'uuid' => $uuid,
                        'original' => $date_string . ' ' . $reason,
                        'corrected' => $fallback_date->toDateString()
                    ];
                    return $fallback_date;
                } else {
                    // Auto-correction worked, no fallback needed
                    // Only log if the fix was significant (not just whitespace/format)
                    if ($date_string !== $fixed_date) {
                        $this->dateCorrectionLog[] = [
                            'uuid' => $uuid,
                            'original' => $date_string,
                            'corrected' => $fixed_date
                        ];
                    }
                }
            }

            return $date;
        } catch (\Exception $e) {
            if ($fallback_date !== null) {
                // Log the correction silently (report at end)
                $this->dateCorrectionLog[] = [
                    'uuid' => $uuid,
                    'original' => $date_string,
                    'corrected' => $fallback_date->toDateString()
                ];
                return $fallback_date;
            }

            $this->error("Failed to parse date '{$date_string}' for UUID {$uuid}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fix common date string typos (e.g., 3016 -> 2016)
     */
    private function fix_date_string($date_string)
    {
        if (empty($date_string)) {
            return $date_string;
        }

        // Fix year typo: 3016 -> 2016, 3017 -> 2017, etc.
        // Pattern: 30XX where XX is 00-99
        $fixed = preg_replace('/\b(30)(\d{2})\b/', '20$2', $date_string);

        return $fixed;
    }
}