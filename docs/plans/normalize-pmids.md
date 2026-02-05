# Plan: PMID Normalization in gencc-sub

## Overview

Port the PMID normalization logic from gencc-search into gencc-sub. This involves:
1. A reusable service for normalizing PMIDs
2. A migration to clean up existing data
3. Integration into the file upload pipeline
4. UI changes so trivial PMID issues (formatting, separators) become warnings instead of blocking errors

## Current State

- **Upload validation** (`SubmissionFileValidation`): The `pmids` column regex is strict (`/^\s*(\d+(\s*[,;]\s*\d+)*)?\s*$/`), rejecting values like `12345[PMID]`, `12345_67890`, scientific notation, etc. These are reported as `severity: 'error'` which **blocks the entire upload**.
- **Processing** (`DocumentController::process_pmids()`): Uses a basic regex `/[\s,]*([0-9]+)[[a-zA-Z\[\]_,\.;\s]*/` to extract numbers, silently discarding everything else.
- **load_from_json()**: Validates individual PMIDs (numeric, no leading zeros) and sets `submission_errors['invalid_pmid']` on failure.
- **No `submitted_as_pmids` column** exists in gencc-sub's submissions table. PMIDs are stored in `submission_data->evidence` JSON and the `pubmed_submission` pivot table.

## Implementation Steps

### Step 1: Create PmidNormalizer Service

**File**: `app/Services/PmidNormalizer.php`

A static utility class with the normalization logic from the gencc-search migration:

```php
class PmidNormalizer
{
    /**
     * Normalize a raw PMID string into an array of valid PMIDs and an array of issues.
     *
     * @param string|null $raw  The raw PMID string (comma/semicolon/underscore/space separated)
     * @return array{pmids: string[], issues: array{value: string, reason: string}[]}
     */
    public static function normalize(?string $raw): array
```

Rules (matching gencc-search):
- Replace non-breaking spaces (U+00A0) with regular spaces
- Split on commas, semicolons, underscores, and whitespace
- Strip `[PMID]` suffix and `PMID:` prefix from entries
- Remove literal "NULL" strings → issue `literal_null`
- Skip empty values
- Detect scientific notation → issue `scientific_notation`
- Must be purely numeric (digits only) → issue `non_numeric`
- Remove zero values → issue `zero_value`
- Remove values with more than 8 digits (excluding leading zeros) → issue `exceeds_max_digits`
- Log warning for PMIDs with fewer than 7 digits (excluding leading zeros) → not an issue, just a log warning
- Sort in ascending numerical order
- Remove duplicates

### Step 2: Migration to Add Columns and Clean Existing Data

**File**: `database/migrations/2026_02_03_XXXXXX_normalize_pmids_on_submissions.php`

1. Add `normalized_pmids` (text, nullable) after evidence column
2. Add `pmid_issues` (json, nullable) after normalized_pmids
3. For each submission with evidence data:
   - Extract raw PMIDs from `submission_data->evidence`
   - Run through `PmidNormalizer::normalize()`
   - Store normalized result in `normalized_pmids`
   - Store issues in `pmid_issues`
   - Re-sync `pubmed_submission` pivot table
   - Clear `invalid_pmid` from `submission_errors` if normalization resolved all issues
4. Trim trailing whitespace from `submitted_as_hgnc_id` and `submitted_as_disease_id` fields in submission_data (matching gencc-search cleanup)

### Step 3: Update Upload Validation (SubmissionFileValidation)

**File**: `app/Services/SubmissionFileValidation.php`

1. Add `SEVERITY_WARNING = 'warning'` constant
2. **Relax the `pmids` column regexp** — remove it or make it very permissive (accept any non-empty string). The actual validation will be done by the normalizer.
3. **Replace `validate_pmid_format()`** with a new method that uses `PmidNormalizer::normalize()`:
   - If normalization produces valid PMIDs → no error
   - If normalization produces issues → report as `severity: 'warning'` with details of what was cleaned/removed
   - If normalization produces ZERO valid PMIDs from non-empty input → report as `severity: 'error'`
4. **Update `validate_pmid_duplicates()`** to use the normalizer for parsing

### Step 4: Update validateFile() to Support Warnings

**File**: `app/Http/Controllers/API/DocumentController.php`

Currently `validateFile()` treats any validation result as blocking (`has_errors: true`). Change to:
- `has_errors: true` only for results with `severity: 'fatal'` or `severity: 'error'`
- Pass warnings through in the response so they can be displayed but don't block upload
- Return `warnings` array separately from `errors`

### Step 5: Update DocumentController::process_pmids()

**File**: `app/Http/Controllers/API/DocumentController.php`

Replace the existing `process_pmids()` method to use `PmidNormalizer::normalize()`. Return only the valid PMIDs array (issues are already captured during validation).

### Step 6: Update load_from_json() PMID Processing

**File**: `app/Models/Submission.php`

Update the evidence processing section (lines 918-979) to:
1. Use `PmidNormalizer::normalize()` for each PMID value
2. Store issues in the new `pmid_issues` column
3. Store the normalized comma-separated list in `normalized_pmids`
4. Only set `submission_errors['invalid_pmid']` for genuinely invalid PMIDs (not formatting issues that were auto-corrected)

### Step 7: Update SubmissionController Evidence Updates

**File**: `app/Http/Controllers/API/SubmissionController.php`

Update the `case 'evidence'` handler to use `PmidNormalizer::normalize()` and update `normalized_pmids`/`pmid_issues` columns.

### Step 8: Update Frontend - JobItem.vue

**File**: `resources/js/Components/JobItem.vue`

1. Handle the new `warnings` array from validation response
2. Display warnings in an orange/yellow card (distinct from the red error card)
3. Warnings should be visible but not block the "Submit" action

### Step 9: Update Frontend - SubmissionItem.vue

**File**: `resources/js/Components/SubmissionItem.vue`

1. Display `pmid_issues` from the submission if present
2. Show as orange/warning indicators rather than red/error
3. Keep the red error indicator only for truly invalid PMIDs that couldn't be normalized

### Step 10: Update GenccRelease Export

**File**: `app/Console/Commands/GenccRelease.php`

Use `normalized_pmids` column (if populated) instead of re-extracting from pivot table for the `submitted_as_pmids` CSV column.

## Files Changed Summary

| File | Change |
|------|--------|
| `app/Services/PmidNormalizer.php` | **NEW** - Normalization service |
| `database/migrations/2026_02_03_*` | **NEW** - Add columns, clean data |
| `app/Services/SubmissionFileValidation.php` | Relax PMID regexp, use normalizer, add warnings |
| `app/Http/Controllers/API/DocumentController.php` | Support warnings in validateFile, update process_pmids |
| `app/Models/Submission.php` | Update load_from_json PMID processing |
| `app/Http/Controllers/API/SubmissionController.php` | Update evidence update handler |
| `resources/js/Components/JobItem.vue` | Display warnings, don't block on warnings |
| `resources/js/Components/SubmissionItem.vue` | Display PMID issues as warnings |
| `app/Console/Commands/GenccRelease.php` | Use normalized_pmids in export |
| `tests/Unit/PmidNormalizerTest.php` | **NEW** - Unit tests for normalizer |
| `tests/Feature/SubmissionFileValidationPmidTest.php` | **NEW** - Tests for PMID validation during upload |

## Unit Tests

### Step 11: PmidNormalizer Unit Tests

**File**: `tests/Unit/PmidNormalizerTest.php`

Comprehensive tests for the normalizer service:
- Empty/null input returns empty arrays
- Simple comma-separated PMIDs are parsed correctly
- Semicolon, underscore, whitespace, and mixed separators work
- `PMID:` prefix is stripped
- `[PMID]` suffix is stripped
- Literal "NULL" values are flagged as `literal_null`
- Scientific notation (e.g. `1.5845E+15`) is flagged as `scientific_notation`
- Non-numeric values are flagged as `non_numeric`
- Zero values are flagged as `zero_value`
- Values exceeding 8 digits are flagged as `exceeds_max_digits`
- Leading zeros are preserved in output (they are still numeric)
- Non-breaking spaces are handled
- Results are sorted in ascending numerical order
- Duplicates are removed
- Mixed valid and invalid input returns correct split
- All-invalid input returns empty pmids array with issues

### Step 12: Submission File Validation PMID Tests

**File**: `tests/Feature/SubmissionFileValidationPmidTest.php`

Tests for the updated validation behavior:
- Clean PMIDs produce no errors or warnings
- Normalizable PMIDs (with [PMID] suffix, mixed separators) produce warnings, not errors
- Completely invalid PMIDs (all non-numeric) produce errors
- Duplicate PMIDs within a row produce warnings (after normalization)
- Warnings don't set `has_errors: true` in validation response
