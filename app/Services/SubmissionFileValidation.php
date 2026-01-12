<?php

namespace App\Services;

use App\Models\Disease;
use App\Models\Gene;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

use App\Models\Classification;
use App\Models\Inheritance;
use App\Models\Submitter;
use App\Models\Pubmed;
use App\Models\Submission;
use App\Services\SubmissionDuplicateDetection;

class SubmissionFileValidation
{
    const HEADER_ROW_NUM = 6;
    const FIRST_DATA_ROW = 13;
    const FATAL_ERROR_MESSAGE = 'This a fatal error that must be fixed and the file re-uploaded.';

    const SPREADSHEET_VALIDATION = 'spreadsheet';
    const HEADER_VALIDATION = 'header_row';
    const DATA_VALIDATION = 'data_row';
    const SEVERITY_FATAL = 'fatal';
    const SEVERITY_ERROR = 'error';

    // If you change this value, be sure to update JobItem.vue MAX_VALIDATION_RESULTS
    // Set to 0 to disable the limit and collect all validation errors
    const MAX_VALIDATION_RESULTS = 0;

    // Class-level cached data
    private static $valid_classifications = null;
    private static $valid_inheritances = null;
    private static $valid_submitters = null;
    private static $submitter_id = null;

    /**
     * Column mapping: an associative array of
     *      key = column name in spreadsheet,
     *      value = an associative array of
     *          'desc' = string description (required)
     *          'required' = boolean indicating whether the field is required or not (required)
     *          'regexp' = string regular expression used to validate the format of the data (optional)
     *          'unique' = boolean indicating that the value of this field must be unique for each entry when populated (optional)
     *          'is_date' = boolean indicating that the field is a date field (optional)
     *          'validator_method' = callable reference to a validation method that returns valid values (optional)
     *              Use either 'regexp' or 'validator_method' but not both.
     */
    private static array $COLUMN_MAP = [
        'sgc_id' => [
            'desc' => 'SGC ID',
            'required' => false,
            # SGC-##### or empty
            'regexp' => '/^(SGC-\d+)?$/',
            'unique' => true,
        ],
        'action' => [
            'desc' => 'Action',
            'required' => true,
            # Must be exactly one of: N (New), R (Republish), U (Unpublish) - case-insensitive
            'regexp' => '/^[NRU]$/i',
        ],
        'local_key' => [
            'desc' => 'Local Key',
            'required' => false,
        ],
        'hgnc_id' => [
            'desc' => 'HGNC ID',
            'required' => true,
            # number or HGNC:#####
            'regexp' => '/^(\d+|HGNC:\d+)$/',
            'validator_with_argument' => [
                'method' => [Gene::class, 'lookup_hgnc_id'],
                'message' => 'Invalid Gene ID',
            ],
        ],
        'hgnc_symbol' => [
            'desc' => 'Gene Symbol',
            'required' => false,
        ],
        'disease_id' => [
            'desc' => 'Disease ID (MONDO)',
            'required' => true,
            # MONDO:#####, OMIM:#### or ORPHA:######/Orphanet:######
            'regexp' => '/^(\d+|(MONDO|OMIM|ORPHA|Orphanet):\d+)$/i',
            'validator_with_argument' => [
                'method' => [Disease::class, 'rosetta'],
                'message' => 'Invalid Disease ID',
            ],
        ],
        'disease_name' => [
            'desc' => 'Disease Name',
            'required' => false,
        ],
        'moi_id' => [
            'desc' => 'Mode of Inheritance ID',
            'required' => true,
            'validator_method' => [self::class, 'get_valid_inheritances'],
        ],
        'moi_name' => [
            'desc' => 'Mode of Inheritance Name',
            'required' => false,
        ],
        'submitter_id' => [
            'desc' => 'Submitter ID',
            'required' => true,
            'validator_method' => [self::class, 'get_valid_submitters'],
        ],
        'submitter_name' => [
            'desc' => 'Submitter Name',
            'required' => false,
        ],
        'classification_id' => [
            'desc' => 'Classification ID',
            'required' => true,
            'validator_method' => [self::class, 'get_valid_classifications'],
        ],
        'classification_name' => [
            'desc' => 'Classification Name',
            'required' => false,
        ],
        'date' => [
            'desc' => 'Report Date',
            'required' => true,
            # YYYY/MM/DD or YYYY-MM-DD
            'regexp' => '/^\d{4}[\/-]\d{2}[\/\-]\d{2}$/',
            'is_date' => true,
        ],
        'public_report_url' => [
            'desc' => 'Public Report URL',
            'required' => false,
            # empty or http:// or https://
            'regexp' => '/^((http|https):\/{2}.*)?$/',
        ],
        'notes' => [
            'desc' => 'Notes',
            'required' => false,
        ],
        'pmids' => [
            'desc' => 'PubMed IDs',
            'required' => false,
            # empty or ###### or #####, ##### or #####,#####,#####
            'regexp' => '/^\s*(\d+(\s*[,;]\s*\d+)*)?\s*$/',
        ],
        'assertion_criteria_url' => [
            'desc' => 'Assertion Criteria URL',
            'required' => true,
            # http:// or https://
            'regexp' => '/^(http|https):\/{2}.*$/',
        ]
    ];

    /**
     * Initialize cached data from database (lazy initialization)
     */
    private static function initialize_cache(): void
    {
        if (self::$valid_classifications === null) {
            self::$valid_classifications = Classification::where('status', Classification::STATUS_ACTIVE)
                ->orderBy('curie')
                ->pluck('curie')
                ->toArray();
        }

        if (self::$valid_inheritances === null) {
            self::$valid_inheritances = Inheritance::where('status', Inheritance::STATUS_ACTIVE)
                ->orderBy('curie')
                ->pluck('curie')
                ->toArray();
        }

        if (self::$valid_submitters === null) {
            self::$valid_submitters = Submitter::where('id', self::$submitter_id)
                ->pluck('curie')
                ->toArray();
        }
    }

    public static function set_submitter_id($submitter_id): void
    {
        self::$submitter_id = $submitter_id;
    }

    /**
     * Reset all cached data. Used primarily for testing to ensure
     * fresh data is loaded between test runs.
     */
    public static function resetCache(): void
    {
        self::$valid_classifications = null;
        self::$valid_inheritances = null;
        self::$valid_submitters = null;
        self::$submitter_id = null;
    }

    public static function get_valid_submitters(): array
    {
        self::initialize_cache();
        return self::$valid_submitters;
    }

    /**
     * Get valid classification CURIEs
     * @return array
     */
    public static function get_valid_classifications(): array
    {
        self::initialize_cache();
        return self::$valid_classifications;
    }

    /**
     * Get valid inheritance (MOI) CURIEs
     * @return array
     */
    public static function get_valid_inheritances(): array
    {
        self::initialize_cache();
        return self::$valid_inheritances;
    }

    /**
     * Get all header column names
     * @return array
     */
    public static function get_column_names(): array
    {
        return array_keys(self::$COLUMN_MAP);
    }

    /**
     * Get all required column names
     */
    public static function get_required_columns(): array
    {
        return array_keys(array_filter(self::$COLUMN_MAP, function($column) {
            return $column['required'] === true;
        }));
    }

    /**
     * Get column descriptions for user-friendly error messages
     */
    public static function get_column_descriptions(): array
    {
        return array_keys(array_filter(self::$COLUMN_MAP, function($column) {
            return $column['desc'];
        }));
    }

    /**
     * Get a specific column description
     */
    public static function get_column_description(string $column): string
    {
        return self::$COLUMN_MAP[$column]['desc'];
    }

    /**
     * Check if a column is required
     * @return bool
     */
    public static function is_required_column(string $column): bool
    {
        return self::$COLUMN_MAP[$column]['required'];
    }

    /**
     * Get the column names that have 'unique: true'.
     * @return array
     */
    public static function get_unique_columns(): array
    {
        return array_keys(array_filter(self::$COLUMN_MAP, function($column) {
            return array_key_exists('unique', $column) && $column['unique'] === true;
        }));
    }

    public static function get_index(string $column): int
    {
        return array_search($column, array_keys(self::$COLUMN_MAP));
    }

    private static function parse_as_date($numeric_date): ?string
    {
        // the date can get tricky due to excels auto format
        if (is_numeric($numeric_date)) {
            $date = Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($numeric_date));
            $date = $date->format('Y-m-d');
        }
        else
        {
            try {
                $date = Carbon::parse($numeric_date)->format('Y-m-d');
            } catch (InvalidFormatException $_) {
                $date = null;
            }
        }
        return $date;
    }

    /**
     * Get helpful guidance message for a specific column
     *
     * @param string $column_name The column name
     * @return string Helpful guidance for the column
     */
    private static function get_column_guidance(string $column_name): string
    {
        $guidance = [
            'hgnc_id' => 'Must be a valid HGNC gene identifier (e.g., HGNC:1234). Verify at https://www.genenames.org/',
            'disease_id' => 'Must be a valid disease identifier from MONDO, OMIM, ORPHA, or Orphanet (e.g., MONDO:0001234, ORPHA:90044, Orphanet:90044). Single ID only.',
            'moi_id' => 'Must be a valid HPO mode of inheritance term (e.g., HP:0000006 for Autosomal dominant).',
            'classification_id' => 'Must be a valid GenCC classification (e.g., GENCC:100001 for Definitive).',
            'action' => 'Must be one of: N (New), R (Republish), or U (Unpublish).',
            'public_report_url' => 'Must be a valid URL starting with http:// or https://',
            'assertion_criteria_url' => 'Must be a valid URL to the assertion criteria documentation.',
            'date' => 'Must be a valid date in format YYYY-MM-DD or MM/DD/YYYY.',
        ];

        return $guidance[$column_name] ?? 'Please check the value and refer to https://thegencc.org/submission-directions';
    }

    /**
     * Group validation errors by message and collect rows that have each error
     *
     * @param array $validation_results Raw validation results (one per row)
     * @return array Grouped validation results (one per unique message with comma-separated rows)
     */
    private static function group_validation_errors(array $validation_results): array
    {
        $grouped = [];

        foreach ($validation_results as $error) {
            // Skip errors that don't have a message or row (like fatal errors)
            if (!isset($error['message'])) {
                $grouped[] = $error;
                continue;
            }

            $message = $error['message'];

            // Create a key that includes message and other identifying info (but not row)
            $key = md5($message . ($error['error_type'] ?? '') . ($error['severity'] ?? ''));

            if (!isset($grouped[$key])) {
                // First occurrence of this error - create new entry
                $grouped[$key] = $error;
                $grouped[$key]['rows'] = [];
            }

            // Add row number to the list if it exists
            if (isset($error['row'])) {
                $grouped[$key]['rows'][] = $error['row'];
            }
        }

        // Convert rows arrays to comma-separated strings
        $result = [];
        foreach ($grouped as $error) {
            if (isset($error['rows']) && !empty($error['rows'])) {
                // Sort row numbers
                sort($error['rows'], SORT_NUMERIC);

                // Keep rows as comma-separated string for frontend use
                $error['rows'] = implode(', ', $error['rows']);

                // Remove row-specific fields from grouped output
                unset($error['row']);
                unset($error['sgc_id']);
                unset($error['local_key']);
            } else {
                // No rows to display
                $error['rows'] = '';
            }
            $result[] = $error;
        }

        return $result;
    }

    public static function validate_spreadsheet($worksheet, $submitter_id, $skipPmidFetch = false, $progressCallback = null): array
    {
        $validation_results = [];

        // set submitter_id
        self::set_submitter_id($submitter_id);

        // Progress: Starting validation
        if ($progressCallback) {
            $progressCallback('Checking spreadsheet structure...');
        }

        // check that the row count has at least one data row
        $total_rows = count($worksheet);
        if ($total_rows < self::FIRST_DATA_ROW) {
            $validation_results[] = [
                'error_type' => 'minimum_rows_requirement',
                'severity' => self::SEVERITY_FATAL,
                'validation_type' => self::SPREADSHEET_VALIDATION,
                'message' => "The spreadsheet contains " . $total_rows .
                        " rows, but there must be a minimum of " . self::FIRST_DATA_ROW . ". " .
                        self::FATAL_ERROR_MESSAGE
            ];
            return $validation_results;
        }

        // build an associative array of unique columns that will add the
        // column data of each row in accumulate_unique_columns()
        $unique_columns = self::get_unique_columns();
        $unique_column_assoc = [];
        foreach ($unique_columns as $unique_column_key) {
            $unique_column_assoc[$unique_column_key] = [];
        }

        // Progress: Validating headers
        if ($progressCallback) {
            $progressCallback('Validating column headers...');
        }

        // validate the header row and data rows
        $row_num = 0;
        foreach ($worksheet as $row) {
            $row_num++;

            \Log::info("Row: " . $row_num . " Contents: " . implode(' ', $row));

            if ($row_num === self::HEADER_ROW_NUM) {
                $header_validation = self::validate_header_row($row);

                // All header errors are fatal - can't process until fixed
                if (!empty($header_validation)) {
                    $validation_results[] = $header_validation;
                    return $validation_results;
                }

                // Progress: Headers valid, starting data validation
                if ($progressCallback) {
                    $progressCallback('Validating data rows...');
                }
            }
            else if ($row_num >= self::FIRST_DATA_ROW) {

                // max validation errors hit (if limit is enabled)
                if (self::MAX_VALIDATION_RESULTS > 0 && count($validation_results) === self::MAX_VALIDATION_RESULTS) {
                    return self::group_validation_errors($validation_results);
                }

                // skip empty data rows
                if (!empty(implode('', $row)))
                    array_push($validation_results, ...self::validate_data_row($row, $row_num));

                // accumulate unique values - for those columns marked with 'unique => true'
                foreach ($unique_columns as $unique_column_key) {
                    // only add valued items
                    $unique_column_key_index = self::get_index($unique_column_key);
                    if (!empty($row[$unique_column_key_index])) {
                        $unique_column_assoc[$unique_column_key][] = $row[$unique_column_key_index];
                    }
                }
            }
        }

        // check uniqueness columns by summing all columns and
        // comparing to sum of unique entries in array
        foreach ($unique_columns as $unique_column_key) {
            $all_values_count = count($unique_column_assoc[$unique_column_key]);
            $unique_values_count = count(array_unique($unique_column_assoc[$unique_column_key]));
            if ($all_values_count != $unique_values_count) {
                $validation_results[] = [
                    'error_type' => 'unique_column_requirement',
                    'severity' => self::SEVERITY_ERROR,
                    'validation_type' => self::DATA_VALIDATION,
                    'message' => "Unique column requirement on column '{$unique_column_key}' not met."
                ];
            }
        }

        // Progress: Checking for duplicates
        if ($progressCallback) {
            $progressCallback('Checking for duplicate SGC IDs...');
        }

        // Check for duplicate SGC IDs within the spreadsheet
        $duplicate_sgc_id_validation = self::validate_duplicate_sgc_ids($worksheet);
        if (!empty($duplicate_sgc_id_validation)) {
            array_push($validation_results, ...$duplicate_sgc_id_validation);
        }

        // Progress: Validating SGC IDs
        if ($progressCallback) {
            $progressCallback('Validating SGC IDs against database...');
        }

        // Batch validate SGC IDs belong to correct submitter
        $sgc_id_validation = self::validate_sgc_ids_batch($worksheet, $submitter_id);
        if (!empty($sgc_id_validation)) {
            array_push($validation_results, ...$sgc_id_validation);
        }

        // Progress: Checking for duplicate gene-disease-MOI combinations
        if ($progressCallback) {
            $progressCallback('Checking for duplicate gene-disease-MOI combinations...');
        }

        // Batch validate for duplicate gene-disease-MOI combinations
        $duplicate_submission_validation = self::validate_duplicate_submissions_batch($worksheet, $submitter_id);
        if (!empty($duplicate_submission_validation)) {
            array_push($validation_results, ...$duplicate_submission_validation);
        }

        // Progress: Validating PMIDs
        if ($progressCallback) {
            $progressCallback('Validating PMID format...');
        }

        // Validate PMIDs
        if (!$skipPmidFetch) {
            // Full validation: fetch from PubMed and check they exist
            $pmid_validation_results = self::validate_and_cache_pmids($worksheet);
            if (!empty($pmid_validation_results)) {
                array_push($validation_results, ...$pmid_validation_results);
            }
        } else {
            // Fast validation: just check format (6+ digits, no leading zeros)
            \Log::info('SubmissionFileValidation: Performing fast PMID format validation (skipping PubMed fetch)');
            $pmid_format_errors = self::validate_pmid_format($worksheet);
            if (!empty($pmid_format_errors)) {
                array_push($validation_results, ...$pmid_format_errors);
            }
        }

        // Validate for duplicate PMIDs within each submission (always run)
        $pmid_duplicate_errors = self::validate_pmid_duplicates($worksheet);
        if (!empty($pmid_duplicate_errors)) {
            array_push($validation_results, ...$pmid_duplicate_errors);
        }

        // Progress: Finalizing validation
        if ($progressCallback) {
            $progressCallback('Finalizing validation results...');
        }

        // Group errors by message and collect rows
        return self::group_validation_errors($validation_results);
    }

    /**
     * Validate spreadsheet headers against required columns
     */
    public static function validate_header_row($header_row): array
    {
        // no header row - show-stopper
        if (empty($header_row)) {
            return [
                'error_type' => 'no_header_row_found',
                'severity' => self::SEVERITY_FATAL,
                'validation_type' => self::HEADER_VALIDATION,
                'row' => self::HEADER_ROW_NUM,
                'message' => 'Could not find header row in row 6 of the spreadsheet. Headers must be in row 6. ' .
                    self::FATAL_ERROR_MESSAGE,
            ];
        }

        // Validate the header columns for missing or extra columns
        // always returns counts and an explicit indication of validity
        $validation = self::validate_header_columns($header_row);

        if ($validation['valid'] === false) {
            // Format missing columns as readable string
            $missingStr = empty($validation['missing_columns'])
                ? 'none'
                : implode(', ', array_map(fn($col) => $col['column'], $validation['missing_columns']));

            // Format extra columns as readable string
            $extraStr = empty($validation['extra_columns'])
                ? 'none'
                : implode(', ', $validation['extra_columns']);

            $message = sprintf(
                'Header validation failed in row 6. Found %d fields, missing %d required fields, %d extra fields. ' .
                    'Required: %d fields total. Missing fields: %s. Extra fields: %s. ' .
                    self::FATAL_ERROR_MESSAGE,
                $validation['total_found'],
                count($validation['missing_columns']),
                count($validation['extra_columns']),
                $validation['total_required'],
                $missingStr,
                $extraStr
            );
            return [
                'error_type' => 'invalid_header_columns',
                'severity' => self::SEVERITY_FATAL,
                'validation_type' => self::HEADER_VALIDATION,
                'row' => self::HEADER_ROW_NUM,
                'message' => $message,
            ];
        }
        return [];
    }

    /**
     * Validate headers from header row. This is a bit different from the
     * other validation functions in that validity is always returned in 'valid'
     * key, along with counts of missing columns and extra columns.
     *
     */
    public static function validate_header_columns(array $actual_headers): array
    {
        // filter nulls and make associative array
        $actual_headers = array_values(array_filter($actual_headers));
        $required_columns = self::get_column_names();
        $missing_columns = [];
        $extra_columns = [];

        // Normalize headers (trim spaces, convert to lowercase for comparison)
        $normalized_actual = array_map(function($header) {
            return strtolower(trim($header));
        }, $actual_headers);

        $normalized_required = array_map('strtolower', $required_columns);

        // Check for missing required columns
        foreach ($normalized_required as $required) {
            if (!in_array($required, $normalized_actual)) {
                $original_column = $required_columns[array_search($required, $normalized_required)];
                $missing_columns[] = [
                    'column' => $original_column,
                    'description' => self::get_column_description($original_column)
                ];
            }
        }

        // Find extra columns (not strictly required but good to know)
        foreach ($normalized_actual as $index => $actual) {
            if (!in_array($actual, $normalized_required) && !empty(trim($actual_headers[$index]))) {
                $extra_columns[] = trim($actual_headers[$index]);
            }
        }

        return [
            'valid' => empty($missing_columns),
            'missing_columns' => $missing_columns,
            'extra_columns' => $extra_columns,
            'total_required' => count($required_columns),
            'total_found' => count($actual_headers)
        ];
    }

    /**
     * Validate the data row against the COLUMN_MAP validation structures.
     *
     * @param $data_row - data row
     * @param $row_num - row number
     * @return array - vslidation error array
     */
    public static function validate_data_row($data_row, $row_num): array
    {
        $validation_results = [];

        // get just the number of columns we expect
        $data_row = array_slice($data_row, 0, count(self::$COLUMN_MAP));

        // get displayed fields
        $sgc_id = $data_row[self::get_index('sgc_id')];
        $local_key = $data_row[self::get_index('local_key')];
        $action = strtoupper(trim($data_row[self::get_index('action')] ?? ''));

        // check that the required fields are there
        // For Unpublish (U), only SGC_ID and Action are required
        if ($action === 'U') {
            // Only validate SGC_ID and Action for unpublish
            if (empty(trim($sgc_id))) {
                $validation_results[] = [
                    'error_type' => 'missing_required_field',
                    'severity' => self::SEVERITY_ERROR,
                    'validation_type' => self::DATA_VALIDATION,
                    'row' => $row_num,
                    'sgc_id' => $sgc_id,
                    'local_key' => $local_key,
                    'message' => "Required field 'sgc_id' is missing.",
                ];
            }
        } else {
            // For all other actions (N, R), check all required fields
            $normalized_required = array_map('strtolower', self::get_required_columns());

            // Check for missing required columns
            foreach ($normalized_required as $required) {
                $index_of_required = self::get_index($required);
                if (empty(trim($data_row[$index_of_required]))) {
                    $validation_results[] = [
                        'error_type' => 'missing_required_field',
                        'severity' => self::SEVERITY_ERROR,
                        'validation_type' => self::DATA_VALIDATION,
                        'row' => $row_num,
                        'sgc_id' => $sgc_id,
                        'local_key' => $local_key,
                        'message' => "Required field '{$required}' is missing.",
                    ];
                }
            }
        }


        foreach (array_values(self::get_column_names()) as $index => $column_name) {

            // For Unpublish (U), skip validation for all fields except sgc_id and action
            if ($action === 'U' && $column_name !== 'sgc_id' && $column_name !== 'action') {
                continue;
            }

            // handle spreadsheet date if is_date set
            if (isset(self::$COLUMN_MAP[$column_name]['is_date']) && self::$COLUMN_MAP[$column_name]['is_date'])
            {
                $original_value = $data_row[$index];
                $value = self::parse_as_date($original_value);

                // If date parsing failed but original value was not empty, report error
                if ($value === null && !empty(trim($original_value))) {
                    $validation_results[] = [
                        'error_type' => 'invalid_field_format',
                        'severity' => self::SEVERITY_ERROR,
                        'validation_type' => self::DATA_VALIDATION,
                        'row' => $row_num,
                        'sgc_id' => $sgc_id,
                        'local_key' => $local_key,
                        'message' => "Invalid date format for column '{$column_name}'. Expected format: YYYY-MM-DD (e.g., 2024-01-15). Got: '{$original_value}'"
                    ];
                    continue;
                }
            }
            else
                $value = $data_row[$index];

            // Skip format/enum/database validation for empty values
            // (required field validation is handled separately)
            if (empty(trim($value))) {
                continue;
            }

            // Validation order of precedence (stop at first failure):
            // 1. Regex format check
            // 2. Enum value check
            // 3. Database lookup check

            $field_validation_failed = false;

            // Step 1: check each value against the columns optional regexp
            if (!$field_validation_failed && array_key_exists('regexp', self::$COLUMN_MAP[$column_name])) {
                $regexp = self::$COLUMN_MAP[$column_name]['regexp'];
                // Normalize whitespace (convert non-breaking spaces and other Unicode spaces to regular spaces)
                $normalized_value = preg_replace('/\s+/u', ' ', trim($value));
                if (!preg_match($regexp, $normalized_value)) {
                    $guidance = self::get_column_guidance($column_name);
                    $validation_results[] = [
                        'error_type' => 'invalid_field_format',
                        'severity' => self::SEVERITY_ERROR,
                        'validation_type' => self::DATA_VALIDATION,
                        'row' => $row_num,
                        'sgc_id' => $sgc_id,
                        'local_key' => $local_key,
                        'message' => "Invalid format for column '{$column_name}'. {$guidance}",
                    ];
                    $field_validation_failed = true;  // Stop further validation for this field
                }
            }

            // Step 2: check value against validator_method (enum check)
            if (!$field_validation_failed && array_key_exists('validator_method', self::$COLUMN_MAP[$column_name])) {
                $validator_method = self::$COLUMN_MAP[$column_name]['validator_method'];
                $valid_values = call_user_func($validator_method);
                if (!in_array($value, $valid_values)) {
                    $guidance = self::get_column_guidance($column_name);
                    $validation_results[] = [
                        'error_type' => 'invalid_field_value',
                        'severity' => self::SEVERITY_ERROR,
                        'validation_type' => self::DATA_VALIDATION,
                        'row' => $row_num,
                        'sgc_id' => $sgc_id,
                        'local_key' => $local_key,
                        'message' => "Invalid value for column '{$column_name}'. {$guidance}",
                    ];
                    $field_validation_failed = true;  // Stop further validation for this field
                }
            }

            // Step 3: check value against validator_with_argument (database lookup)
            if (!$field_validation_failed && array_key_exists('validator_with_argument', self::$COLUMN_MAP[$column_name])) {
                $validator_method = self::$COLUMN_MAP[$column_name]['validator_with_argument']['method'];
                $return = call_user_func($validator_method, $value);
                if ($return === null ) {
                    $guidance = self::get_column_guidance($column_name);
                    $validation_results[] = [
                        'error_type' => 'invalid_field_value',
                        'severity' => self::SEVERITY_ERROR,
                        'validation_type' => self::DATA_VALIDATION,
                        'row' => $row_num,
                        'sgc_id' => $sgc_id,
                        'local_key' => $local_key,
                        'message' => "Invalid value for column '{$column_name}'. {$guidance}",
                    ];
                    // No need to set flag here as this is the last check
                }
            }
        }

        // Validate action-specific business rules
        $action_validation = self::validate_action_rules($data_row, $row_num, $sgc_id, $local_key);
        if (!empty($action_validation)) {
            array_push($validation_results, ...$action_validation);
        }

        return $validation_results;
    }

    /**
     * Validate action-specific business rules
     * - N (New): must NOT have SGC_ID
     * - R (Republish): must have valid processed SGC_ID, current state must be published or unpublished
     * - U (Unpublish): must have valid processed SGC_ID, current state must be published only
     * - All: SGC_ID must not be in another draft or submitted job
     */
    private static function validate_action_rules($data_row, $row_num, $sgc_id, $local_key): array
    {
        $validation_results = [];
        $action_index = self::get_index('action');
        $action = strtoupper(trim($data_row[$action_index]));

        // Map action codes to names for error messages
        $actionNames = [
            'N' => 'New',
            'R' => 'Republish',
            'U' => 'Unpublish'
        ];
        $actionName = $actionNames[$action] ?? $action;

        // Rule 1: New submissions must NOT have an SGC_ID
        if ($action === 'N') {
            if (!empty($sgc_id)) {
                $validation_results[] = [
                    'error_type' => 'new_with_sgc_id',
                    'severity' => self::SEVERITY_ERROR,
                    'validation_type' => self::DATA_VALIDATION,
                    'row' => $row_num,
                    'sgc_id' => $sgc_id,
                    'local_key' => $local_key,
                    'message' => "Action 'N' (New) cannot have an SGC_ID. The SGC_ID column must be empty for new submissions.",
                ];
            }
        }

        // Rule 1b: Unpublish submissions must have ONLY SGC_ID, all other fields should be empty
        if ($action === 'U') {
            $non_empty_fields = [];
            // Check all fields except sgc_id and action
            foreach (array_keys(self::$COLUMN_MAP) as $index => $column_name) {
                if ($column_name !== 'sgc_id' && $column_name !== 'action') {
                    $value = trim($data_row[$index] ?? '');
                    if (!empty($value)) {
                        $non_empty_fields[] = $column_name;
                    }
                }
            }

            if (!empty($non_empty_fields)) {
                $validation_results[] = [
                    'error_type' => 'unpublish_has_data',
                    'severity' => self::SEVERITY_ERROR,
                    'validation_type' => self::DATA_VALIDATION,
                    'row' => $row_num,
                    'sgc_id' => $sgc_id,
                    'local_key' => $local_key,
                    'message' => "Action 'U' (Unpublish) should only have SGC_ID and Action columns filled. The following fields must be empty: " . implode(', ', $non_empty_fields) . ".",
                ];
            }
        }

        // Rules for Republish and Unpublish
        if ($action === 'R' || $action === 'U') {
            // Rule 2: Republish/Unpublish must have an SGC_ID
            if (empty($sgc_id)) {
                $validation_results[] = [
                    'error_type' => 'action_missing_sgc_id',
                    'severity' => self::SEVERITY_ERROR,
                    'validation_type' => self::DATA_VALIDATION,
                    'row' => $row_num,
                    'sgc_id' => $sgc_id,
                    'local_key' => $local_key,
                    'message' => "Action '{$action}' ({$actionName}) requires a valid SGC_ID in the first column.",
                ];
            } elseif (!preg_match('/^SGC-[1-9]\d{5}$/', $sgc_id)) {
                // Rule 2b: SGC_ID must have correct format (SGC- followed by 6 digits, no leading zero)
                $validation_results[] = [
                    'error_type' => 'invalid_sgc_id_format',
                    'severity' => self::SEVERITY_ERROR,
                    'validation_type' => self::DATA_VALIDATION,
                    'row' => $row_num,
                    'sgc_id' => $sgc_id,
                    'local_key' => $local_key,
                    'message' => "Invalid SGC ID format. Must be 'SGC-' followed by exactly 6 digits with no leading zeros (e.g., SGC-100001).",
                ];
            }
            // Note: SGC_ID existence, ownership, and state validation is done in batch via validate_sgc_ids_and_states_batch()
        }

        return $validation_results;
    }

    /**
     * Fast PMID format validation - checks format only (6+ digits, no leading zeros)
     * Does NOT fetch from PubMed API
     *
     * @param array $worksheet The spreadsheet data
     * @return array Array of validation errors for invalid PMID formats
     */
    private static function validate_pmid_format($worksheet): array
    {
        $validation_results = [];
        $pmid_index = self::get_index('pmids');

        $row_num = 0;
        foreach ($worksheet as $row) {
            $row_num++;

            // Skip non-data rows
            if ($row_num < self::FIRST_DATA_ROW) {
                continue;
            }

            // Skip empty rows
            if (empty(implode('', $row))) {
                continue;
            }

            // Get PMIDs from this row
            $pmids_cell = $row[$pmid_index] ?? '';
            if (empty(trim($pmids_cell))) {
                continue;
            }

            // Track if this row has any invalid PMIDs
            $row_has_invalid_pmid = false;

            // Parse PMIDs (comma or semicolon separated)
            $pmids = preg_split('/[,;]/', $pmids_cell);
            foreach ($pmids as $pmid) {
                $pmid = trim($pmid);

                // Remove "PMID:" prefix if present
                $pmid = (stripos($pmid, "PMID:") === 0 ? substr($pmid, 5) : $pmid);
                $pmid = trim($pmid);

                if (empty($pmid)) {
                    continue;
                }

                // Check format: must be numeric (any length from 1 digit up), no leading zeros
                $is_invalid = false;
                if (!is_numeric($pmid)) {
                    $is_invalid = true;
                } elseif (strlen($pmid) > 1 && $pmid[0] === '0') {
                    // Leading zeros are only invalid for multi-digit numbers
                    $is_invalid = true;
                }

                if ($is_invalid) {
                    $row_has_invalid_pmid = true;
                    break;  // One invalid PMID is enough to flag the row
                }
            }

            // Add error for this row if it has invalid PMIDs
            if ($row_has_invalid_pmid) {
                $validation_results[] = [
                    'error_type' => 'invalid_pmid_format',
                    'severity' => self::SEVERITY_ERROR,
                    'validation_type' => self::DATA_VALIDATION,
                    'row' => $row_num,
                    'message' => 'Invalid PMID format found: Must be numeric with no leading zeros.',
                ];
            }
        }

        \Log::info('SubmissionFileValidation: PMID format validation complete. Rows with invalid PMIDs: ' . count($validation_results));
        return $validation_results;
    }

    /**
     * Validate that each row has no duplicate PMIDs within the same submission
     *
     * @param $worksheet The worksheet array to validate
     * @return array Array of validation errors for rows with duplicate PMIDs
     */
    private static function validate_pmid_duplicates($worksheet): array
    {
        $validation_results = [];
        $pmid_index = self::get_index('pmids');

        \Log::info('SubmissionFileValidation: Checking for duplicate PMIDs within submissions...');

        for ($row_num = self::FIRST_DATA_ROW; $row_num < count($worksheet); $row_num++) {
            $row = $worksheet[$row_num];

            // Get PMIDs from this row
            $pmids_cell = $row[$pmid_index] ?? '';
            if (empty(trim($pmids_cell))) {
                continue;
            }

            // Parse PMIDs (comma or semicolon separated)
            $pmids = preg_split('/[,;]/', $pmids_cell);
            $cleaned_pmids = [];

            foreach ($pmids as $pmid) {
                $pmid = trim($pmid);

                // Remove "PMID:" prefix if present
                $pmid = (stripos($pmid, "PMID:") === 0 ? substr($pmid, 5) : $pmid);
                $pmid = trim($pmid);

                if (!empty($pmid) && is_numeric($pmid)) {
                    $cleaned_pmids[] = $pmid;
                }
            }

            // Check for duplicates
            $unique_pmids = array_unique($cleaned_pmids);
            if (count($cleaned_pmids) !== count($unique_pmids)) {
                // Find which PMIDs are duplicated
                $pmid_counts = array_count_values($cleaned_pmids);
                $duplicated = array_filter($pmid_counts, function($count) { return $count > 1; });
                $duplicate_list = implode(', ', array_keys($duplicated));

                $validation_results[] = [
                    'error_type' => 'duplicate_pmids',
                    'severity' => self::SEVERITY_ERROR,
                    'validation_type' => self::DATA_VALIDATION,
                    'message' => "Duplicate PMIDs found in single submission: {$duplicate_list}. Each PMID should appear only once per submission.",
                    'rows' => (string)$row_num
                ];
            }
        }

        \Log::info('SubmissionFileValidation: Duplicate PMID validation complete. Rows with duplicate PMIDs: ' . count($validation_results));
        return $validation_results;
    }

    /**
     * Validate and pre-cache all PMIDs from the spreadsheet
     * This collects all unique PMIDs, fetches their data from PubMed in batch,
     * and reports any invalid PMIDs as validation errors
     *
     * @param array $worksheet The spreadsheet data
     * @return array Array of validation errors for invalid PMIDs
     */
    private static function validate_and_cache_pmids($worksheet): array
    {
        $validation_results = [];
        $all_pmids = [];
        $pmid_index = self::get_index('pmids');

        \Log::info('SubmissionFileValidation: Collecting all PMIDs from spreadsheet for caching...');

        // Collect all unique PMIDs from all rows
        $row_num = 0;
        foreach ($worksheet as $row) {
            $row_num++;

            // Skip non-data rows
            if ($row_num < self::FIRST_DATA_ROW) {
                continue;
            }

            // Skip empty rows
            if (empty(implode('', $row))) {
                continue;
            }

            // Get PMIDs from this row
            $pmids_cell = $row[$pmid_index] ?? '';
            if (empty(trim($pmids_cell))) {
                continue;
            }

            // Parse PMIDs (comma or semicolon separated)
            $pmids = preg_split('/[,;]/', $pmids_cell);
            foreach ($pmids as $pmid) {
                $pmid = trim($pmid);
                // Remove "PMID:" prefix if present
                $pmid = (stripos($pmid, "PMID:") === 0 ? substr($pmid, 5) : $pmid);
                $pmid = trim($pmid);

                if (!empty($pmid) && is_numeric($pmid)) {
                    $all_pmids[$pmid] = true; // Use associative array for uniqueness
                }
            }
        }

        $unique_pmids = array_keys($all_pmids);
        $pmid_count = count($unique_pmids);

        if ($pmid_count === 0) {
            \Log::info('SubmissionFileValidation: No PMIDs found in spreadsheet');
            return [];
        }

        \Log::info("SubmissionFileValidation: Found {$pmid_count} unique PMIDs, checking local cache...");

        // Create Pubmed records for any that don't exist (for caching purposes)
        // Do not attempt to fetch from PubMed API - this will be done by a separate batch job
        foreach ($unique_pmids as $pmid) {
            Pubmed::firstOrCreate(
                ['pmid' => $pmid, 'uid' => $pmid],
                ['status' => Pubmed::STATUS_INITIALIZING]
            );
        }

        \Log::info('SubmissionFileValidation: PMID caching complete');

        // No validation errors - PMIDs that don't exist in PubMed will be fetched by separate job
        return $validation_results;
    }

    /**
     * Validate that no SGC IDs are duplicated within the spreadsheet
     *
     * @param array $worksheet The spreadsheet data
     * @return array Array of validation errors for duplicate SGC IDs
     */
    private static function validate_duplicate_sgc_ids($worksheet): array
    {
        $validation_results = [];
        $sgc_id_index = self::get_index('sgc_id');

        // Track SGC IDs and their row numbers
        $sgc_id_rows = [];
        $row_num = 0;

        foreach ($worksheet as $row) {
            $row_num++;

            if ($row_num < self::FIRST_DATA_ROW) {
                continue;
            }

            if (empty(implode('', $row))) {
                continue;
            }

            $sgc_id = $row[$sgc_id_index] ?? '';
            if (empty(trim($sgc_id))) {
                continue;
            }

            $sgc_id = trim($sgc_id);

            // Track which rows contain this SGC ID
            if (!isset($sgc_id_rows[$sgc_id])) {
                $sgc_id_rows[$sgc_id] = [];
            }
            $sgc_id_rows[$sgc_id][] = $row_num;
        }

        // Generate errors for any SGC ID that appears more than once
        foreach ($sgc_id_rows as $sgc_id => $rows) {
            if (count($rows) > 1) {
                // Create one error per row with the duplicate
                foreach ($rows as $row) {
                    $validation_results[] = [
                        'error_type' => 'duplicate_sgc_id',
                        'severity' => self::SEVERITY_ERROR,
                        'validation_type' => self::DATA_VALIDATION,
                        'row' => $row,
                        'message' => "Duplicate SGC ID found in spreadsheet. Each SGC ID must appear only once.",
                    ];
                }
            }
        }

        return $validation_results;
    }

    /**
     * Batch validate that all SGC IDs in the worksheet belong to the correct submitter
     *
     * @param array $worksheet The spreadsheet data
     * @param int $submitter_id The submitter ID to validate against
     * @return array Array of validation errors for invalid SGC IDs
     */
    private static function validate_sgc_ids_batch($worksheet, $submitter_id): array
    {
        $validation_results = [];
        $sgc_id_index = self::get_index('sgc_id');
        $action_index = self::get_index('action');
        $hgnc_id_index = self::get_index('hgnc_id');

        // Collect all SGC IDs from the worksheet with their row numbers, actions, and HGNC IDs
        $sgc_ids_to_check = []; // Format: ['SGC-100001' => [['row' => 7, 'action' => 'R', 'hgnc_id' => '1234'], ...]]
        $row_num = 0;

        foreach ($worksheet as $row) {
            $row_num++;

            if ($row_num < self::FIRST_DATA_ROW) {
                continue;
            }

            if (empty(implode('', $row))) {
                continue;
            }

            $sgc_id = $row[$sgc_id_index] ?? '';
            if (empty(trim($sgc_id))) {
                continue;
            }

            $sgc_id = trim($sgc_id);
            $action = strtoupper(trim($row[$action_index] ?? ''));
            $hgnc_id_raw = trim($row[$hgnc_id_index] ?? '');

            // Normalize HGNC ID format (remove "HGNC:" prefix if present)
            $hgnc_id = (stripos($hgnc_id_raw, "HGNC:") === 0) ? substr($hgnc_id_raw, 5) : $hgnc_id_raw;

            // Store SGC ID with its row number, action, and HGNC ID for error reporting
            if (!isset($sgc_ids_to_check[$sgc_id])) {
                $sgc_ids_to_check[$sgc_id] = [];
            }
            $sgc_ids_to_check[$sgc_id][] = ['row' => $row_num, 'action' => $action, 'hgnc_id' => $hgnc_id];
        }

        if (empty($sgc_ids_to_check)) {
            return $validation_results;
        }

        // Batch query to fetch all submissions with their states and gene relationships
        // Use submission->submitter_id to support admin users acting as other submitters
        // For SIDs with multiple versions, only check against the live version (is_live=true)
        // or pending versions (draft/submitted statuses)
        $submissions = \App\Models\Submission::with('gene')
            ->whereIn('sid', array_keys($sgc_ids_to_check))
            ->where('submitter_id', $submitter_id)
            ->where(function ($q) {
                // Pending submissions (any draft/submitted status)
                $q->whereIn('status', [
                    \App\Models\Submission::STATUS_DRAFT_NEW,
                    \App\Models\Submission::STATUS_DRAFT_REPUBLISH,
                    \App\Models\Submission::STATUS_DRAFT_UNPUBLISH,
                    \App\Models\Submission::STATUS_SUBMITTED_NEW,
                    \App\Models\Submission::STATUS_SUBMITTED_REPUBLISH,
                    \App\Models\Submission::STATUS_SUBMITTED_UNPUBLISH,
                ])
                // Or live released submissions
                ->orWhere('is_live', true);
            })
            ->get()
            ->keyBy('sid');

        // Check each SGC ID
        foreach ($sgc_ids_to_check as $sgc_id => $row_actions) {
            $submission = $submissions->get($sgc_id);

            // Check if SGC ID exists and belongs to this submitter
            if ($submission === null) {
                // SGC ID not found or doesn't belong to this submitter
                foreach ($row_actions as $row_action) {
                    $validation_results[] = [
                        'error_type' => 'sgc_id_not_found',
                        'severity' => self::SEVERITY_ERROR,
                        'validation_type' => self::DATA_VALIDATION,
                        'row' => $row_action['row'],
                        'message' => "SGC ID not found. Only previously processed submissions can be republished or unpublished.",
                    ];
                }
                continue;
            }

            // SGC ID exists and belongs to submitter - now check state transitions
            $currentState = $submission->status;

            // Get the existing gene HGNC ID from the submission
            // Use the gene relationship's hgnc_id as authoritative source (original_submission_data may be null for legacy records)
            $existingGeneHgncId = $submission->gene?->hgnc_id ?? $submission->original_submission_data?->gene?->id;

            foreach ($row_actions as $row_action) {
                $row = $row_action['row'];
                $action = $row_action['action'];
                $newHgncIdRaw = $row_action['hgnc_id']; // Already has HGNC: prefix stripped

                // Normalize both to HGNC:#### format for comparison
                // The spreadsheet value may have prefix stripped, so add it back
                $newHgncId = (stripos($newHgncIdRaw, "HGNC:") === 0) ? $newHgncIdRaw : "HGNC:{$newHgncIdRaw}";
                // The database value should already be in HGNC:#### format, but normalize just in case
                $existingHgncId = $existingGeneHgncId;
                if ($existingHgncId && stripos($existingHgncId, "HGNC:") !== 0) {
                    $existingHgncId = "HGNC:{$existingHgncId}";
                }

                // Check if SGC_ID is already in a draft or submitted job
                if (in_array($currentState, [
                    \App\Models\Submission::STATUS_DRAFT_NEW,
                    \App\Models\Submission::STATUS_DRAFT_REPUBLISH,
                    \App\Models\Submission::STATUS_DRAFT_UNPUBLISH,
                    \App\Models\Submission::STATUS_SUBMITTED_NEW,
                    \App\Models\Submission::STATUS_SUBMITTED_REPUBLISH,
                    \App\Models\Submission::STATUS_SUBMITTED_UNPUBLISH
                ])) {
                    $validation_results[] = [
                        'error_type' => 'sgc_id_in_draft_or_submitted_job',
                        'severity' => self::SEVERITY_ERROR,
                        'validation_type' => self::DATA_VALIDATION,
                        'row' => $row,
                        'message' => "SGC ID is already in another draft or submitted job. Complete or cancel that job first.",
                    ];
                    continue; // Skip state transition check if already in a job
                }

                // Check state transitions for R (Republish)
                if ($action === 'R') {
                    if (!in_array($currentState, [
                        \App\Models\Submission::STATUS_PUBLISHED,
                        \App\Models\Submission::STATUS_UNPUBLISHED
                    ])) {
                        $validation_results[] = [
                            'error_type' => 'republish_invalid_state',
                            'severity' => self::SEVERITY_ERROR,
                            'validation_type' => self::DATA_VALIDATION,
                            'row' => $row,
                            'message' => "Action 'R' (Republish) requires SGC ID to be in 'Published' or 'Unpublished' state. Check the current state of the submission.",
                        ];
                    }

                    // Check that gene hasn't changed for Republish action
                    // Compare normalized HGNC IDs (both in HGNC:#### format)
                    if ($existingHgncId !== null && $existingHgncId !== $newHgncId) {
                        $validation_results[] = [
                            'error_type' => 'republish_gene_change',
                            'severity' => self::SEVERITY_ERROR,
                            'validation_type' => self::DATA_VALIDATION,
                            'row' => $row,
                            'message' => "Action 'R' (Republish) cannot change the gene of an existing submission. To submit a different gene-disease association, create a new submission instead.",
                        ];
                    }
                }

                // Check state transitions for U (Unpublish)
                if ($action === 'U') {
                    if ($currentState !== \App\Models\Submission::STATUS_PUBLISHED) {
                        $validation_results[] = [
                            'error_type' => 'unpublish_invalid_state',
                            'severity' => self::SEVERITY_ERROR,
                            'validation_type' => self::DATA_VALIDATION,
                            'row' => $row,
                            'message' => "Action 'U' (Unpublish) requires SGC ID to be in 'Published' state. Check the current state of the submission.",
                        ];
                    }
                }
            }
        }

        return $validation_results;
    }

    /**
     * Validate that no duplicate gene-disease-MOI combinations exist.
     *
     * For each row:
     * - Action 'N' (New): Check if same gene-disease-MOI already exists for this submitter
     * - Action 'R' (Republish): Check if modified disease-MOI would create duplicate (gene is locked for republish)
     *
     * @param array $worksheet The worksheet data as an array of rows
     * @param int $submitter_id The submitter ID to check against
     * @return array Array of validation errors/warnings
     */
    private static function validate_duplicate_submissions_batch($worksheet, int $submitter_id): array
    {
        $validation_results = [];

        // Get column indices
        $action_index = self::get_index('action');
        $hgnc_id_index = self::get_index('hgnc_id');
        $disease_id_index = self::get_index('disease_id');
        $moi_id_index = self::get_index('moi_id');
        $sgc_id_index = self::get_index('sgc_id');

        // Collect all submissions to check
        $submissions_to_check = [];
        $row_num = 0;

        foreach ($worksheet as $row) {
            $row_num++;

            // Skip non-data rows
            if ($row_num < self::FIRST_DATA_ROW) {
                continue;
            }

            // Skip empty rows
            if (empty(implode('', $row))) {
                continue;
            }

            $action = strtoupper(trim($row[$action_index] ?? ''));

            // Skip unpublish actions - they don't create new gene-disease-MOI combinations
            if ($action === 'U') {
                continue;
            }

            // Get field values
            $hgnc_id_raw = trim($row[$hgnc_id_index] ?? '');
            $disease_id_raw = trim($row[$disease_id_index] ?? '');
            $moi_id_raw = trim($row[$moi_id_index] ?? '');
            $sgc_id = trim($row[$sgc_id_index] ?? '');

            // Skip if any required field is empty (other validators will catch these)
            if (empty($hgnc_id_raw) || empty($disease_id_raw) || empty($moi_id_raw)) {
                continue;
            }

            // Look up database IDs
            $gene = Gene::lookup_hgnc_id($hgnc_id_raw);
            $disease = Disease::rosetta($disease_id_raw);  // Normalizes to MONDO for original_disease_id comparison
            $inheritance = Inheritance::curie($moi_id_raw)->first();

            // Skip if any lookup failed (other validators will catch these)
            if ($gene === null || $disease === null || $inheritance === null) {
                continue;
            }

            // For republish, get the existing live submission ID to exclude from check
            // Must filter by is_live=true to get the correct version when multiple versions exist
            $exclude_submission_id = null;
            if ($action === 'R' && !empty($sgc_id)) {
                $existing = Submission::sid($sgc_id)->where('is_live', true)->first();
                $exclude_submission_id = $existing?->id;
            }

            // Look up the original_disease_id from the exact uploaded disease CURIE
            // This is the disease record for what was uploaded (OMIM, Orphanet, or MONDO)
            $originalDisease = Disease::curie($disease_id_raw)->first();
            $original_disease_id = $originalDisease?->id ?? $disease->id;

            $submissions_to_check[] = [
                'gene_id' => $gene->id,
                'original_disease_id' => $original_disease_id,
                'inheritance_id' => $inheritance->id,
                'row_index' => $row_num,
                'exclude_submission_id' => $exclude_submission_id,
                'action' => $action,
            ];
        }

        if (empty($submissions_to_check)) {
            return $validation_results;
        }

        // Batch check for duplicates
        $duplicate_results = SubmissionDuplicateDetection::checkForDuplicatesBatch(
            $submitter_id,
            $submissions_to_check
        );

        // Collect batch duplicates into groups (each group is a set of rows that are duplicates of each other)
        $batchDuplicateGroups = [];
        $processedRows = [];

        foreach ($submissions_to_check as $submission) {
            $row_num = $submission['row_index'];
            $result = $duplicate_results[$row_num] ?? null;

            if ($result === null) {
                continue;
            }

            // Collect batch duplicates into groups
            if ($result['has_batch_duplicate'] && !isset($processedRows[$row_num])) {
                // Create a group with this row and its duplicates
                $group = array_merge([$row_num], $result['batch_duplicate_rows']);
                sort($group, SORT_NUMERIC);

                // Mark all rows in this group as processed
                foreach ($group as $r) {
                    $processedRows[$r] = true;
                }

                $batchDuplicateGroups[] = $group;
            }
        }

        // Add a single consolidated error for all batch duplicates with grouped rows
        if (!empty($batchDuplicateGroups)) {
            $validation_results[] = [
                'error_type' => 'duplicate_submission',
                'severity' => self::SEVERITY_ERROR,
                'validation_type' => self::DATA_VALIDATION,
                'message' => SubmissionDuplicateDetection::formatGroupedBatchDuplicateMessage($batchDuplicateGroups),
            ];
        }

        // Process database duplicate results (existing published/draft submissions)
        foreach ($submissions_to_check as $submission) {
            $row_num = $submission['row_index'];
            $result = $duplicate_results[$row_num] ?? null;

            if ($result === null) {
                continue;
            }

            // Check for blocking duplicates (existing published/draft submissions)
            if ($result['has_blocking_duplicate']) {
                $duplicate = $result['blocking_duplicates']->first();
                $validation_results[] = [
                    'error_type' => 'duplicate_submission',
                    'severity' => self::SEVERITY_ERROR,
                    'validation_type' => self::DATA_VALIDATION,
                    'row' => $row_num,
                    'message' => SubmissionDuplicateDetection::formatBlockingErrorMessage($duplicate),
                ];
            }

            // Check for unpublished duplicates (warning only - allow upload to proceed)
            if ($result['has_unpublished_duplicate'] && !$result['has_blocking_duplicate']) {
                $validation_results[] = [
                    'error_type' => 'unpublished_duplicate_submission',
                    'severity' => 'warning',  // Not an error, just a warning
                    'validation_type' => self::DATA_VALIDATION,
                    'row' => $row_num,
                    'message' => SubmissionDuplicateDetection::formatUnpublishedWarningMessage($result['unpublished_duplicates']),
                ];
            }
        }

        return $validation_results;
    }
}