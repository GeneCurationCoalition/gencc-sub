# Submission Upload Flow - Complete Technical Documentation

## Overview

This document provides a comprehensive explanation of what happens when a user uploads a spreadsheet containing gene-disease submissions to the GenCC Submission Portal. The process involves multiple phases of validation, data transformation, database operations, and real-time UI updates via WebSockets.

---

## High-Level Architecture

**Key Components:**
- **Frontend**: Vue.js + Inertia.js (JobItem.vue component)
- **Backend**: Laravel 10 (DocumentController)
- **Validation**: SubmissionFileValidation service
- **Real-time Updates**: Ably WebSocket (SpreadsheetUpdate events)
- **Database**: MySQL with multiple related tables
- **File Processing**: Maatwebsite Excel package

**Processing Time**: Approximately **1 second per submission row** (for a 2,885 row file ≈ 48 minutes)

---

## Complete Upload Flow

### Phase 1: File Upload & Initial Processing

#### 1. File Upload
**Location**: `DocumentController@store` (lines 53-157)

**What Happens**:
- User selects Excel file via FileUpload component
- Frontend sends POST request to `/api/documents/{job_id}` with file data
- Backend creates a new `Document` record:
  ```php
  Document::create([
      'type' => 1,
      'user_id' => $user->id,
      'submitter_id' => $effectiveSubmitterId,
      'job_id' => $job->id,
      'file_name' => $originalFilename,
      'extension' => $extension,
      'ident' => generated_unique_id
  ])
  ```
- File contents are stored in the database as base64 in the `file_contents` column of the `documents` table
- Broadcasts 'begin' event via WebSocket (Ably) to start UI progress tracking

**Request Parameters**:
- `file`: Excel file upload
- `validate_only` (optional): If true, stops after validation

#### 2. File Parsing
**Location**: `DocumentController@parser` (lines 209-220)

**What Happens**:
- Loads raw Excel file using Maatwebsite Excel package
- Counts total rows (including 6 header rows)
- Broadcasts 'parse' event with row count:
  ```php
  SpreadsheetUpdate::dispatch([
      'ident' => $document->job->ident,
      'size' => $rawFirstsheet->count(),
      'status' => 'parse'
  ]);
  ```
- Frontend receives this and displays "Parsing spreadsheet..." message

---

### Phase 2: Batch Validation

#### 3. Spreadsheet Validation
**Location**: `SubmissionFileValidation::validate_spreadsheet`

**Critical**: This happens **BEFORE any submissions are created**. If validation fails, NO submissions are processed.

**Validation Checks** (in order):

a. **Header Structure Validation**
   - Verifies all required columns exist
   - Checks column order matches expected format
   - Required columns: SGC_ID, Action, Local_Key, HGNC_ID, Disease_ID, MOI_ID, Classification_ID, Date, Report_URL, PMIDs, Notes

b. **SGC_ID Batch Validation** (Performance Optimized)
   - Collects all SGC_IDs from spreadsheet
   - **Single SQL query** fetches all submissions at once:
     ```php
     Submission::whereIn('sid', $all_sgc_ids)
         ->where('submitter_id', $submitter_id)
         ->get()
     ```
   - For each SGC_ID, validates:
     - Existence (for R and U actions)
     - Ownership (belongs to current submitter)
     - State transitions (can't edit if in another draft/submitted job)
     - Valid state for action (e.g., can't unpublish if not published)

c. **Disease ID Format Validation**
   - Validates format: MONDO:XXXXXXX, OMIM:XXXXXX, or ORPHA:XXXXXX
   - Reports rows with invalid formats

d. **PMID Format Validation**
   - Ensures PMIDs are numeric
   - Validates comma-separated lists

e. **Action Field Validation**
   - Valid values: N (New), R (Republish), U (Unpublish)
   - Case-insensitive

**If Validation Fails**:
```php
// Store errors in document
$document->update(['processing_errors' => $formattedErrors]);

// Broadcast error event
SpreadsheetUpdate::dispatch([
    'ident' => $document->job->ident,
    'status' => 'validation_errors',
    'error_count' => count($formattedErrors),
    'document_id' => $document->id
]);

return false; // STOPS PROCESSING
```

**If Validation Passes**: Continue to submission processing

**Validate-Only Mode** (New Feature):
If `validate_only=true`, returns after validation with row count:
```php
SpreadsheetUpdate::dispatch([
    'ident' => $document->job->ident,
    'status' => 'validation_complete',
    'row_count' => $rawFirstsheet->count() - 6,
    'document_id' => $document->id
]);

return ['validated' => true, 'row_count' => $count];
```

---

### Phase 3: Individual Submission Processing

For each row in the spreadsheet (skipping first 6 header rows):

#### 4. Row Preparation
**Location**: `DocumentController@parser` (lines 300-369)

**What Happens**:
- Skip blank rows: `if (empty(implode('', $row->toArray()))) continue;`
- Extract action: N (New), R (Republish), or U (Unpublish)
- Increment processed row counter

**Action-Specific Handling**:

**Action N (New Submission)**:
```php
$submission = new Submission();
$submission->submitter_id = $document->submitter_id;
$existingSubmissionState = null;
```

**Action R (Republish)**:
```php
// Lookup existing submission by SGC_ID
$submission = $submitter->submissions()
    ->where('sid', $row['sgc_id'])
    ->first();

// Verify gene hasn't changed (not allowed)
if ($submission->original_submission_data->gene->id != $row['hgnc_id']) {
    // Log error and skip row
}

$existingSubmissionState = $submission->status; // Store for state transition
```

**Action U (Unpublish)**:
```php
// Same lookup as Republish
$submission = $submitter->submissions()
    ->where('sid', $row['sgc_id'])
    ->first();

$existingSubmissionState = $submission->status;
```

#### 5. Data Extraction
**Location**: `DocumentController@parser` (lines 371-426)

**What Happens**:
Extracts data from Excel row into `Nodal` data object:

```php
$data = new Nodal();
$data->sgc_id = $row['sgc_id'];
$data->local_key = $row['local_key'];
$data->hgnc_id = $row['hgnc_id'];
$data->gene_symbol = $row['hgnc_symbol'];
$data->mondo_id = $row['disease_id'];
$data->disease_name = $row['disease_name'];
$data->hp_id = $row['moi_id'];
$data->moi_name = $row['moi_name'];
$data->report_date = Carbon::parse($row['date']); // Handles Excel date formats
$data->report_url = $row['public_report_url'];
$data->gencc_classification_id = $row['classification_id'];
$data->gencc_classification_name = $row['classification_name'];
$data->criteria_url = $row['assertion_criteria_url'];
$data->evidence_items = $this->process_pmids($row['pmids']); // Parses comma-separated PMIDs
$data->notes_display = $row['notes'];
$data->notes_private = "File {$document->file_name} Row {$rownum}";
```

**Version Management**:
```php
$check = $document->submitter->submissions()
    ->sid($row['local_key'])
    ->first();

if ($check === null) {
    // First submission with this local_key
    $data->version_display = "1.0";
    $data->version_internal = "1.0.0";
    $data->reason_codes = ["NEW_CURATION"];
} else {
    // Increment version
    $oldversion = explode('.', $check->submission_data->version->display);
    $newversion = (int) $oldversion[0] + 1;
    $data->version_display = "{$newversion}.0";
    $data->version_internal = "{$newversion}.0.0";
    $data->reason_codes = ["RECURATION"];
}
```

#### 6. JSON Template Rendering
**Location**: `DocumentController@parser` (lines 428-444)

**What Happens**:
```php
// Pass data to Blade template
$template = view('json.spreadsheet')->with('d', $data);
$rendered = $template->render();
$obj = json_decode($rendered);

if ($obj === null) {
    // Log error and skip row
    \Log::error("Failed to decode JSON for row {$rownum}");
    continue;
}
```

The template (`views/json/spreadsheet.blade.php`) generates a standardized JSON structure matching the GenCC data model.

#### 7. Submission Data Loading & Validation
**Location**: `Submission@load_from_json` (lines 545-670)

This is where the actual database lookups happen and data gets validated against reference tables.

**Process Flow**:

a. **Gene Lookup**:
```php
$gene = Gene::hgnc_id($obj->gene->id)->first();
$this->gene_id = $this->asserterrors($gene->id ?? null, 'gene_hgnc_id', 'Invalid HGNC ID');

// If not found, use placeholder
if ($this->gene_id === null) {
    $this->gene_id = Gene::symbol('-')->first()->id;
    // Error added to errors_bag
}
```

b. **Disease Lookup** (supports multiple ID types):
```php
// rosetta() method handles MONDO, OMIM, and ORPHA IDs
$disease = Disease::rosetta($obj->disease->id);
$this->disease_id = $this->asserterrors($disease->id ?? null, 'disease_curie_id', 'Invalid Disease ID');

// If not found, use placeholder
if ($this->disease_id === null) {
    $this->disease_id = Disease::curie('MONDO:0000001')->first()->id;
}
```

c. **Mode of Inheritance Lookup**:
```php
$moi = Inheritance::curie($obj->moi->id)->first();
$this->inheritance_id = $this->asserterrors($moi->id ?? null, 'moi_curie_id', 'Invalid MOI ID');

// If not found, use placeholder
if ($this->inheritance_id === null) {
    $this->inheritance_id = Inheritance::curie('HP:0000005')->first()->id;
}
```

d. **Classification Lookup**:
```php
$classification = Classification::curie($obj->classification->id)->first();
$this->classification_id = $this->asserterrors($classification->id ?? null, 'classification_curie_id', 'Invalid Classification ID');
// classification_id can remain null if invalid - file validation prevents invalid data from being imported
```

e. **Mechanism Lookup** (optional):
```php
$mechanism = Mechanism::curie($obj->mechanism->id)->first();
// Optional field - no placeholder
```

f. **Report Date Validation**:
```php
$this->report_date = $this->asserterrors($obj->report->display_date ?? null, 'report_date', 'Missing Report Date');

if ($this->report_date !== null) {
    try {
        $this->report_date = Carbon::parse($this->report_date);
    } catch (Exception $e) {
        $this->report_date = null;
        $this->asserterrors(null, 'report_date', 'Invalid Report Date');
    }
}
```

g. **JSON Storage**:
```php
$this->original_submission_data = $obj; // Original from template
$this->submission_data = $obj; // Working copy
```

**Return Value**:
- If `errors_bag` is empty: Returns `true`
- If `errors_bag` has entries: Returns `errors_bag` array

#### 8. State Transition & Database Save
**Location**: `DocumentController@parser` (lines 449-517)

**For Action U (Unpublish)**:
```php
// Transition to draft_unpublish BEFORE changing job
// This preserves origin_job_id
$submission = SubmissionStateMachine::transition(
    $submission,
    Submission::STATUS_DRAFT_UNPUBLISH,
    $existingSubmissionState
);

// Associate with current draft job
$submission->user_id = $document->user_id;
$submission->job_id = $job->id;
$submission->save();

$successfulSubmissions++;
```

**For Action R (Republish)**:
```php
$status = $submission->load_from_json($obj);

if ($status === true) {
    // Transition to draft_republish BEFORE saving
    $submission = SubmissionStateMachine::transition(
        $submission,
        Submission::STATUS_DRAFT_REPUBLISH,
        $existingSubmissionState
    );

    $submission->user_id = $document->user_id;
    $submission->job_id = $job->id;
    $submission->save();

    $successfulSubmissions++;
} else {
    // Validation errors occurred
    $submission->submission_errors = $status;
    $submission->status = Submission::STATUS_ERRORS;
    $submission->user_id = $document->user_id;
    $job->submissions()->save($submission);
    $job->update(['status' => Job::STATUS_ERRORS]);

    $erroredSubmissions[] = [
        'row' => $rownum,
        'submission_id' => $data->local_key,
        'sgc_id' => $data->sgc_id,
        'errors' => $status
    ];
}
```

**For Action N (New)**:
```php
$status = $submission->load_from_json($obj);

if ($status === true) {
    // No state transition needed - defaults to draft_new
    $submission->user_id = $document->user_id;
    $submission->job_id = $job->id;
    $submission->save();
    // Auto-generates SGC_ID on save (format: SGC-1XXXXX)

    $successfulSubmissions++;
} else {
    // Same error handling as Republish
}
```

**State Machine Logic**:
The `SubmissionStateMachine::transition()` method:
- Validates the state transition is allowed
- Preserves `origin_job_id` when moving to draft states
- Updates `status` field
- Records state change in submission history

#### 9. PubMed Association
**Location**: `DocumentController@parser` (lines 520-534)

**What Happens**:
```php
// Clear existing associations
$submission->pubmeds()->detach();

// Re-attach based on current evidence
foreach ($submission->submission_data->evidence as $evidence) {
    if (empty($evidence->pmid)) continue;

    $pubmed = Pubmed::where('pmid', $evidence->pmid)->first();

    if ($pubmed === null) continue; // Will be added in batch later

    // Create pivot table entry
    $submission->pubmeds()->attach($pubmed->id);
}
```

**Note**: This only attaches PMIDs that already exist in the `pubmeds` table. New PMIDs are handled in the next phase.

#### 10. Progress Updates
**Location**: `DocumentController@parser` (lines 536-545)

**What Happens**:
```php
// Update every 5 rows OR on the last row
if ($processedRows % 5 == 0 || $processedRows == ($totalRows - 6)) {
    SpreadsheetUpdate::dispatch([
        'ident' => $document->job->ident,
        'size' => $processedRows,
        'total' => max(1, $totalRows - 6),
        'status' => 'progress'
    ]);
}
```

**Frontend Updates**:
- Progress bar updates to show percentage
- Status message: "Processing submission X of Y"
- Calculated: `Math.round((processedRows / totalRows) * 100)`

---

### Phase 4: Post-Processing

#### 11. Batch PMID Processing
**Location**: `DocumentController@parser` (lines 548-592)

**Critical**: This happens **AFTER all submissions are processed**, not during individual submission processing.

**Process Flow**:

a. **Collect All PMIDs**:
```php
$allPmids = [];
foreach ($job->submissions as $submission) {
    if ($submission->submission_data && isset($submission->submission_data->evidence)) {
        foreach ($submission->submission_data->evidence as $evidence) {
            if (!empty($evidence->pmid) && is_numeric($evidence->pmid)) {
                $allPmids[$evidence->pmid] = true; // Associative array for uniqueness
            }
        }
    }
}
$uniquePmids = array_keys($allPmids);
```

b. **Check Existing PMIDs** (Single Query):
```php
$existingPmids = Pubmed::whereIn('pmid', $uniquePmids)
    ->pluck('pmid')
    ->toArray();

$missingPmids = array_diff($uniquePmids, $existingPmids);
```

c. **Bulk Insert Missing PMIDs**:
```php
if (count($missingPmids) > 0) {
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
    Pubmed::insert($insertData); // Single bulk INSERT
}
```

**Important Notes**:
- PMIDs are added with `status = STATUS_INITIALIZING`
- **Does NOT fetch from NCBI** - that happens later via `php artisan pubmed:refresh` command
- Single bulk INSERT is much faster than multiple `firstOrCreate()` calls
- Previous implementation took 15+ minutes for 21,125 PMIDs
- Current implementation takes seconds

#### 12. Completion
**Location**: `DocumentController@parser` (lines 594-603)

**What Happens**:
```php
// Broadcast completion event
SpreadsheetUpdate::dispatch([
    'ident' => $document->job->ident,
    'size' => $processedRows,
    'total' => max(1, $totalRows - 6),
    'status' => 'done'
]);

return true;
```

**Frontend Response**:
- Sets progress to 100%
- Shows "Loading Submissions..." message
- Triggers page reload: `router.reload()`
- New submissions appear in the job's submission list

---

## Database Changes Summary

### Per Submission:

**Tables Modified**:

1. **submissions**
   - INSERT (new submission) or UPDATE (republish/unpublish)
   - Fields populated: `gene_id`, `disease_id`, `inheritance_id`, `classification_id`, `mechanism_id`, `report_date`, `report_url`, `submission_data` (JSON), `original_submission_data` (JSON), `submission_errors` (JSON), `status`, `user_id`, `job_id`, `submitter_id`, `origin_job_id`, `sid` (auto-generated for new)

2. **pubmed_submission** (pivot table)
   - DELETE existing associations
   - INSERT new associations for each PMID in evidence

### After All Submissions:

3. **pubmeds**
   - Bulk INSERT for new PMIDs (status = STATUS_INITIALIZING)

4. **jobs**
   - UPDATE submission count
   - UPDATE status if errors occurred

5. **documents**
   - UPDATE status to STATUS_STORED_PROCESSED

---

## Performance Optimizations

### Batch Operations:
1. **SGC_ID Validation**: Single query for all SGC_IDs (not N queries)
2. **PMID Processing**: Bulk INSERT (not N `firstOrCreate()` calls)
3. **Progress Updates**: Every 5 rows (not every row)

### Caching:
- Lookup tables (genes, diseases, inheritances, classifications) can use cache
- Reduces database queries during `load_from_json()`

### WebSocket Updates:
- Real-time UI updates without polling
- Minimal payload (just status and count)
- Throttled to every 5 rows

---

## Error Handling

### Validation Errors (Phase 2):
- **Effect**: Stops all processing
- **Storage**: `document.processing_errors` JSON field
- **User Impact**: No submissions created, must fix errors and re-upload

### Submission Errors (Phase 3):
- **Effect**: Partial success (other submissions continue)
- **Storage**: `submission.submission_errors` JSON field
- **User Impact**: Some submissions succeed, failed ones marked with errors
- **Invalid Lookups**: Use placeholder values + record error in errors_bag

### Error Categories:
1. **Structural**: Missing columns, invalid format
2. **Reference**: Invalid HGNC ID, Disease ID, MOI ID, Classification ID
3. **State**: Invalid state transition, SGC_ID not found, already in use
4. **Data**: Invalid date format, missing required fields

---

## Two-Phase Upload (New Feature)

### Phase 1: Validate Only
```javascript
// Frontend sends with validate_only parameter
axios.post('/api/documents/' + jobId, formData, {
    validate_only: true
})
```

**Backend Response**:
```json
{
    "success": "true",
    "status_code": 200,
    "message": "Validation Succeeded",
    "results": {
        "validated": true,
        "row_count": 2885
    },
    "document_id": 7,
    "validate_only": true
}
```

**Use Case**: Calculate processing time estimate before starting

### Phase 2: Process Validated Document
```javascript
// If user confirms, process the validated document
axios.post('/api/documents/' + documentId + '/process')
```

**Backend Response**: Same as regular upload

**Workflow**:
1. Upload with `validate_only=true`
2. Calculate estimated time: `rowCount * 1 second = X minutes`
3. If > 5 minutes: Show confirmation dialog
4. If confirmed: POST to `/process` endpoint
5. Show progress as normal

---

## WebSocket Event Flow

### Events Broadcasted:

1. **'begin'**: Upload started
2. **'parse'**: File parsed, row count available
3. **'validation_errors'**: Validation failed
4. **'validation_complete'**: Validation passed (validate_only mode)
5. **'progress'**: Every 5 rows processed
6. **'done'**: All submissions processed

### Frontend Handlers:

```javascript
// In JobItem.vue setupEchoListener()
echoChannel.listen('SpreadsheetUpdate', (e) => {
    if (e.message.status === 'parse') {
        totalRows.value = e.message.size;
        // Show "Parsing spreadsheet..."
    }

    if (e.message.status === 'progress') {
        processedRows.value = e.message.size;
        totalRows.value = e.message.total;
        progress.value = Math.round((e.message.size / e.message.total) * 100);
    }

    if (e.message.status === 'done') {
        progress.value = 100;
        // Reload page
    }

    if (e.message.status === 'validation_errors') {
        // Fetch and display errors
    }
});
```

---

## Code References

### Backend:
- **Upload Controller**: `app/Http/Controllers/API/DocumentController.php`
  - `store()` method (lines 53-157)
  - `parser()` method (lines 209-604)
  - `process()` method (lines 163-201) - New endpoint
- **Validation Service**: `app/Services/SubmissionFileValidation.php`
  - `validate_spreadsheet()` method
  - `validate_sgc_ids_batch()` method (lines 1085-1209)
- **Submission Model**: `app/Models/Submission.php`
  - `load_from_json()` method (lines 545-670)
- **State Machine**: `app/Services/SubmissionStateMachine.php`
  - `transition()` method

### Frontend:
- **Upload Component**: `resources/js/Components/JobItem.vue`
  - `fileUpload()` method
  - `setupEchoListener()` method
  - Upload dialog UI (lines 735-782)

### Routes:
- **API Routes**: `routes/api.php`
  - `POST /api/documents/{id}` - Upload & validate
  - `POST /api/documents/{id}/process` - Process validated document
  - `GET /api/documents/{id}/errors` - Fetch validation errors

---

## Timing Breakdown (Example: 2,885 Row File)

| Phase | Duration | Notes |
|-------|----------|-------|
| File Upload | ~5 sec | Network + file save |
| Parsing | ~2 sec | Excel file reading |
| Validation | ~10 sec | Batch queries, format checks |
| Processing (2,885 rows) | ~48 min | ~1 sec/row |
| PMID Batch Insert | ~5 sec | Bulk operation |
| **Total** | **~49 min** | For 2,885 submissions |

**Factors Affecting Speed**:
- Row count (primary factor)
- Database performance
- Number of unique PMIDs
- Server resources
- Network latency (WebSocket updates)

---

## Future Optimizations

1. **Queue-based Processing**: Move long-running parser to background queue
2. **Chunked Processing**: Process in batches with progress saves
3. **Parallel Processing**: Multiple workers processing different chunks
4. **Redis Caching**: Cache lookup tables in Redis
5. **Database Indexing**: Ensure optimal indexes on frequently queried fields
6. **Async WebSocket**: Non-blocking event broadcasts

---

*Last Updated: November 17, 2025*
*Version: 1.0*
