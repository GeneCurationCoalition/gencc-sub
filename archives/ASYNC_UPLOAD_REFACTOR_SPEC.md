# Async Upload Workflow - Technical Specification

**STATUS: FULLY IMPLEMENTED** ✅

This document originally specified the refactoring of the submission upload process. **The implementation has been completed** and is now in production use. This document serves as technical reference documentation for the async upload system.

## Overview

The submission upload process separates validation from background processing, eliminating WebSocket timing issues and improving UX.

## Current Problems

1. **WebSocket connection delays** - Button clicks blocked by synchronous WebSocket setup (13+ seconds)
2. **Monolithic upload process** - Validation and processing tightly coupled in one synchronous operation
3. **Poor error UX** - Validation failures lose file context, user must re-upload
4. **UI blocking** - User must wait for entire upload to complete before navigating away

## New Architecture

### Workflow Phases

**Phase 1: Upload & Validate (Synchronous)**
- User uploads Excel file
- Blocking dialog shows validation progress
- File format checked, row count verified
- On completion: File associated with job REGARDLESS of validation result
- State transition: `null` → `validating` → `validation_failed` OR `validated`

**Phase 2: Background Upload (Asynchronous via Queue)**
- Triggered automatically after successful validation
- Laravel queue job processes submissions in background
- User can navigate away - job continues
- Job page shows real-time progress via polling (NOT WebSocket)
- State transition: `validated` → `uploading` → `upload_complete` OR `upload_partial`

### State Machine

```
Document.upload_state transitions:

null (no file)
  ↓ [user uploads file]
validating (sync validation running)
  ↓ [validation complete]
  ├→ validation_failed (has errors, file kept, user can view/clear/replace)
  └→ validated (ready for background processing)
      ↓ [queue job dispatched]
    uploading (background job processing)
      ↓ [processing complete]
      ├→ upload_complete (all submissions processed)
      └→ upload_partial (timeout, some submissions processed)
```

### Job Locking

When `documents.upload_state IN ('uploading')`:
- `jobs.is_processing = true`
- All edit buttons disabled
- Navigation to edit views prevented
- Read-only banner displayed with progress

---

## Backend Implementation

### 1. DocumentController Refactoring

**File:** `app/Http/Controllers/API/DocumentController.php`

#### Method: `upload()` (MODIFY EXISTING)

**Current behavior:** Validates file, returns row count OR errors
**New behavior:** Same + associate file to job with upload_state

```php
public function upload(Request $request)
{
    // ... existing file storage logic ...

    $document = Document::create([
        // ... existing fields ...
        'upload_state' => Document::UPLOAD_STATE_VALIDATING,
    ]);

    // Run validation (read-only pass through Excel)
    $validationResult = $this->validateFile($document);

    if ($validationResult['has_errors']) {
        // NEW: Keep file, mark validation failed
        $document->update([
            'upload_state' => Document::UPLOAD_STATE_VALIDATION_FAILED,
            'processing_errors' => $validationResult['errors'],
            'total_submissions' => $validationResult['row_count'],
        ]);

        return response()->json([
            'success' => false,
            'document_id' => $document->id,
            'errors' => $validationResult['errors'],
        ], 422);
    }

    // Validation passed
    $document->update([
        'upload_state' => Document::UPLOAD_STATE_VALIDATED,
        'total_submissions' => $validationResult['row_count'],
    ]);

    // Lock job for processing
    $document->job->update(['is_processing' => true]);

    // Dispatch background job
    ProcessSubmissionsUpload::dispatch($document);

    return response()->json([
        'success' => true,
        'document_id' => $document->id,
        'row_count' => $validationResult['row_count'],
        'message' => 'Validation passed - background processing started',
    ], 200);
}
```

#### Method: `validateFile()` (NEW - EXTRACT FROM EXISTING parser())

Read-only pass through Excel file to check format and count rows.

```php
private function validateFile($document)
{
    $errors = [];
    $rowCount = 0;

    try {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($document->local_path);
        $worksheet = $spreadsheet->getActiveSheet();

        // Check for required columns (row 6)
        $headerRow = $worksheet->rangeToArray('A6:Z6')[0];
        $requiredColumns = ['gene_curie', 'disease_curie', 'classification', /* ... */];

        foreach ($requiredColumns as $col) {
            if (!in_array($col, $headerRow)) {
                $errors[] = [
                    'error_type' => 'missing_column',
                    'severity' => 'error',
                    'message' => "Required column missing: {$col}",
                ];
            }
        }

        // Count data rows (starting from row 7)
        $rowCount = $worksheet->getHighestRow() - 6;

        if ($rowCount === 0) {
            $errors[] = [
                'error_type' => 'empty_file',
                'severity' => 'error',
                'message' => 'No submission data found in file',
            ];
        }

    } catch (\Exception $e) {
        $errors[] = [
            'error_type' => 'file_read_error',
            'severity' => 'error',
            'message' => 'Could not read Excel file: ' . $e->getMessage(),
        ];
    }

    return [
        'has_errors' => count($errors) > 0,
        'errors' => $errors,
        'row_count' => $rowCount,
    ];
}
```

#### Method: `parser()` (MODIFY EXISTING)

Remove validation logic, keep only submission processing logic. This will be called by the queue job.

```php
public function parser($document, $validateOnly = false)
{
    // Remove $validateOnly parameter (no longer needed)
    // Remove validation logic (moved to validateFile())
    // Keep only: Excel reading + Submission creation loop
    // Update: Set processed_submissions progress as we go
    // Update: Handle timeouts by returning partial results
}
```

#### Method: `clearDocument()` (NEW)

Allow user to remove failed/completed document from job.

```php
public function clearDocument(Request $request, $documentId)
{
    $document = Document::findOrFail($documentId);
    $job = $document->job;

    // Can only clear if not currently uploading
    if ($document->upload_state === Document::UPLOAD_STATE_UPLOADING) {
        return response()->json([
            'success' => false,
            'message' => 'Cannot clear document while upload is in progress',
        ], 400);
    }

    // Soft delete document
    $document->delete();

    // Unlock job
    $job->update(['is_processing' => false]);

    return response()->json(['success' => true], 200);
}
```

### 2. ProcessSubmissionsUpload Queue Job

**File:** `app/Jobs/ProcessSubmissionsUpload.php`

```php
<?php

namespace App\Jobs;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSubmissionsUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels, Queueable;

    public $timeout = 3600; // 1 hour
    public $tries = 1; // Don't retry - partial results are saved

    protected $document;

    public function __construct(Document $document)
    {
        $this->document = $document;
    }

    public function handle()
    {
        \Log::info('ProcessSubmissionsUpload: Starting', [
            'document_id' => $this->document->id,
        ]);

        // Update state to uploading
        $this->document->update([
            'upload_state' => Document::UPLOAD_STATE_UPLOADING,
            'upload_started_at' => now(),
            'processed_submissions' => 0,
        ]);

        // Call the existing parser method (now refactored to only process)
        $controller = new \App\Http\Controllers\API\DocumentController();
        $result = $controller->parser($this->document);

        // Determine final state based on result
        $finalState = ($result['processed_rows'] === $result['total_rows'])
            ? Document::UPLOAD_STATE_UPLOAD_COMPLETE
            : Document::UPLOAD_STATE_UPLOAD_PARTIAL;

        // Update document with final results
        $this->document->update([
            'upload_state' => $finalState,
            'processed_submissions' => $result['processed_rows'],
            'upload_completed_at' => now(),
            'processing_errors' => $result['errors'] ?? null,
        ]);

        // Unlock job
        $this->document->job->update(['is_processing' => false]);

        \Log::info('ProcessSubmissionsUpload: Complete', [
            'document_id' => $this->document->id,
            'state' => $finalState,
            'processed' => $result['processed_rows'],
            'total' => $result['total_rows'],
        ]);
    }

    public function failed(\Throwable $exception)
    {
        \Log::error('ProcessSubmissionsUpload: Failed', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        // Mark as failed, unlock job
        $this->document->update([
            'upload_state' => Document::UPLOAD_STATE_UPLOAD_PARTIAL,
            'upload_completed_at' => now(),
            'processing_errors' => [[
                'error_type' => 'queue_job_failed',
                'severity' => 'error',
                'message' => 'Background processing failed: ' . $exception->getMessage(),
            ]],
        ]);

        $this->document->job->update(['is_processing' => false]);
    }
}
```

### 3. Polling API Endpoint

**File:** `routes/api.php`

```php
// Add new route for polling upload progress
Route::get('/jobs/{id}/upload-progress', [JobController::class, 'uploadProgress']);
```

**File:** `app/Http/Controllers/JobController.php`

```php
public function uploadProgress($id)
{
    $job = Job::with('documents')->findOrFail($id);
    $document = $job->documents->first();

    if (!$document) {
        return response()->json(['has_document' => false], 200);
    }

    return response()->json([
        'has_document' => true,
        'upload_state' => $document->upload_state,
        'processed_submissions' => $document->processed_submissions,
        'total_submissions' => $document->total_submissions,
        'progress_percent' => $document->total_submissions > 0
            ? round(($document->processed_submissions / $document->total_submissions) * 100)
            : 0,
        'is_processing' => $job->is_processing,
        'processing_errors' => $document->processing_errors,
    ], 200);
}
```

### 4. Update JobController Queries

**File:** `app/Http/Controllers/JobController.php`

Update all queries to include new document fields:

```php
// In show() method
->with(['documents' => function ($query) {
    $query->select('id', 'ident', 'job_id', 'file_name', 'extension', 'size',
                   'upload_state', 'processed_submissions', 'total_submissions',
                   'processing_errors', 'created_at');
}])

// Add is_processing to job selects
->select('jobs.*', 'is_processing', /* ... */)
```

---

## Frontend Implementation

### 1. JobItem.vue Refactoring

**File:** `resources/js/Components/JobItem.vue`

#### Remove WebSocket Logic

Delete all:
- `setupUploadProgressListener()`
- `window.Echo.channel()` calls
- `echoChannel` variable
- All WebSocket event listeners
- `onMounted()` / `onUnmounted()` WebSocket setup/teardown

#### Add Polling Logic

```javascript
// Polling state
const pollingInterval = ref(null);
const isPolling = ref(false);

// Start polling for upload progress
const startPollingProgress = () => {
  if (isPolling.value) return;

  console.log('[Upload] Starting progress polling');
  isPolling.value = true;

  // Poll every 2 seconds
  pollingInterval.value = setInterval(async () => {
    try {
      const response = await axios.get(`/api/jobs/${props.job.id}/upload-progress`);
      const data = response.data;

      if (!data.has_document) {
        stopPollingProgress();
        return;
      }

      // Update progress
      uploadingDialog.value.processedRows = data.processed_submissions;
      uploadingDialog.value.totalRows = data.total_submissions;
      uploadingDialog.value.progress = data.progress_percent;

      // Check if complete
      if (data.upload_state === 'upload_complete' || data.upload_state === 'upload_partial') {
        console.log('[Upload] Processing complete');
        stopPollingProgress();
        uploadingDialog.value.visible = false;
        router.reload();
      }

    } catch (error) {
      console.error('[Upload] Polling error:', error);
      stopPollingProgress();
    }
  }, 2000);
};

const stopPollingProgress = () => {
  if (pollingInterval.value) {
    clearInterval(pollingInterval.value);
    pollingInterval.value = null;
  }
  isPolling.value = false;
};

// Cleanup on unmount
onUnmounted(() => {
  stopPollingProgress();
});
```

#### Simplify Upload Flow

```javascript
const uploadFile = async (event) => {
  const file = event.files[0];
  uploadedFilename.value = file.name;

  // Clear previous errors
  showErrorCard.value = false;
  uploadErrors.value = [];

  // Show validating dialog
  validatingDialog.value.visible = true;
  validatingDialog.value.message = 'Validating file format...';
  uploadState.value = 'validating';

  const formData = new FormData();
  formData.append('file', file);

  try {
    const response = await axios.post(`/api/documents/${props.job.id}`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    // Validation successful
    validatingDialog.value.visible = false;
    uploadState.value = 'idle';

    // Show uploading dialog and start polling
    uploadingDialog.value.visible = true;
    uploadingDialog.value.processedRows = 0;
    uploadingDialog.value.totalRows = response.data.row_count;
    uploadingDialog.value.progress = 0;

    startPollingProgress();

  } catch (error) {
    // Validation failed or other error
    validatingDialog.value.visible = false;
    uploadState.value = 'idle';

    if (error.response?.data?.errors) {
      // Show validation errors
      displayErrorCard(error.response.data.errors);
    } else {
      // Show generic error
      displayErrorCard([{
        error_type: 'upload_error',
        severity: 'error',
        message: error.response?.data?.message || 'Upload failed',
      }]);
    }
  }
};
```

#### Update UI for New States

Show different messages based on `upload_state`:

```vue
<!-- Validation failed state -->
<div v-if="job.documents[0].upload_state === 'validation_failed'" class="mt-2">
  <button @click="showValidationErrors" class="text-red-600">
    ⚠️ Validation Failed - Click to view errors
  </button>
  <button @click="clearDocument" class="ml-2 text-gray-600">
    Clear File
  </button>
</div>

<!-- Uploading state -->
<div v-if="job.documents[0].upload_state === 'uploading'" class="mt-2">
  <div class="flex items-center gap-2">
    <i class="pi pi-spin pi-spinner"></i>
    <span>Uploading submissions... {{ job.documents[0].processed_submissions }} of {{ job.documents[0].total_submissions }}</span>
  </div>
</div>

<!-- Upload complete -->
<div v-if="job.documents[0].upload_state === 'upload_complete'" class="mt-2">
  <span class="text-green-600">✓ {{ job.documents[0].total_submissions }} submissions uploaded</span>
</div>

<!-- Upload partial -->
<div v-if="job.documents[0].upload_state === 'upload_partial'" class="mt-2">
  <button @click="showPartialUploadWarning" class="text-orange-600">
    ⚠️ Partial Upload - {{ job.documents[0].processed_submissions }} of {{ job.documents[0].total_submissions }} processed
  </button>
</div>
```

### 2. Job.vue - Read-Only Banner

**File:** `resources/js/Pages/Job.vue`

Add banner at top of page when `job.is_processing === true`:

```vue
<template>
  <!-- Read-only banner during upload -->
  <div v-if="job.is_processing" class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-4">
    <div class="flex items-center gap-3">
      <i class="pi pi-spin pi-spinner text-blue-600 text-2xl"></i>
      <div class="flex-1">
        <h3 class="font-semibold text-blue-900">Submissions Uploading</h3>
        <p class="text-sm text-blue-700">
          This job is read-only while submissions are being uploaded in the background.
        </p>
        <div v-if="uploadProgress" class="mt-2">
          <div class="flex items-center gap-2 text-sm">
            <span>Progress: {{ uploadProgress.processed }} of {{ uploadProgress.total }} submissions</span>
            <span class="font-semibold">{{ uploadProgress.percent }}%</span>
          </div>
          <div class="w-full bg-blue-200 rounded-full h-2 mt-1">
            <div class="bg-blue-600 h-2 rounded-full transition-all" :style="{ width: uploadProgress.percent + '%' }"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Rest of page... -->
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

const props = defineProps(['job']);

const uploadProgress = ref(null);
let pollingInterval = null;

// Poll for progress if job is processing
const startPolling = () => {
  if (!props.job.is_processing) return;

  pollingInterval = setInterval(async () => {
    try {
      const response = await axios.get(`/api/jobs/${props.job.id}/upload-progress`);
      uploadProgress.value = {
        processed: response.data.processed_submissions,
        total: response.data.total_submissions,
        percent: response.data.progress_percent,
      };

      // Reload page if complete
      if (!response.data.is_processing) {
        clearInterval(pollingInterval);
        router.reload();
      }
    } catch (error) {
      console.error('Polling error:', error);
    }
  }, 2000);
};

onMounted(() => {
  if (props.job.is_processing) {
    startPolling();
  }
});

onUnmounted(() => {
  if (pollingInterval) {
    clearInterval(pollingInterval);
  }
});

// Disable all edit actions if job.is_processing
const canEdit = computed(() => !props.job.is_processing);
</script>
```

Disable all edit buttons/links when `!canEdit`:

```vue
<Button :disabled="!canEdit" @click="editSubmission">Edit</Button>
<a v-if="canEdit" href="...">Add Submission</a>
<span v-else class="text-gray-400">Add Submission (locked)</span>
```

### 3. Jobs Listing - Upload Spinner

**File:** `resources/js/Components/JobsListing.vue`

Add indicator on job card when `job.is_processing`:

```vue
<div class="flex items-center gap-2">
  <h3>{{ job.slug }}</h3>
  <div v-if="job.is_processing" class="flex items-center gap-1 text-blue-600 text-sm">
    <i class="pi pi-spin pi-spinner"></i>
    <i class="pi pi-file-upload"></i>
    <span>Uploading...</span>
  </div>
</div>
```

---

## Queue Configuration

### Required Setup

1. **Queue Driver**: Update `.env` to use `database` queue driver

```bash
QUEUE_CONNECTION=database
```

2. **Queue Worker**: Start queue worker process

```bash
php artisan queue:work --timeout=3600
```

For production, use Supervisor to keep queue worker running:

```ini
[program:gencc-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/gencc-sub/artisan queue:work --sleep=3 --tries=1 --timeout=3600
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/gencc-sub/storage/logs/queue-worker.log
```

3. **Queue Monitoring**: Consider using Laravel Horizon for better queue management (optional)

---

## Testing Strategy

### Unit Tests

1. **Document State Transitions**
   - Test each state transition
   - Verify state machine constraints

2. **Queue Job**
   - Test successful processing
   - Test timeout handling (partial uploads)
   - Test failure handling

### Integration Tests

1. **Validation Flow**
   - Upload file with errors → validation_failed state
   - Upload valid file → validated state + queue job dispatched

2. **Background Processing**
   - Job processes submissions
   - Progress updates correctly
   - Final state correct (complete vs partial)

3. **Polling**
   - API returns correct progress
   - Frontend updates UI based on poll results

### Manual Testing Scenarios

1. **Happy Path**
   - Upload valid file
   - Watch progress update
   - Verify all submissions created
   - Confirm job unlocked

2. **Validation Errors**
   - Upload file with missing columns
   - Verify errors displayed
   - Clear file, upload new one

3. **Timeout Handling**
   - Upload very large file (set timeout to 60sec for testing)
   - Verify partial state
   - Check partial submissions created

4. **Browser Close**
   - Upload file, start processing
   - Close browser tab
   - Open job again → verify processing continues
   - Poll picks up progress

---

## Migration Path

### Phase 1: Backend ✅ COMPLETE
- ✅ Migration created and run
- ✅ Models updated
- ✅ DocumentController refactored ([DocumentController.php:59-494](../app/Http/Controllers/API/DocumentController.php))
  - Validation separated into `validateFile()` method
  - `store()` method dispatches `ProcessSubmissionsUpload` queue job
  - `clearDocument()` and `clearValidDocument()` methods added
- ✅ Queue job implemented ([ProcessSubmissionsUpload.php](../app/Jobs/ProcessSubmissionsUpload.php))
- ✅ Polling functionality integrated into existing endpoints (frontend polls job status directly)

### Phase 2: Frontend ✅ COMPLETE
- ✅ WebSocket code retained (still used for real-time events)
- ✅ Add polling logic ([JobItem.vue:344-398](../resources/js/Components/JobItem.vue))
  - `startPolling()` checks upload progress every 2 seconds
  - Automatically stops when upload completes
  - Resumes polling if page reloaded during processing
- ✅ Update UI for new states
  - Validation dialog shows during sync validation
  - Uploading progress tracked with processed/total counts
  - Error cards show validation failures with detailed error tables
- ✅ Job processing indicators
  - Spinner shown during draft job upload processing
  - Jobs listing shows upload indicator
  - Real-time progress updates via polling

### Phase 3: Testing & Production ✅ COMPLETE
- ✅ End-to-end testing completed
- ✅ WebSocket code retained (complementary to polling - used for other real-time updates)
- ✅ Timeouts set to production values (3600s = 1 hour in ProcessSubmissionsUpload)
- ✅ Documentation complete (this document)

---

## Open Questions

1. **Timeout Values**: Currently set to 60 sec for testing. Production should be 3600 (1 hour). Where should this be configured?

2. **Queue Retry Strategy**: Currently `$tries = 1` (no retry). Should failed uploads be retryable?

3. **Concurrent Uploads**: Can multiple jobs upload simultaneously? Or should we enforce one upload at a time per submitter?

4. **Progress Granularity**: Currently updates after each row. Should we batch updates (e.g., every 10 rows) for performance?

5. **Error Recovery**: If background job fails, should we provide a "retry" button, or just "clear and re-upload"?

---

## Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Queue worker not running | Uploads hang in "uploading" state | Add health check, monitoring, Supervisor auto-restart |
| Large file memory exhaustion | Queue job crashes | Set memory limit, chunk processing if needed |
| Database locks during long upload | Other operations blocked | Use proper transaction isolation |
| Polling overhead | Increased server load | Limit poll frequency, add exponential backoff |
| Existing jobs in old format | Migration issues | Add data migration script for in-flight jobs |

---

## Success Criteria - ALL MET ✅

- ✅ User clicks upload, sees validation immediately (< 2 seconds)
- ✅ Validation errors displayed with file kept for review
- ✅ Background upload doesn't block user navigation
- ✅ Progress updates visible in real-time (2-second polling refresh)
- ✅ Job locked during upload via `is_processing` flag (no concurrent edits)
- ✅ Partial uploads handled gracefully with clear error messages
- ✅ WebSocket timing issues eliminated (validation is synchronous, processing is async)
- ✅ Queue worker managed by PM2 with automatic restart on failure

---

## Implementation Notes

### Key Differences from Original Spec:
1. **WebSocket Retained**: Original spec planned to remove WebSocket code entirely, but implementation retained it for complementary real-time event updates (e.g., SpreadsheetUpdate events). Polling is used specifically for upload progress tracking.

2. **Polling Endpoint**: Instead of creating a dedicated `/jobs/{id}/upload-progress` endpoint as originally specified, the implementation polls the job data directly through existing endpoints, checking `document.upload_state` and `document.processed_submissions`.

3. **Timeout Handling**: Added sophisticated timeout handling with:
   - Wall-clock timeout monitoring during processing
   - Shutdown handler to catch fatal errors and unexpected terminations
   - Clear error messages distinguishing timeouts from other failures
   - `upload_state` tracks partial vs complete uploads

4. **State Machine**: `Document.upload_state` transitions implemented as specified:
   - `null` → `validating` → `validation_failed` | `validated`
   - `validated` → `uploading` → `upload_complete` | `upload_partial`

5. **Queue Management**: Uses PM2 for development and Supervisor for production (as documented in deployment guides).
