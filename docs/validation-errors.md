# Spreadsheet Validation Errors Documentation

## Overview

This document provides a comprehensive reference for all validation errors that can occur during spreadsheet upload in the GenCC Submission Portal. The validation system now uses intelligent error grouping and short-circuit validation to provide clear, actionable feedback.

## Table of Contents

1. [Error Types and Messages](#error-types-and-messages)
2. [Validation Order of Precedence](#validation-order-of-precedence)
3. [Error Severity Levels](#error-severity-levels)
4. [Grouped Error Display](#grouped-error-display)
5. [Example Scenarios](#example-scenarios)

---

## Error Types and Messages

### 1. Spreadsheet Structure Errors (FATAL)

#### `minimum_rows_requirement`
**Severity:** FATAL
**Message:** "The spreadsheet contains [X] rows, but there must be a minimum of 13. This a fatal error that must be fixed and the file re-uploaded."

**Cause:** Spreadsheet has fewer than 13 rows (12 header/example rows + at least 1 data row)

---

#### `no_header_row_found`
**Severity:** FATAL
**Message:** "Could not find header row in row 6 of the spreadsheet. Headers must be in row 6. This a fatal error that must be fixed and the file re-uploaded."

**Cause:** Row 6 does not contain the expected column headers

---

#### `invalid_header_columns`
**Severity:** FATAL
**Message:** Detailed message about missing or incorrect headers

**Cause:** One or more required column headers are missing or misspelled

---

### 2. Required Field Errors (ERROR)

#### `missing_required_field`
**Severity:** ERROR
**Possible Messages:**
- "Required field 'sgc_id' is missing." (for Republish/Unpublish actions)
- "Required field 'hgnc_id' is missing."
- "Required field 'disease_id' is missing."
- "Required field 'moi_id' is missing."
- "Required field 'classification_id' is missing."
- "Required field 'date' is missing."
- "Required field 'public_report_url' is missing."
- "Required field 'assertion_criteria_url' is missing." (for Republish actions)

**Cause:** A required field contains an empty value

**Note:** Required fields vary by action type (New, Republish, Unpublish)

---

### 3. Field Format Errors (ERROR)

#### `invalid_field_format`
**Severity:** ERROR
**Message Template:** "Invalid format for column '[column_name]'. [Column-specific guidance]"

**Examples:**
- "Invalid format for column 'hgnc_id'. Must be a valid HGNC gene identifier (e.g., HGNC:1234). Verify at https://www.genenames.org/"
- "Invalid format for column 'disease_id'. Must be a valid disease identifier from MONDO, OMIM, or ORPHA (e.g., MONDO:0001234). Single ID only."
- "Invalid format for column 'moi_id'. Must be a valid HPO mode of inheritance term (e.g., HP:0000006 for Autosomal dominant)."
- "Invalid format for column 'public_report_url'. Must be a valid URL starting with http:// or https://"

**Cause:** Field value does not match expected format pattern (regex validation)

**Note:** Error messages no longer display the invalid value to improve error grouping. Instead, they provide helpful guidance on the expected format.

---

### 4. Field Value Validation Errors (ERROR)

#### `invalid_field_value`
**Severity:** ERROR
**Message Template:** "Invalid value for column '[column_name]'. [Column-specific guidance]"

**Examples:**
- "Invalid value for column 'hgnc_id'. Must be a valid HGNC gene identifier (e.g., HGNC:1234). Verify at https://www.genenames.org/"
- "Invalid value for column 'disease_id'. Must be a valid disease identifier from MONDO, OMIM, or ORPHA (e.g., MONDO:0001234). Single ID only."
- "Invalid value for column 'classification_id'. Must be a valid GenCC classification (e.g., GENCC:100001 for Definitive)."
- "Invalid value for column 'action'. Must be one of: N (New), R (Republish), or U (Unpublish)."

**Cause:** Field value has correct format but either:
- Not in allowed list of values (enum validation), OR
- Doesn't exist in database (database lookup validation)

**Note:** Error messages no longer display the invalid value to improve error grouping. Instead, they provide helpful guidance on valid values.

---

### 5. Action-Specific Errors (ERROR)

#### `new_with_sgc_id`
**Severity:** ERROR
**Message:** "Action 'N' (New) cannot have an SGC_ID. The SGC_ID column must be empty for new submissions."

**Cause:** New submission (Action = 'N') has a value in SGC_ID column

---

#### `action_missing_sgc_id`
**Severity:** ERROR
**Message Template:** "Action '[action]' ([actionName]) requires a valid SGC_ID in the first column."

**Examples:**
- "Action 'R' (Republish) requires a valid SGC_ID in the first column."
- "Action 'U' (Unpublish) requires a valid SGC_ID in the first column."

**Cause:** Republish or Unpublish action is missing SGC_ID value

---

#### `unpublish_has_data`
**Severity:** ERROR
**Message Template:** "Action 'U' (Unpublish) should only have SGC_ID and Action columns filled. The following fields must be empty: [field list]."

**Cause:** Unpublish action has data in columns other than SGC_ID and Action

---

### 6. SGC_ID Validation Errors (ERROR)

#### `invalid_sgc_id_format`
**Severity:** ERROR
**Message:** "Invalid SGC ID format. Must be 'SGC-' followed by exactly 6 digits with no leading zeros (e.g., SGC-100001)."

**Cause:** SGC_ID in Republish (R) or Unpublish (U) action doesn't match the required format

**Validation Order:** This check runs first, before checking if the SGC ID exists or belongs to the submitter

**Format Requirements:**
- Must start with `SGC-`
- Followed by exactly 6 digits
- First digit cannot be 0 (no leading zeros)
- Valid examples: `SGC-100001`, `SGC-999999`
- Invalid examples: `SGC-000001` (leading zero), `SGC-1234` (too short), `SGC-1234567` (too long)

**Note:** Generic message groups all format errors together regardless of the specific invalid value.

---

#### `duplicate_sgc_id`
**Severity:** ERROR
**Message:** "Duplicate SGC ID found in spreadsheet. Each SGC ID must appear only once."

**Cause:** The same SGC_ID appears multiple times in the spreadsheet

**Note:** This check ensures data integrity and prevents accidental duplicate submissions. Generic message allows all duplicate SGC ID errors to be grouped together.

---

#### `invalid_sgc_id`
**Severity:** ERROR
**Message:** "Invalid SGC ID: This submission does not belong to your organization or does not exist."

**Cause:** SGC_ID doesn't exist in the system or doesn't belong to the submitter's organization

**Note:** This is a batch validation check performed on all SGC IDs at once for efficiency. Generic message groups all invalid SGC IDs together.

---

#### `sgc_id_not_found`
**Severity:** ERROR
**Message:** "SGC ID not found. Only previously processed submissions can be republished or unpublished."

**Cause:** SGC_ID doesn't exist in the system (legacy check, mostly replaced by `invalid_sgc_id`)

**Note:** Generic message groups all not-found SGC IDs together.

---

#### `sgc_id_in_draft_or_submitted_job`
**Severity:** ERROR
**Message:** "SGC ID is already in another draft or submitted job. Complete or cancel that job first."

**Cause:** SGC_ID is currently being processed in another job

**Note:** Generic message groups all in-progress SGC IDs together.

---

#### `republish_invalid_state`
**Severity:** ERROR
**Message:** "Action 'R' (Republish) requires SGC ID to be in 'Published' or 'Unpublished' state. Check the current state of the submission."

**Cause:** Attempting to republish a submission that's not in a valid state

**Note:** Generic message groups all republish state errors together, regardless of current state.

---

#### `unpublish_invalid_state`
**Severity:** ERROR
**Message:** "Action 'U' (Unpublish) requires SGC ID to be in 'Published' state. Check the current state of the submission."

**Cause:** Attempting to unpublish a submission that's not published

**Note:** Generic message groups all unpublish state errors together, regardless of current state.

---

### 7. PMID Validation Errors (ERROR)

#### `invalid_pmid_format`
**Severity:** ERROR
**Message:** "Invalid PMID format found: Must be numeric with no leading zeros."

**Cause:** PMID values don't meet format requirements (must be numeric, any length from 1+ digits, no leading zeros on multi-digit numbers)

---

#### `invalid_pmids`
**Severity:** ERROR
**Message Template:** "The following PubMed IDs could not be found in PubMed: [pmid list]. Please verify these PMIDs are correct."

**Cause:** PMID values have correct format but don't exist in PubMed database

---

### 8. Data Uniqueness Errors (ERROR)

#### `unique_column_requirement`
**Severity:** ERROR
**Message Template:** "Unique column requirement on column '[column_name]' not met."

**Example:**
- "Unique column requirement on column 'local_key' not met."

**Cause:** Duplicate values found in a column that requires unique values

---

## Validation Order of Precedence

To prevent redundant error messages, validation checks are performed in order with short-circuit logic. Once a validation check fails for a field, subsequent checks for that field are skipped.

### Order of Execution (General Fields)

1. **Empty Value Check**
   - If field is empty, skip all format/enum/database validation
   - Required field validation is handled separately

2. **Regex Format Check** (if applicable)
   - Validates field matches expected format pattern
   - Example: `HGNC:####` for gene IDs

3. **Enum Value Check** (if format passed and applicable)
   - Validates field value is in allowed list
   - Example: Action must be 'N', 'R', or 'U'

4. **Database Lookup Check** (if format/enum passed and applicable)
   - Validates field value exists in database
   - Example: HGNC ID must exist in genes table

### Order of Execution (SGC_ID Validation for R/U Actions)

For Republish (R) and Unpublish (U) actions, SGC_ID validation follows this specific order:

1. **Empty Check**
   - Error: `action_missing_sgc_id` if empty

2. **Format Check**
   - Error: `invalid_sgc_id_format` if not matching `SGC-[1-9]\d{5}` pattern
   - Stops further validation if format is invalid

3. **Database Existence & Ownership Check**
   - Error: `sgc_id_not_found` if SGC_ID doesn't exist
   - Error: `invalid_sgc_id` if exists but doesn't belong to submitter (batch check)
   - Error: `sgc_id_in_draft_or_submitted_job` if already in another active job

4. **State Validation Check**
   - Error: `republish_invalid_state` if trying to republish non-published/unpublished submission
   - Error: `unpublish_invalid_state` if trying to unpublish non-published submission

5. **Duplicate Check** (across entire spreadsheet)
   - Error: `duplicate_sgc_id` if same SGC_ID appears multiple times in spreadsheet

### Implementation

```php
// Skip validation for empty values
if (empty(trim($value))) {
    continue;
}

$field_validation_failed = false;

// Step 1: Regex format check
if (!$field_validation_failed && has_regex_check($column)) {
    if (!matches_regex($value)) {
        add_error("Invalid format...");
        $field_validation_failed = true;  // Stop further validation
    }
}

// Step 2: Enum value check (only if format passed)
if (!$field_validation_failed && has_enum_check($column)) {
    if (!in_allowed_values($value)) {
        add_error("Invalid value... Must be one of...");
        $field_validation_failed = true;  // Stop further validation
    }
}

// Step 3: Database lookup check (only if enum passed)
if (!$field_validation_failed && has_database_check($column)) {
    if (!exists_in_database($value)) {
        add_error("Invalid value... [not found]");
    }
}
```

---

## Error Severity Levels

### FATAL
**Behavior:** Stops all processing; file must be fixed and re-uploaded

**Error Types:**
- `minimum_rows_requirement`
- `no_header_row_found`
- `invalid_header_columns`

**User Impact:** Cannot proceed until issue is resolved

---

### ERROR
**Behavior:** Validation error; processing may continue but affected rows will have issues

**Error Types:** All other error types

**User Impact:** File can be uploaded, but submissions with errors will be marked and may require correction

---

### WARNING
**Behavior:** Advisory message; doesn't prevent processing

**User Impact:** Informational only; no action required

---

## Grouped Error Display

Errors with the same message are automatically grouped together, with all affected row numbers listed. This significantly reduces clutter and makes it easier to identify patterns.

### Display Format

**Information Banner:**
At the top of the error display, users see:
```
ℹ️ Please review and correct the errors below. For detailed guidance on submission requirements,
refer to GenCC Submission Directions (opens in new tab).
```

**Error Table:**
| Error Type | Severity | Error Message | Rows |
|------------|----------|---------------|------|
| missing_required_field | error | Required field 'hgnc_id' is missing. | 13, 14, 25... (+8) |
| invalid_field_format | error | Invalid format for column 'disease_id'. Must be a valid disease identifier... | 16, 18 |

**Note:** The Rows column shows the first 3 row numbers. If more than 3 rows are affected, it displays an ellipsis followed by the count (e.g., "13, 14, 25... (+8)").

### CSV Export Format

```csv
Error Type,Severity,Message,Rows
"missing_required_field","error","Required field 'hgnc_id' is missing.","13, 14, 25, 27, 31, 45, 52, 63, 71, 88, 92"
"invalid_field_format","error","Invalid format for column 'disease_id'. Must be a valid disease identifier from MONDO, OMIM, or ORPHA (e.g., MONDO:0001234). Single ID only.","16, 18"
```

**Note:** The CSV export includes the full list of all affected rows, not just the first 3.

### Benefits

- **Reduced Clutter:** Shows 1 error instead of N identical errors
- **Pattern Recognition:** Easy to see which rows have the same issue
- **Easier Fixing:** Can fix all instances of the same error at once

---

## Example Scenarios

### Scenario 1: Format Error (Regex Validation)

**Input:** Row 13: `hgnc_id = "HGNC:ABC"` (letters instead of numbers)

**Validation Process:**
1. Empty check: Value present ✓
2. Format check: "HGNC:ABC" doesn't match `/^HGNC:\d+$/` ✗ **STOPS HERE**
3. Enum check: SKIPPED
4. Database lookup: SKIPPED

**Result:**
```
Error Type: invalid_field_format
Severity: error
Message: Invalid format for column 'hgnc_id'. Must be a valid HGNC gene identifier (e.g., HGNC:1234). Verify at https://www.genenames.org/
Rows: 13
```

---

### Scenario 2: Valid Format, Invalid Database Value

**Input:** Row 13: `hgnc_id = "HGNC:99999999"` (valid format, not in database)

**Validation Process:**
1. Empty check: Value present ✓
2. Format check: "HGNC:99999999" matches pattern ✓
3. Enum check: Not applicable (SKIPPED)
4. Database lookup: Not found in genes table ✗

**Result:**
```
Error Type: invalid_field_value
Severity: error
Message: Invalid value for column 'hgnc_id'. Must be a valid HGNC gene identifier (e.g., HGNC:1234). Verify at https://www.genenames.org/
Rows: 13
```

---

### Scenario 3: Enum Validation

**Input:** Rows 13-15: `action = "X"` (invalid action code)

**Validation Process:**
1. Empty check: Value present ✓
2. Format check: Not applicable or passed ✓
3. Enum check: "X" not in ['N', 'R', 'U'] ✗ **STOPS HERE**
4. Database lookup: SKIPPED

**Result:**
```
Error Type: invalid_field_value
Severity: error
Message: Invalid value for column 'action'. Must be one of: N (New), R (Republish), or U (Unpublish).
Rows: 13, 14, 15
```

---

### Scenario 4: Empty Optional Field

**Input:** Row 13: `notes = ""` (empty optional field)

**Validation Process:**
1. Empty check: Value empty → SKIP ALL VALIDATION

**Result:** No error (empty optional fields are valid)

---

### Scenario 5: Empty Required Field

**Input:** Row 13: `hgnc_id = ""` (empty required field)

**Validation Process:**
1. Format/enum/database validation: SKIPPED (handled separately)
2. Required field validation: Empty value for required field ✗

**Result:**
```
Error Type: missing_required_field
Message: Required field 'hgnc_id' is missing. (Rows: 13)
```

---

### Scenario 6: Multiple Issues, Same Field

**Input:** Rows 13-20: `hgnc_id = "ABC"` (missing prefix, wrong format)

**Old Behavior (Without Short-Circuit & Generic Messages):**
```
Invalid format for column 'hgnc_id': 'ABC'. (Rows: 13, 14, 15, 16, 17, 18, 19, 20)
Invalid value for column 'hgnc_id': 'ABC'. Invalid Gene ID. (Rows: 13, 14, 15, 16, 17, 18, 19, 20)
```

**New Behavior (With Short-Circuit & Generic Messages):**
```
Invalid format for column 'hgnc_id'. Must be a valid HGNC gene identifier (e.g., HGNC:1234). Verify at https://www.genenames.org/ (Rows: 13, 14, 15, 16, 17, 18, 19, 20)
```

**Benefits:**
- Only shows the most relevant error (format), avoiding redundant database lookup error
- Generic message groups all format errors together regardless of the specific invalid value
- Provides helpful guidance on what the correct format should be

---

### Scenario 7: Batch SGC ID Validation

**Input:** Spreadsheet with SGC IDs from different organizations mixed together

**Validation Process:**
1. Collect all SGC IDs: ["SGC-100001", "SGC-100002", "SGC-200001", "SGC-200002"]
2. Single database query: Check which belong to submitter's organization
3. Valid for this submitter: ["SGC-100001", "SGC-100002"]
4. Invalid for this submitter: ["SGC-200001", "SGC-200002"]

**Result:**
```
Error Type: invalid_sgc_id
Severity: error
Message: Invalid SGC ID 'SGC-200001': This submission does not belong to your organization or does not exist.
Rows: 13, 15

Error Type: invalid_sgc_id
Severity: error
Message: Invalid SGC ID 'SGC-200002': This submission does not belong to your organization or does not exist.
Rows: 14, 16
```

**Benefits:**
- Single database query validates all SGC IDs at once (efficient batch processing)
- Prevents users from accidentally modifying submissions from other organizations
- Clear error message explains why the SGC ID is invalid

---

## Best Practices for Users

### 1. Fix Structural Errors First
- FATAL errors must be resolved before any data processing
- Check row count, headers, and file structure

### 2. Address Format Errors Next
- Format errors often cause multiple downstream errors
- Fix format issues to reveal actual validation problems

### 3. Use Grouped Error Display
- Look for patterns in row numbers
- Fix all instances of the same error together

### 4. Download Error Report
- Use CSV export for easy reference
- Share with team members for collaborative fixing

### 5. Validate in Stages
- Fix one error type at a time
- Re-upload to see remaining issues

---

## Technical Implementation

**File:** `app/Services/SubmissionFileValidation.php`

**Key Methods:**
- `validate_spreadsheet()` - Main validation entry point
- `validate_data_row()` - Field-level validation with short-circuit logic
- `validate_action_rules()` - Action-specific business rules
- `validate_pmid_format()` - Fast PMID format validation
- `validate_and_cache_pmids()` - Full PMID validation with PubMed lookup
- `validate_duplicate_sgc_ids()` - Checks for duplicate SGC IDs within spreadsheet
- `validate_sgc_ids_batch()` - Batch validation of SGC IDs against submitter
- `get_column_guidance()` - Provides column-specific help text
- `group_validation_errors()` - Groups errors by message with row lists

**Frontend Display:** `resources/js/Components/JobItem.vue`
- Displays errors in a DataTable with separate columns for Error Type, Severity, Message, and Rows
- Shows information banner with link to submission directions
- Formats row display to show first 3 + count, full list in CSV export

---

## Validation Error Limit

**Maximum:** No limit (disabled)

The validation system will collect and display **all** validation errors found in the spreadsheet, regardless of how many errors exist. This ensures users see the complete picture of what needs to be fixed.

**Configuration:**
- Backend: `MAX_VALIDATION_RESULTS = 0` in [SubmissionFileValidation.php:29](app/Services/SubmissionFileValidation.php#L29)
- Frontend: `MAX_VALIDATION_RESULTS = 0` in [JobItem.vue:72](resources/js/Components/JobItem.vue#L72)
- Setting to 0 disables the limit
- Setting to any positive integer (e.g., 25) would limit validation to that many errors

**Note:** Even with no limit, the error grouping feature ensures the display remains manageable by combining identical errors across multiple rows.

---

## Validation Progress Messages

During the validation process, users receive real-time progress updates in the upload progress dialog. These messages provide visibility into which validation checks are currently running.

**Progress Messages:**
1. **Validating X rows...** - Initial validation start message
2. **Checking spreadsheet structure...** - Verifying row count and basic structure
3. **Validating column headers...** - Checking that all required headers are present
4. **Validating data rows...** - Performing field-level validation on all data rows
5. **Checking for duplicate SGC IDs...** - Looking for duplicate SGC IDs within the spreadsheet
6. **Validating SGC IDs against database...** - Batch checking SGC IDs belong to submitter
7. **Validating PMID format...** - Checking PMID format (numeric, no leading zeros)
8. **Finalizing validation results...** - Grouping errors and preparing final report

**Implementation:**
- Backend broadcasts progress via `SpreadsheetUpdate` event with `validation_progress` status
- Frontend displays messages in the existing upload progress dialog
- Shows indeterminate progress bar during validation
- If validation errors are found, dialog shows "Preparing error report..." while fetching full error details
- Dialog closes only after error report is displayed or validation succeeds
- After successful validation, dialog transitions to showing submission processing progress

**User Flow:**
1. Upload spreadsheet → Dialog appears showing "Validating File"
2. Dialog updates with progress messages as validation proceeds
3. **If errors found:**
   - Dialog shows "Found X validation error(s). Preparing error report..."
   - Error details are fetched from server
   - Dialog closes and error card displays with all errors
4. **If no errors:**
   - Dialog transitions to "Processing submission 1 of X"
   - Shows percentage-based progress bar

**Benefits:**
- Uses existing upload dialog UI (no separate modal or toast spam)
- Dialog stays visible throughout entire validation process
- Users always see what's happening (never a mysterious pause)
- Clear messaging when errors are found and being processed
- Seamless transition from validation → error display OR validation → processing
- Especially helpful for large spreadsheets where validation takes time

---

## Two-Phase Upload with Validation-First Mode

The system now supports a two-phase upload process that allows validation to complete before processing begins. This is particularly useful for large uploads where processing may take a long time.

### How It Works

**Phase 1: Validation Only**
- Backend accepts `validate_only=true` parameter on upload
- Performs complete validation (all checks described above)
- Returns validation results WITHOUT processing submissions
- Broadcasts `validation_complete` event with row count

**Phase 2: Process After Confirmation** (Optional)
- If validation passes and processing time > 5 minutes estimated
- User is shown confirmation dialog with estimated time
- If user confirms, POST to `/api/documents/{id}/process` endpoint
- Processing begins with progress tracking

### Benefits

- **Time Estimation**: Calculate `row_count * 1 second` for accurate time estimate
- **User Control**: Users can cancel long uploads after seeing estimate
- **No Wasted Time**: Validation errors found before committing to long processing
- **Same Progress Tracking**: Once processing starts, progress updates work identically

### API Endpoints

**Validate Only:**
```
POST /api/documents/{job_id}
Content-Type: multipart/form-data

file: [Excel file]
validate_only: true
```

**Response:**
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

**Process Validated Document:**
```
POST /api/documents/{document_id}/process
```

**Response:**
```json
{
    "success": "true",
    "status_code": 200,
    "message": "Processing Succeeded",
    "results": true,
    "document_id": 7
}
```

### WebSocket Events

- `validation_complete` - Emitted when validate_only mode completes successfully
- Contains `row_count` for time estimation
- Frontend can show confirmation dialog if needed

---

## Interactive Error Display Features

### Row List Expansion

The error display now includes interactive features for managing long row lists:

**Default View:**
- Shows first 3 rows: "13, 14, 15... (+47 more)"
- Keeps UI compact for easy scanning

**Expanded View:**
- Click the "(+47 more)" link to expand
- Shows all 50 rows: "13, 14, 15, 16, 17, 18, ..."
- Link changes to "show less"
- Click "show less" to collapse back to first 3

**CSV Download:**
- Always includes complete list of all rows
- No truncation in downloaded file
- Format: "13, 14, 15, 16, 17, 18, ..." (all rows)

**Implementation:**
- `formatRowsForDisplay()` function in JobItem.vue
- Uses reactive state to track expanded/collapsed per error
- Toggles between truncated and full view

### Auto-Clear on New Upload

**Behavior:**
- When starting a new upload, any previous error card is automatically cleared
- Prevents confusion between errors from different uploads
- Ensures clean slate for each upload attempt

**Implementation:**
```javascript
// In fileUpload() method
showErrorCard.value = false;
uploadErrors.value = [];
expandedErrorRows.value = {};
```

---

## Change Log

### Version 2.2 (Current)
- **Two-Phase Upload:** Added validate_only mode for validation-first workflow
- **Time Estimation:** Backend returns row count for accurate processing time calculation
- **Row Expansion:** Interactive expand/collapse for long row lists in UI
- **Auto-Clear:** Error card automatically clears when starting new upload
- **Process Endpoint:** New `/api/documents/{id}/process` endpoint for validated documents

### Version 2.1
- **Generic Error Messages:** Removed specific invalid values from error messages to improve grouping
- **Column Guidance:** Added helpful guidance text for each column type
- **Batch SGC ID Validation:** Validates all SGC IDs against submitter in single database query
- **Separate Rows Column:** Rows displayed in dedicated column with smart truncation (first 3 + count)
- **Information Banner:** Added help message with link to submission directions at top of error display
- **Duplicate SGC ID Check:** Added validation to prevent duplicate SGC IDs within a spreadsheet
- **Unlimited Error Collection:** Removed 25-error limit to show all validation errors
- **Progress Messages:** Real-time progress updates in upload dialog show validation progress through each stage
- **SGC ID Format Validation:** Added format check for R/U actions (must be SGC- + 6 digits, no leading zeros)
- **SGC ID Validation Order:** Format check runs before existence/ownership/duplicate checks

### Version 2.0
- Added validation order of precedence with short-circuit logic
- Implemented error grouping by message with row lists
- Added empty value handling to skip unnecessary validation
- Updated error report display and CSV export format
- Reduced redundant error messages

### Version 1.0 (Legacy)
- Basic validation with all checks running independently
- Individual error per row
- Potential for redundant error messages
