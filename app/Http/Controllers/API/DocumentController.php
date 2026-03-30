<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Events\SpreadsheetUpdate;

use Auth;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;

use App\Models\Document;
use App\Models\Nodal;
use App\Imports\SubmissionImport;
use App\Models\Job;
use App\Models\Submission;
use App\Models\Pubmed;
use App\Models\Gene;
use App\Models\Disease;
use App\Models\Inheritance;
use App\Models\Classification;
use App\Models\Mechanism;
use App\Services\SubmissionFileValidation;

use App\Jobs\ProcessUpload;
use App\Jobs\ProcessSubmissionsUpload;

class DocumentController extends Controller
{
    /**
     * Unique identifier for this controller instance
     */
    private string $controllerId;

    /**
     * Constructor - generates unique ID for logging
     */
    public function __construct()
    {
        $this->controllerId = uniqid('doc_ctrl_', true);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Get a temporary file path for the document's file contents
     * Creates a temp file from the database blob for use with Excel library
     *
     * @param Document $document
     * @return string|null The path to the temporary file, or null if no file contents
     */
    private function getTempFilePath(Document $document): ?string
    {
        if (empty($document->file_contents)) {
            return null;
        }

        // Create temp directory if it doesn't exist
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        // Create a temporary file with the document's extension
        $tempPath = $tempDir . '/' . $document->ident . '.' . $document->extension;

        // Decode base64 and write to temp file
        $contents = base64_decode($document->file_contents);
        if ($contents === false) {
            \Log::error('DocumentController: Failed to decode file contents for document: ' . $document->id);
            return null;
        }

        if (file_put_contents($tempPath, $contents) === false) {
            \Log::error('DocumentController: Failed to write temp file for document: ' . $document->id);
            return null;
        }

        return $tempPath;
    }

    /**
     * Clean up a temporary file after use
     *
     * @param string|null $tempPath
     */
    private function cleanupTempFile(?string $tempPath): void
    {
        if ($tempPath && file_exists($tempPath)) {
            unlink($tempPath);
        }
    }


    /**
     * Add the specified resource in storage
     *
     */
    public function store(Request $request, $id)
    {
        $user = Auth::user();
        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);
        $effectiveSubmitter = $this->getEffectiveSubmitter($request);

        $job = $this->getEffectiveSubmitterQuery($request, 'jobs')->where('ident', $id)->first();

        if ($job === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);

        // REMOVED: Old check that blocked uploads if job had submissions
        // Now allowing uploads even if manually-created submissions exist
        // since Clear File no longer deletes submissions

        // Delete any existing documents for this job (limit to 1 file per job)
        // This cleans up:
        // - Documents with validation errors (failed uploads)
        // - Documents from cancelled uploads (orphaned documents)
        // - Previous file uploads that weren't cleared
        // Using forceDelete to remove blob data from database
        \App\Models\Document::where('job_id', $job->id)->forceDelete();

        $file = $request->file('file');

        // Check if file was uploaded
        if ($file === null || !$file->isValid()) {
            \Log::error('DocumentController@store: No valid file uploaded. Request files: ' . json_encode($request->allFiles()));

            // Get PHP upload limits for error message
            $uploadMaxSize = ini_get('upload_max_filesize');
            $postMaxSize = ini_get('post_max_size');

            // Check if file was sent but rejected (empty object in request)
            $filesSent = $request->allFiles();
            if (!empty($filesSent) && isset($filesSent['file']) && empty($filesSent['file'])) {
                // File was sent but rejected - likely too large
                return response()->json(['success' => 'false',
                            'status_code' => 6003,
                            'message' => "File upload failed. The file may be too large. Maximum upload size is {$uploadMaxSize}. Please contact support if you need to upload larger files."],
                            200);
            }

            return response()->json(['success' => 'false',
                        'status_code' => 6002,
                        'message' => 'No file uploaded or file is invalid'],
                        200);
        }

        $document = new Document([
                'type' => 1,
                'user_id' => $user->id,
                'submitter_id' => $effectiveSubmitterId,
                'job_id' => $job->id,
                'file_name' => $file->getClientOriginalName(),
                'extension' => $file->getClientOriginalExtension(),
                'original_path' => $file->getRealPath(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'status' => Document::STATUS_STORED_UNPROCESSED
            ],
        );

        // begin file processing
        $message = [
            'ident' => $job->ident,
            'file' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'controllerId' => $this->controllerId,
            'status' => 'begin'
        ];
        SpreadsheetUpdate::dispatch((object) $message);

        // Store file contents in database as base64
        $fileContents = file_get_contents($file);
        if ($fileContents === false) {
            return response()->json(['success' => 'false',
                        'status_code' => 6001,
                        'message' => 'Upload Failed - Could not read file'],
                        200);
        }

        $document->file_contents = base64_encode($fileContents);
        $document->local_path = null; // No longer using filesystem storage

        $document->save();

        \Log::info('DocumentController@store: Starting validation for document: ' . $document->id);

        // Phase 1: Validate file (read-only, synchronous)
        $document->update(['upload_state' => Document::UPLOAD_STATE_VALIDATING]);

        $validationResult = $this->validateFile($document);

        // If validation fails, update document state and return errors
        if ($validationResult['has_errors']) {
            \Log::error('DocumentController@store: Validation failed for document: ' . $document->id);

            $document->update([
                'upload_state' => Document::UPLOAD_STATE_VALIDATION_FAILED,
                'processing_errors' => $validationResult['errors']
            ]);

            // Send validation error event
            $message = [
                'ident' => $job->ident,
                'status' => 'validation_errors',
                'error_count' => count($validationResult['errors']),
                'document_id' => $document->id
            ];
            SpreadsheetUpdate::dispatch((object) $message);

            return response()->json([
                'success' => 'false',
                'status_code' => 6005,
                'message' => 'Validation failed',
                'results' => false,
                'document_id' => $document->id,
                'errors' => $validationResult['errors'],
                'warnings' => $validationResult['warnings'] ?? []
            ], 422);
        }

        // Phase 2: Validation passed - prepare for async upload
        \Log::info('DocumentController@store: Validation passed for document: ' . $document->id . ', dispatching background job');

        $document->update([
            'upload_state' => Document::UPLOAD_STATE_VALIDATED,
            'total_submissions' => $validationResult['row_count'],
            'processed_submissions' => 0
        ]);

        // Lock the job during processing
        $job->update(['is_processing' => true]);

        // Dispatch background job for processing
        ProcessSubmissionsUpload::dispatch($document);

        // Send validation complete event
        $message = [
            'ident' => $job->ident,
            'status' => 'validation_complete',
            'row_count' => $validationResult['row_count'],
            'document_id' => $document->id
        ];
        SpreadsheetUpdate::dispatch((object) $message);

        return response()->json([
            'success' => 'true',
            'status_code' => 200,
            'message' => 'File validated successfully. Upload processing in background.',
            'document_id' => $document->id,
            'row_count' => $validationResult['row_count']
        ], 200);

    }

    /**
     * Process a previously validated document
     * NOTE: This method is deprecated in favor of the async upload workflow
     * It remains for backward compatibility
     *
     */
    public function processValidated(Request $request, $documentId)
    {
        $user = Auth::user();
        $document = Document::find($documentId);

        if (!$document) {
            return response()->json([
                'success' => 'false',
                'status_code' => 4004,
                'message' => 'Document not found'
            ], 404);
        }

        // Verify user has access to this document
        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);
        if ($document->submitter_id !== $effectiveSubmitterId) {
            return response()->json([
                'success' => 'false',
                'status_code' => 3002,
                'message' => 'Unauthorized'
            ], 200);
        }

        \Log::info('DocumentController@processValidated: Starting processing for document: ' . $document->id);
        $parserResults = $this->parser($document);

        $document->update(['status' => Document::STATUS_STORED_PROCESSED]);
        \Log::info('DocumentController@processValidated: Document processing completed');

        $response = [
            'success' => 'true',
            'status_code' => 200,
            'message' => 'Processing Succeeded',
            'results' => $parserResults,
            'document_id' => $document->id
        ];

        return response()->json($response, 200);
    }

    /**
     * Clear a document from a job (user action to remove file)
     * Can only clear if not currently uploading
     *
     */
    public function clearDocument(Request $request, $documentId)
    {
        $user = Auth::user();
        $document = Document::findOrFail($documentId);

        // Verify user has access to this document
        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);
        if ($document->submitter_id !== $effectiveSubmitterId) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        // Can only clear if not currently uploading
        if ($document->upload_state === Document::UPLOAD_STATE_UPLOADING) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot clear document while upload is in progress',
            ], 400);
        }

        \Log::info('DocumentController@clearDocument: Clearing document: ' . $document->id);

        $job = $document->job;

        // IMPORTANT: We do NOT delete submissions here because:
        // 1. Submissions don't have a document_id field to track which file created them
        // 2. Manually created submissions would be incorrectly deleted
        // 3. User must manually manage submissions separately if they want to remove them
        \Log::info('DocumentController@clearDocument: Preserving all submissions (manual deletion required)');

        // Delete the document only
        $document->delete();

        // Unlock job
        $job->update(['is_processing' => false]);

        \Log::info('DocumentController@clearDocument: Document cleared successfully (submissions preserved)');

        return response()->json(['success' => true], 200);
    }

    /**
     * Clear a valid document and handle associated submissions
     * - Deletes all draft submissions created from this upload
     * - Original published versions remain unchanged (versioning)
     * - Deletes the document file
     *
     * @param int $documentId
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearValidDocument(Request $request, $documentId)
    {
        $user = Auth::user();
        $document = Document::find($documentId);

        if (!$document) {
            return response()->json(['error' => 'Document not found'], 404);
        }

        // Get the job
        $job = $document->job;

        if (!$job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        \Log::info('DocumentController@clearValidDocument: Clearing valid document and handling submissions', [
            'document_id' => $documentId,
            'job_id' => $job->id
        ]);

        // Get only submissions that were created/modified by this specific document
        $submissions = Submission::where('document_id', $document->id)->get();

        \Log::info('DocumentController@clearValidDocument: Found submissions for document', [
            'count' => $submissions->count()
        ]);

        $deletedCount = 0;
        $draftSids = []; // Track SIDs that need is_most_recent restored

        foreach ($submissions as $submission) {
            // With versioning, draft_republish and draft_unpublish are NEW version records
            // that should be deleted when the file is removed (the original version is preserved)
            if (in_array($submission->status, [
                Submission::STATUS_DRAFT_NEW,
                Submission::STATUS_DRAFT_REPUBLISH,
                Submission::STATUS_DRAFT_UNPUBLISH
            ])) {
                // Track SIDs that need is_most_recent restored (only for republish/unpublish drafts)
                if ($submission->status !== Submission::STATUS_DRAFT_NEW) {
                    $draftSids[$submission->sid] = $submission->id;
                }

                // Delete this draft version record
                $submission->forceDelete();
                $deletedCount++;
                \Log::info('DocumentController@clearValidDocument: Deleted draft submission', [
                    'sid' => $submission->sid,
                    'status' => $submission->status,
                    'version_number' => $submission->version_number
                ]);
            } else {
                // For any other status (shouldn't happen normally), just clear the document association
                $submission->update(['document_id' => null]);
                \Log::info('DocumentController@clearValidDocument: Cleared document association only', [
                    'sid' => $submission->sid,
                    'status' => $submission->status
                ]);
            }
        }

        // Restore is_most_recent=true on the previous versions
        foreach ($draftSids as $sid => $deletedId) {
            $previousVersion = Submission::where('sid', $sid)
                ->where('id', '!=', $deletedId)
                ->orderBy('version_number', 'desc')
                ->first();

            if ($previousVersion && !$previousVersion->is_most_recent) {
                $previousVersion->is_most_recent = true;
                $previousVersion->save();
                \Log::info("DocumentController@clearValidDocument: Restored is_most_recent on {$sid}");
            }
        }

        // File contents are stored in database, so deleting the document record
        // will automatically delete the file blob - no filesystem cleanup needed

        // Delete the document record
        $document->delete();

        // Unlock job
        $job->update(['is_processing' => false]);

        \Log::info('DocumentController@clearValidDocument: Complete', [
            'deleted_submissions' => $deletedCount
        ]);

        return response()->json([
            'success' => true,
            'deleted_submissions' => $deletedCount
        ], 200);
    }

    /**
     * Validate Excel file without processing submissions
     * Read-only validation that checks headers and returns errors/row count
     *
     * @param \App\Models\Document $document The document to validate
     * @return array ['has_errors' => bool, 'errors' => array|null, 'row_count' => int]
     */
    private function validateFile(Document $document)
    {
        \Log::info('DocumentController@validateFile: Starting validation for document: ' . $document->id);

        // Create temp file from database blob for Excel library
        $tempPath = $this->getTempFilePath($document);
        if ($tempPath === null) {
            \Log::error('DocumentController@validateFile: Failed to create temp file for document: ' . $document->id);
            return [
                'has_errors' => true,
                'errors' => [[
                    'error_type' => 'system_error',
                    'severity' => 'error',
                    'message' => 'Failed to read file contents from database',
                    'rows' => 'N/A'
                ]],
                'row_count' => 0
            ];
        }

        try {
            // Import raw data for header validation
            $rawWorksheets = Excel::toArray([], $tempPath);
            $rawFirstsheet = collect($rawWorksheets[0]);
        } finally {
            // Always clean up temp file
            $this->cleanupTempFile($tempPath);
        }

        // Spreadsheet structure: Rows 1-6 are headers, rows 7-12 are info/help text, row 13+ is data
        // Count rows after skipping the first 12 rows AND filtering out empty rows
        // This matches the logic in parser() which skips empty rows during processing
        $dataRows = $rawFirstsheet->slice(12); // Skip first 12 rows (headers + info text)
        $rowCount = $dataRows->filter(function ($row) {
            // Filter out empty rows - same logic as parser() line 698
            return !empty(implode('', $row));
        })->count();

        \Log::info('DocumentController@validateFile: Row count (non-empty data rows after header): ' . $rowCount);

        // Validate spreadsheet headers and data
        // Skip PMID fetching during upload - PMIDs will be refreshed later
        $validation_errors = SubmissionFileValidation::validate_spreadsheet($rawWorksheets[0], $document->submitter_id, true);

        if (!empty($validation_errors)) {
            // Separate blocking errors from warnings
            // array_values() reindexes to ensure JSON serializes as array, not object
            $errors = array_values(array_filter($validation_errors, fn($e) => ($e['severity'] ?? 'error') !== 'warning'));
            $warnings = array_values(array_filter($validation_errors, fn($e) => ($e['severity'] ?? 'error') === 'warning'));

            if (!empty($errors)) {
                $formattedErrors = array_map(function($error) {
                    $rows = $error['rows'] ?? ($error['row'] ?? 'N/A');
                    $formatted = [
                        'error_type' => $error['error_type'] ?? 'validation_error',
                        'severity' => $error['severity'] ?? 'error',
                        'message' => $error['message'] ?? 'Unknown validation error',
                        'rows' => $rows,
                    ];

                    // Preserve column name for frontend grouping
                    if (!empty($error['column'])) {
                        $formatted['column'] = $error['column'];
                    }

                    // Preserve expandable details (unique values with their rows)
                    if (!empty($error['details'])) {
                        $formatted['details'] = $error['details'];
                    }

                    // Preserve file format error fields for frontend display
                    if (!empty($error['is_file_format_error'])) {
                        $formatted['is_file_format_error'] = true;
                    }
                    if (!empty($error['user_title'])) {
                        $formatted['user_title'] = $error['user_title'];
                    }
                    if (!empty($error['user_message'])) {
                        $formatted['user_message'] = $error['user_message'];
                    }

                    return $formatted;
                }, $errors);

                return [
                    'has_errors' => true,
                    'errors' => array_values($formattedErrors),
                    'warnings' => array_values(array_map(function($w) {
                        return [
                            'error_type' => $w['error_type'] ?? 'warning',
                            'severity' => 'warning',
                            'message' => $w['message'] ?? '',
                            'rows' => $w['rows'] ?? ($w['row'] ?? 'N/A'),
                        ];
                    }, $warnings)),
                    'row_count' => $rowCount
                ];
            }

            // Only warnings, no blocking errors - format warnings for display
            $formattedWarnings = array_values(array_map(function($w) {
                return [
                    'error_type' => $w['error_type'] ?? 'warning',
                    'severity' => 'warning',
                    'message' => $w['message'] ?? '',
                    'rows' => $w['rows'] ?? ($w['row'] ?? 'N/A'),
                ];
            }, $warnings));
        }

        // Check for empty file (no valid submission rows)
        if ($rowCount === 0) {
            \Log::error('DocumentController@validateFile: File contains no valid submissions');
            return [
                'has_errors' => true,
                'errors' => [[
                    'error_type' => 'validation_error',
                    'severity' => 'error',
                    'message' => 'File contains no valid submission rows',
                    'rows' => 'N/A'
                ]],
                'row_count' => 0
            ];
        }

        \Log::info('DocumentController@validateFile: Validation passed, row count: ' . $rowCount);

        return [
            'has_errors' => false,
            'errors' => null,
            'warnings' => $formattedWarnings ?? [],
            'row_count' => $rowCount
        ];
    }

    /**
     * Parse an submission worsheet into the system
     * Processes submissions and creates database records
     * NOTE: Validation must be done separately via validateFile() before calling this method
     *
     * @param \App\Models\Document $document The document to parse
     * @return array ['processed_rows' => int, 'total_rows' => int, 'errors' => array|null]
     */
    public function parser($document)
    {
        // Check if the associated job still exists (could be deleted while queued)
        $job = $document->job;
        if (!$job) {
            \Log::warning('DocumentController@parser: Job no longer exists for document', [
                'document_id' => $document->id,
                'document_ident' => $document->ident
            ]);
            // Clean up the orphaned document
            $document->delete();
            return [
                'processed_rows' => 0,
                'total_rows' => 0,
                'errors' => [['message' => 'Job was deleted before processing could complete']]
            ];
        }

        // Increase execution time limit for large file processing
        set_time_limit(3600); // 1 hour for large files
        ini_set('max_execution_time', 3600);

        // Wall-clock timeout (measures real time, not just CPU time)
        // Configurable via UPLOAD_TIMEOUT_SECONDS env variable, defaults to 10 minutes
        $startTime = microtime(true);
        $wallClockTimeoutSeconds = (int) env('UPLOAD_TIMEOUT_SECONDS', 600);

        // Variables for shutdown handler
        $processedRows = 0;
        $totalRows = 0;
        $shutdownHandled = false;
        $jobIdent = $job->ident; // Capture job ident for shutdown handler (in case job gets deleted during processing)

        // Register shutdown function to handle unexpected termination (timeout, errors, etc)
        register_shutdown_function(function() use ($document, $jobIdent, &$processedRows, &$totalRows, &$shutdownHandled) {
            if ($shutdownHandled) {
                return; // Already handled by normal completion
            }

            $error = error_get_last();
            $totalSubmissions = max(1, $totalRows); // Already excludes headers and empty rows

            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                \Log::error('DocumentController@parser: Fatal error during processing', [
                    'error' => $error,
                    'processed_rows' => $processedRows,
                    'total_rows' => $totalRows
                ]);

                // Store fatal error message in document
                try {
                    $document->update([
                        'processing_errors' => [[
                            'error_type' => 'partial_upload',
                            'severity' => 'error',
                            'message' => "Upload failed due to a fatal error after processing {$processedRows} of {$totalSubmissions} submissions. Error: {$error['message']}. Please check your file and try uploading again.",
                            'processed_rows' => $processedRows,
                            'total_rows' => $totalSubmissions,
                            'error_details' => $error['message']
                        ]]
                    ]);
                } catch (\Exception $e) {
                    \Log::error('DocumentController@parser: Failed to save error to document', ['error' => $e->getMessage()]);
                }
            } else {
                \Log::warning('DocumentController@parser: Script terminated unexpectedly (likely timeout)', [
                    'processed_rows' => $processedRows,
                    'total_rows' => $totalRows
                ]);

                // Store unexpected termination message in document
                try {
                    $document->update([
                        'processing_errors' => [[
                            'error_type' => 'partial_upload',
                            'severity' => 'warning',
                            'message' => "Upload was interrupted after processing {$processedRows} of {$totalSubmissions} submissions. The process may have timed out or been terminated unexpectedly. The remaining submissions will not be included when submitting this job. Splitting your file into smaller batches is recommended for the remaining submissions.",
                            'processed_rows' => $processedRows,
                            'total_rows' => $totalSubmissions
                        ]]
                    ]);
                } catch (\Exception $e) {
                    \Log::error('DocumentController@parser: Failed to save warning to document', ['error' => $e->getMessage()]);
                }
            }

            // Send 'done' event with partial results
            $doneMessage = [
                'ident' => $jobIdent, // Use captured ident since job may have been deleted
                'size' => $processedRows,
                'total' => max(1, $totalRows),
                'status' => 'done',
                'partial' => true,
                'reason' => 'timeout_or_error'
            ];
            try {
                SpreadsheetUpdate::dispatch((object) $doneMessage);
            } catch (\Exception $e) {
                \Log::error('DocumentController@parser: Failed to send shutdown completion event', ['error' => $e->getMessage()]);
            }
        });

        // Create temp file from database blob for Excel library
        $tempPath = $this->getTempFilePath($document);
        if ($tempPath === null) {
            \Log::error('DocumentController@parser: Failed to create temp file for document: ' . $document->id);
            $shutdownHandled = true;
            return [
                'processed_rows' => 0,
                'total_rows' => 0,
                'errors' => [['message' => 'Failed to read file contents from database']]
            ];
        }

        // Import Excel file with processed headers for data processing
        $worksheets = Excel::toCollection(new SubmissionImport, $tempPath);
        $firstsheet = $worksheets[0];

        // Clean up temp file now that we've loaded it into memory
        $this->cleanupTempFile($tempPath);

        $rownum = 0;
        $submitter = $document->submitter;

        // Update variables that shutdown handler uses
        // Use the pre-calculated total_submissions from validateFile() which correctly excludes empty rows
        // This ensures consistency between validation count and processing count
        $totalRows = $document->total_submissions;
        // $processedRows already declared at top for shutdown handler
        $successfulSubmissions = 0;
        $erroredSubmissions = [];

        \Log::info('DocumentController@parser: Total rows to process (from validation): ' . $totalRows);

        // Build lookup caches to avoid repeated database queries for each row
        // This dramatically improves performance for large files (e.g., 3000+ rows)
        \Log::info('DocumentController@parser: Loading diseases...');
        $allDiseases = Disease::select('id', 'curie', 'name', 'type', 'xrefs')->get();
        \Log::info('DocumentController@parser: Loaded ' . $allDiseases->count() . ' diseases');

        // Build disease cache - this is keyed by exact CURIE for direct lookups
        // Used to find the original disease record that was uploaded
        $diseaseCache = $allDiseases->keyBy('curie');

        // Build MONDO mapping cache - maps any disease ID (MONDO, OMIM, Orphanet) to its MONDO record
        // Used to normalize all diseases to MONDO
        \Log::info('DocumentController@parser: Building MONDO mapping cache...');
        $mondoMappingCache = collect();

        foreach ($allDiseases as $disease) {
            // If it's a MONDO disease, it maps to itself
            if ($disease->type == Disease::TYPE_MONDO) {
                $mondoMappingCache->put($disease->curie, $disease);

                // Also map any OMIM/Orphanet xrefs to this MONDO disease
                if (isset($disease->xrefs->omim_id)) {
                    $omimIds = is_array($disease->xrefs->omim_id) ? $disease->xrefs->omim_id : [$disease->xrefs->omim_id];
                    foreach ($omimIds as $omimId) {
                        $mondoMappingCache->put('OMIM:' . $omimId, $disease);
                    }
                }
                if (isset($disease->xrefs->orpha_id)) {
                    $orphaIds = is_array($disease->xrefs->orpha_id) ? $disease->xrefs->orpha_id : [$disease->xrefs->orpha_id];
                    foreach ($orphaIds as $orphaId) {
                        $mondoMappingCache->put('ORPHA:' . $orphaId, $disease);
                        $mondoMappingCache->put('ORPHANET:' . $orphaId, $disease);
                    }
                }
            }
        }
        \Log::info('DocumentController@parser: Built MONDO mapping cache with ' . $mondoMappingCache->count() . ' entries');

        \Log::info('DocumentController@parser: Loading other lookup tables...');
        $lookupCaches = [
            'genes' => Gene::select('id', 'hgnc_id', 'symbol')->get()->keyBy('hgnc_id'),
            'diseases' => $diseaseCache,  // Exact disease lookups by CURIE
            'mondo_mappings' => $mondoMappingCache,  // MONDO normalization mappings
            'moi' => Inheritance::select('id', 'curie', 'name')->get()->keyBy('curie'),
            'classifications' => Classification::select('id', 'curie', 'name')->get()->keyBy('curie'),
            'mechanisms' => Mechanism::select('id', 'curie', 'name')->get()->keyBy('curie'),
            'pubmeds' => Pubmed::select('id', 'pmid', 'uid', 'status')->get()->keyBy('pmid'),  // Cache existing PMIDs
        ];
        \Log::info('DocumentController@parser: Built lookup caches', [
            'genes' => $lookupCaches['genes']->count(),
            'diseases' => $lookupCaches['diseases']->count(),
            'mondo_mappings' => $lookupCaches['mondo_mappings']->count(),
            'moi' => $lookupCaches['moi']->count(),
            'classifications' => $lookupCaches['classifications']->count(),
            'mechanisms' => $lookupCaches['mechanisms']->count(),
            'pubmeds' => $lookupCaches['pubmeds']->count(),
        ]);

        // Pre-load all SGC IDs from the worksheet for batch lookup
        // This avoids N+1 queries when processing republish/unpublish actions
        $sgcIdsInFile = [];
        $preloadRowNum = 0;
        foreach ($firstsheet as $row) {
            if ($preloadRowNum++ < 6) continue; // Skip header rows
            if (empty(implode('', $row->toArray()))) continue; // Skip blank rows

            $sgcId = trim($row['sgc_id'] ?? '');
            if (!empty($sgcId)) {
                $sgcIdsInFile[] = $sgcId;
            }
        }
        $sgcIdsInFile = array_unique($sgcIdsInFile);

        // Batch query all existing submissions for these SGC IDs
        // This single query replaces potentially thousands of individual queries
        $existingSubmissionsCache = collect();
        if (!empty($sgcIdsInFile)) {
            $existingSubmissionsCache = Submission::where('submitter_id', $document->submitter_id)
                ->whereIn('sid', $sgcIdsInFile)
                ->where('is_live', true) // Only get live submissions for republish/unpublish
                ->get()
                ->keyBy('sid');

            \Log::info('DocumentController@parser: Pre-loaded existing submissions', [
                'sgc_ids_in_file' => count($sgcIdsInFile),
                'submissions_found' => $existingSubmissionsCache->count(),
            ]);
        }

        // process the rows
        foreach ($firstsheet as $row)
        {

            if ($rownum++ < 6)
                continue;

            // Debug logging disabled for performance - uncomment if needed
            // \Log::info("Row: " . $rownum . " Contents: " . $row);

            // Trim all cell values to remove leading/trailing whitespace, tabs, and newlines
            $row = $row->map(fn($value) => is_string($value) ? trim($value) : $value);

            // do not process blank lines
            if (empty(implode('', $row->toArray())))
                continue;

            $processedRows++;

            // Check wall-clock timeout (measures real time, not just CPU time)
            $elapsedTime = microtime(true) - $startTime;
            if ($elapsedTime > $wallClockTimeoutSeconds) {
                \Log::warning('DocumentController@parser: Wall-clock timeout reached', [
                    'elapsed_seconds' => $elapsedTime,
                    'timeout_limit' => $wallClockTimeoutSeconds,
                    'processed_rows' => $processedRows,
                    'total_rows' => $totalRows
                ]);

                $shutdownHandled = true;

                // Return partial results with error
                $totalSubmissions = $totalRows; // Already excludes headers and empty rows
                return [
                    'processed_rows' => $processedRows,
                    'total_rows' => $totalSubmissions,
                    'errors' => [[
                        'error_type' => 'partial_upload',
                        'severity' => 'warning',
                        'message' => "Upload timed out after processing {$processedRows} of {$totalSubmissions} submissions. The upload process exceeded the time limit and was stopped. The remaining submissions will not be included when submitting this job. Splitting your file into smaller batches is recommended for the remaining submissions.",
                        'processed_rows' => $processedRows,
                        'total_rows' => $totalSubmissions,
                        'elapsed_seconds' => round($elapsedTime, 2)
                    ]]
                ];
            }

            // Get the action from the row (N, R, or U)
            $action = strtoupper(trim($row['action'] ?? 'N'));
            \Log::info('DocumentController@parser: Processing row with action: ' . $action);

            // Handle based on action type
            if ($action === 'N') {
                // New submission
                \Log::info('DocumentController@parser creating new submission...');
                $submission = new Submission();
                $submission->submitter_id = $document->submitter_id;
                $existingSubmissionState = null;
            } elseif ($action === 'R' || $action === 'U') {
                // Republish or Unpublish - must have SGC_ID
                if (empty($row['sgc_id'])) {
                    $message = [
                        'ident' => $document->job->ident,
                        'status' => 'header_error',
                        'validation_results' => 'Error: Action ' . $action . ' requires SGC_ID. Row:' . $processedRows
                    ];
                    SpreadsheetUpdate::dispatch((object) $message);
                    \Log::error('DocumentController@parser: Action ' . $action . ' requires SGC_ID, skipping row ' . $rownum);
                    continue;
                }

                \Log::info('DocumentController@parser looking up submission sid by sgc_id: ' . $row['sgc_id']);
                // Use pre-loaded cache for O(1) lookup instead of per-row database query
                $originalSubmission = $existingSubmissionsCache->get($row['sgc_id']);
                if ($originalSubmission === null) {
                    $message = [
                        'ident' => $document->job->ident,
                        'status' => 'header_error',
                        'validation_results' => 'Error: SGC ID ' . $row['sgc_id'] . ' not found! Row:' . $processedRows
                    ];
                    SpreadsheetUpdate::dispatch((object) $message);
                    \Log::error('DocumentController@parser: SGC ID ' . $row['sgc_id'] . ' not found, skipping row ' . $rownum);
                    continue;
                }

                // Note: Gene change validation for Republish is now handled during file validation
                // (see SubmissionFileValidation::validate_sgc_ids_batch)

                // Store the current status to determine state transition
                $existingSubmissionState = $originalSubmission->status;

                if ($action === 'R') {
                    // For Republish: Create a NEW submission record with incremented version_number
                    // This matches the API behavior in SubmissionController@republish
                    $maxVersion = Submission::where('sid', $row['sgc_id'])->max('version_number') ?? 1;
                    $newVersionNumber = $maxVersion + 1;

                    // Check if there's already a draft version being processed
                    $existingDraft = $submitter->submissions()
                        ->where('sid', $row['sgc_id'])
                        ->whereIn('status', [Submission::STATUS_DRAFT_REPUBLISH, Submission::STATUS_SUBMITTED_REPUBLISH])
                        ->first();

                    if ($existingDraft) {
                        // Use the existing draft instead of creating a new one
                        \Log::info("DocumentController@parser: Using existing draft version for {$row['sgc_id']} (version {$existingDraft->version_number})");
                        $submission = $existingDraft;
                    } else {
                        // Create a new version record
                        $submission = $originalSubmission->replicate(['ident']);
                        $submission->ident = \Illuminate\Support\Str::uuid()->toString();
                        $submission->version_number = $newVersionNumber;
                        $submission->released_at = null;
                        \Log::info("DocumentController@parser: Created new version {$newVersionNumber} for republish of {$row['sgc_id']}");
                    }
                } else {
                    // For Unpublish: Also create a NEW version record (same as republish)
                    // This preserves the original published record and creates an audit trail
                    $maxVersion = Submission::where('sid', $row['sgc_id'])->max('version_number') ?? 1;
                    $newVersionNumber = $maxVersion + 1;

                    // Check if there's already a draft unpublish version being processed
                    $existingDraft = $submitter->submissions()
                        ->where('sid', $row['sgc_id'])
                        ->whereIn('status', [Submission::STATUS_DRAFT_UNPUBLISH, Submission::STATUS_SUBMITTED_UNPUBLISH])
                        ->first();

                    if ($existingDraft) {
                        // Use the existing draft instead of creating a new one
                        \Log::info("DocumentController@parser: Using existing unpublish draft for {$row['sgc_id']} (version {$existingDraft->version_number})");
                        $submission = $existingDraft;
                    } else {
                        // Create a new version record for unpublish
                        $submission = $originalSubmission->replicate(['ident']);
                        $submission->ident = \Illuminate\Support\Str::uuid()->toString();
                        $submission->version_number = $newVersionNumber;
                        $submission->released_at = null;
                        \Log::info("DocumentController@parser: Created new version {$newVersionNumber} for unpublish of {$row['sgc_id']}");
                    }
                }
            } else {
                // Invalid action
                \Log::error('DocumentController@parser: Invalid action ' . $action . ', skipping row ' . $rownum);
                continue;
            }

            // created_at is auto-set by Laravel when saving

            $data = new Nodal();
            $data->sgc_id = $row['sgc_id'];
            $data->submission_label = $row['local_key'];  // delete?
            $data->local_key = $row['local_key'];
            $data->hgnc_id = $row['hgnc_id'];
            $data->gene_symbol = $row['hgnc_symbol'];
            $data->mondo_id = $row['disease_id'];
            $data->disease_name = $row['disease_name'];
            $data->hp_id = $row['moi_id'];
            $data->moi_name = $row['moi_name'];

            // the date can get tricky due to excels auto format
            if (is_numeric($row['date']))
                $date = Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($row['date']));
            else
            {
                try {
                    $date = Carbon::parse($row['date']);
                } catch (InvalidFormatException $_) {
                    $date = null;
                }
            }

            $data->report_date = $date;
            $data->report_url = $row['public_report_url'];
            $data->gencc_classification_id = $row['classification_id'];
            $data->gencc_classification_name = $row['classification_name'];
            $data->criteria_url = $row['assertion_criteria_url'];

            // deal with some accidental separators
            $data->evidence_items = $this->process_pmids($row['pmids']);

            $data->notes_display = $row['notes'];
            $data->notes_private = "File " . $document->file_name . " Row " . $rownum;

            // Capture submitter info for original_submission_data
            $data->submitter_curie = $document->submitter->curie ?? '';
            $data->submitter_title = $document->submitter->name ?? '';

            $check = $document->submitter->submissions()->sid($row['local_key'])->first();
            if ($check === null)
            {
                $data->version_display = "1.0";
                $data->version_internal = "1.0.0";
                $data->reason_codes = ["NEW_CURATION"];
            }
            else
            {
                $oldversion = $check->submission_data->version->display ?? "0";
                $oldversion = explode('.', $oldversion);
                $newversion = (int) $oldversion[0] + 1;

                // assume a major number update
                $data->version_display = $newversion . ".0";
                $data->version_internal = $newversion . ".0.0";
                $data->reason_codes = ["RECURATION"];
            }

            // load the template
            $template = view('json.spreadsheet')->with('d', $data);
            $rendered = $template->render();
            $obj = json_decode($rendered);

            if ($obj === null) {
                \Log::error("DocumentController@parser: Failed to decode JSON for row {$rownum}. Template output: " . substr($rendered, 0, 500));
                $message = [
                    'ident' => $document->job->ident,
                    'status' => 'header_error',
                    'validation_results' => 'Error: Failed to process row ' . $processedRows . '. Invalid data format.'
                ];
                SpreadsheetUpdate::dispatch((object) $message);
                continue;
            }

            \Log::info("JSON object from template: " . json_encode($obj));

            $job = $document->job;

            // For Unpublish action, skip data loading and just set status
            if ($action === 'U') {
                \Log::info('DocumentController@parser: Unpublish action - new version already created, setting status');

                // Set status to draft_unpublish (new version record was already created above)
                $submission->status = Submission::STATUS_DRAFT_UNPUBLISH;

                // Now associate with draft job and document, then save
                $submission->user_id = $document->user_id;
                $submission->job_id = $job->id;
                $submission->document_id = $document->id;
                $submission->save();

                // Mark the original submission as not most recent (new draft is now the most recent version)
                if (isset($originalSubmission) && $originalSubmission->is_most_recent) {
                    $originalSubmission->is_most_recent = false;
                    $originalSubmission->save();
                    \Log::info("DocumentController@parser: Marked original submission as not most recent");
                }

                // Copy pubmed associations from original submission
                if (isset($originalSubmission)) {
                    $pubmedIds = $originalSubmission->pubmeds()->pluck('pubmeds.id')->toArray();
                    $submission->pubmeds()->sync($pubmedIds);
                    \Log::info("DocumentController@parser: Copied " . count($pubmedIds) . " pubmed associations to unpublish version");
                }

                $successfulSubmissions++;
            } else {
                // For New (N) and Republish (R) actions, load data from spreadsheet
                $status = $submission->load_from_json($obj, $lookupCaches);
                if ($status === true)
                {
                    $submission->user_id = $document->user_id;

                    // Set status based on action type
                    if ($action === 'R') {
                        // Republish: Set status to draft_republish
                        // New version record was already created above with version_number incremented
                        $submission->status = Submission::STATUS_DRAFT_REPUBLISH;
                        \Log::info('DocumentController@parser: Setting republish status to draft_republish');
                    } elseif ($action === 'N') {
                        // New submission: set status to draft_new
                        \Log::info('DocumentController@parser: Setting new submission status to draft_new');
                        $submission->status = Submission::STATUS_DRAFT_NEW;
                    }

                    // Now associate with draft job and document, then save
                    $submission->job_id = $job->id;
                    $submission->document_id = $document->id;
                    $submission->save();

                    // For republish, mark the original submission as not most recent
                    if ($action === 'R' && isset($originalSubmission) && $originalSubmission->is_most_recent) {
                        $originalSubmission->is_most_recent = false;
                        $originalSubmission->save();
                        \Log::info("DocumentController@parser: Marked original submission as not most recent");
                    }

                    // For republish, copy pubmed associations from original submission
                    if ($action === 'R' && isset($originalSubmission)) {
                        $pubmedIds = $originalSubmission->pubmeds()->pluck('pubmeds.id')->toArray();
                        $submission->pubmeds()->sync($pubmedIds);
                        \Log::info("DocumentController@parser: Copied " . count($pubmedIds) . " pubmed associations to new version");
                    }

                    $successfulSubmissions++;
                }
                else
                {
                    $message = [
                        'ident' => $document->job->ident,
                        'status' => 'header_error',
                        'validation_results' => 'SGC ID ' . $submission->sid . ' encountered submission errors. Row: ' . $processedRows
                    ];
                    SpreadsheetUpdate::dispatch((object) $message);
                    $job->addEvent('SGC ID ' . $submission->sid . ' encountered submission errors');
                    $submission->submission_errors = $status;
                    // Errors are determined by submission_errors via has_errors accessor
                    // Set draft status so the submission is editable/deletable in the UI
                    if ($action === 'R') {
                        $submission->status = Submission::STATUS_DRAFT_REPUBLISH;
                    } elseif ($action === 'N') {
                        $submission->status = Submission::STATUS_DRAFT_NEW;
                    }
                    $submission->user_id = $document->user_id;
                    $submission->document_id = $document->id;
                    $job->submissions()->save($submission);

                    // Collect error details for summary
                    $erroredSubmissions[] = [
                        'row' => $rownum,
                        'submission_id' => $data->local_key,
                        'sgc_id' => $data->sgc_id,
                        'errors' => $status
                    ];
                }
            }

            // we can now update the evidence pivot table entries
            $submission->pubmeds()->detach();

            if (isset($submission->submission_data->evidence) && is_array($submission->submission_data->evidence)) {
                // Collect all pubmed IDs to attach in one query
                $pubmedIdsToAttach = [];
                foreach ($submission->submission_data->evidence as $evidence)
                {
                    if (empty($evidence->pmid))
                        continue;

                    // Use the cache instead of querying each time
                    $pubmed = $lookupCaches['pubmeds']->get($evidence->pmid);

                    if ($pubmed === null)
                        continue;

                    $pubmedIdsToAttach[] = $pubmed->id;
                }

                // Attach all PMIDs in one query
                if (!empty($pubmedIdsToAttach)) {
                    $submission->pubmeds()->attach($pubmedIdsToAttach);
                }
            }

            // Update progress every 10 rows
            if ($processedRows % 10 == 0) {
                $document->update(['processed_submissions' => $processedRows]);
                \Log::info("DocumentController@parser: Progress update - {$processedRows} of {$totalRows} submissions processed");
            }
        }

        // Batch process all PMIDs from this upload
        // Step 1: Collect all unique PMIDs from this job's submissions
        \Log::info('DocumentController@parser: Collecting all PMIDs from job submissions...');
        $allPmids = [];
        foreach ($job->submissions as $submission) {
            if ($submission->submission_data && isset($submission->submission_data->evidence)) {
                foreach ($submission->submission_data->evidence as $evidence) {
                    if (!empty($evidence->pmid) && is_numeric($evidence->pmid)) {
                        $allPmids[$evidence->pmid] = true; // Use associative array for uniqueness
                    }
                }
            }
        }
        $uniquePmids = array_keys($allPmids);
        $pmidCount = count($uniquePmids);

        if ($pmidCount > 0) {
            \Log::info("DocumentController@parser: Found {$pmidCount} unique PMIDs in job");

            // Step 2: Single query to find which PMIDs don't exist yet
            $existingPmids = Pubmed::whereIn('pmid', $uniquePmids)->pluck('pmid')->toArray();
            $missingPmids = array_diff($uniquePmids, $existingPmids);
            $missingCount = count($missingPmids);

            // Step 3: Bulk insert missing PMIDs with STATUS_INITIALIZING
            if ($missingCount > 0) {
                \Log::info("DocumentController@parser: Adding {$missingCount} missing PMIDs to database with STATUS_INITIALIZING");
                $insertData = [];
                foreach ($missingPmids as $pmid) {
                    $insertData[] = [
                        'pmid' => $pmid,
                        'uid' => $pmid,
                        'status' => Pubmed::STATUS_INITIALIZING,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
                Pubmed::insert($insertData);
                \Log::info('DocumentController@parser: PMID batch insert complete');
            } else {
                \Log::info('DocumentController@parser: All PMIDs already exist in database');
            }
        } else {
            \Log::info('DocumentController@parser: No PMIDs found in job submissions');
        }

        // Mark shutdown as handled since we completed normally
        $shutdownHandled = true;

        \Log::info('DocumentController@parser: Processing complete', [
            'processed_rows' => $processedRows,
            'total_rows' => $totalRows
        ]);

        // Return structured result
        return [
            'processed_rows' => $processedRows,
            'total_rows' => $totalRows,
            'errors' => null
        ];
    }


    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }


    /**
     * The original GenCC spreadsheeds did not enforce a format, so the PMID
     * list is all over the place.  Some use ; as a separator.  Some include
     * [PMID].  Some include _.  This function attempts to clean it all up.
     */
    public function process_pmids($list)
    {
        if (empty(trim($list))) {
            return [];
        }

        $result = \App\Services\PmidNormalizer::normalize($list);
        return $result['pmids'];
    }


    /**
     * Get error report as JSON for a document
     */
    public function get_errors(int $id)
    {
        $user = Auth::user();
        $document = Document::findOrFail($id);

        if (empty($document->processing_errors)) {
            return response()->json(['errors' => []], 200);
        }

        // processing_errors is already cast to array by the model
        return response()->json([
            'errors' => $document->processing_errors
        ], 200);
    }

    /**
     * Download a document file
     */
    public function download(Request $request, string $ident)
    {
        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);

        // Find document by ident
        $document = Document::where('ident', $ident)
            ->where('submitter_id', $effectiveSubmitterId)
            ->firstOrFail();

        // Verify file contents exist in database
        if (empty($document->file_contents)) {
            abort(404, 'File not found');
        }

        // Decode base64 file contents
        $contents = base64_decode($document->file_contents);
        if ($contents === false) {
            abort(500, 'Failed to decode file contents');
        }

        // Return the file as a download
        return response($contents, 200, [
            'Content-Type' => $document->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $document->file_name . '"',
            'Content-Length' => strlen($contents),
        ]);
    }

    /**
     * Process the specified resource from storage.
     */
    public function process($document)
    {
        // quick check if file exists in database
        if (empty($document->file_contents)) {
            return;
        }

        // quick check to see if this is still a valid work job or has another process completed it
        if (Document::ident($document->ident)->where('status', Document::STATUS_STORED_PROCESSED)->exists())
            return;

        // process the spreadsheet
        $this->parser($document);

        // send notification to user
        $message = [
            'ident' => $document->job->ident,
            'size' => $document->size,
            'status' => 'complete'
        ];

        SpreadsheetUpdate::dispatch((object) $message);

    }
}
