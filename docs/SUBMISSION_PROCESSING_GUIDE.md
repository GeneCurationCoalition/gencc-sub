# GenCC Submission Processing Guide

**Version**: 2.0 (String-Based States)
**Last Updated**: November 13, 2025

This guide explains how submissions are processed in the GenCC Submission Portal, covering spreadsheet uploads, UI-based interactions, and the complete submission lifecycle using the V2 state machine.

---

## Table of Contents

1. [Overview](#overview)
2. [Core Concepts](#core-concepts)
3. [File Validation](#file-validation)
4. [Creating Submissions](#creating-submissions)
5. [Submission Workflows](#submission-workflows)
6. [Job Workflows](#job-workflows)
7. [Publication Process](#publication-process)
8. [State Reference](#state-reference)
9. [Best Practices](#best-practices)
10. [Troubleshooting](#troubleshooting)

---

## Overview

The GenCC Submission Portal manages gene-disease validity curations through a state-based workflow that ensures data quality and proper publication sequencing.

### What This Guide Covers

- **State Machine**: Understanding the 8 submission states and 3 job states
- **Workflows**: How to create, update, republish, and unpublish submissions
- **Validation**: File upload validation and real-time field validation
- **Publication**: Daily processing and publication to GenCC Search

### Related Documentation

- [STATE_MODEL_USER_GUIDE.md](STATE_MODEL_USER_GUIDE.md) - User-friendly overview with visual diagrams
- [STATE_MODEL_QUICK_REFERENCE.md](STATE_MODEL_QUICK_REFERENCE.md) - Developer API reference
- [SUBMISSION_FILE_VALIDATION_DOCUMENTATION.md](SUBMISSION_FILE_VALIDATION_DOCUMENTATION.md) - Detailed file validation rules

---

## Core Concepts

### Jobs

A **Job** is a container that groups related submissions together for batch processing.

**Job States** (3):
- `draft` - Work in progress, submissions can be added/edited
- `submitted` - Locked, awaiting daily processing
- `completed` - All submissions processed

**Job Identifier Format**: `gencc-import-YYYY-MM-DD-{hash}` (e.g., `gencc-import-2025-11-13-abc123`)

**Key Points**:
- One draft job per submitter at a time
- Jobs are submitted as a unit
- Daily `run:publish` command processes submitted jobs

### Submissions

A **Submission** represents a single gene-disease curation assertion.

**Submission States** (8):
- `draft_new` - New submission being created
- `submitted_new` - New submission awaiting publication
- `published` - Live on GenCC website
- `draft_republish` - Editing published submission
- `submitted_republish` - Updated submission awaiting publication
- `draft_unpublish` - Confirming removal
- `submitted_unpublish` - Removal awaiting processing
- `unpublished` - Hidden from public view

**Submission Identifier Format**: `SGC-1XXXXX` (e.g., `SGC-10001`)

**Key Components**:
- Gene (HGNC ID)
- Disease (MONDO, OMIM, or ORPHA ID)
- Mode of Inheritance (HP term)
- Classification (Definitive, Strong, Moderate, Limited, etc.)
- Evidence (PubMed IDs)
- Report information, assertions, and notes

### Local Keys

Each submission has a `local_key` (your internal identifier):
- Used to track submissions across updates
- Recommended format: UUID v4
- Cannot be changed once set
- Maps to `submission_id` column in spreadsheets

### State Machine Key Concepts

**Draft vs Submitted vs Final**:
- **Draft states** (`draft_*`): Editable, in draft job
- **Submitted states** (`submitted_*`): Locked, awaiting processing
- **Final states** (`published`, `unpublished`): Terminal states (until explicitly moved)

**Origin Tracking**:
When entering `draft_republish` or `draft_unpublish`, the system stores:
- `origin_state`: Where you came from (published or unpublished)
- `origin_snapshot`: Complete copy of original field values
- `origin_job_id`: The job the submission originally belonged to

This enables perfect restoration if you cancel changes.

---

## File Validation

### Spreadsheet Upload Process

1. **File Upload**
   - User selects a draft job and uploads Excel file (.xlsx, .xls)
   - System stores file and creates Document record
   - Real-time progress displayed with animated spinner

2. **Validation Phase**
   - Spreadsheet structure validated (row 6 headers, row 13+ data)
   - All required fields checked
   - Field formats validated (dates, URLs, CURIEs)
   - Database lookups performed (genes, diseases, classifications)
   - Maximum 25 errors reported per upload

3. **Validation Results**
   - **Success**: File processed, submissions created/updated
   - **Errors**: Error list displayed, file must be corrected and re-uploaded
   - **Fatal Errors**: Structure issues prevent all processing

See [SUBMISSION_FILE_VALIDATION_DOCUMENTATION.md](SUBMISSION_FILE_VALIDATION_DOCUMENTATION.md) for complete validation rules.

### Spreadsheet Format

**Required Columns** (in order):
1. `sgc_id` - Leave blank for new, populate for updates
2. `local_key` - Your internal identifier (UUID recommended)
3. `hgnc_id` - Gene identifier (required)
4. `hgnc_symbol` - Gene symbol (optional)
5. `disease_id` - Disease identifier (required)
6. `disease_name` - Disease name (optional)
7. `moi_id` - Mode of Inheritance HP term (required)
8. `moi_name` - MOI name (optional)
9. `submitter_id` - Your organization CURIE (required)
10. `submitter_name` - Organization name (optional)
11. `classification_id` - GenCC classification CURIE (required)
12. `classification_name` - Classification name (optional)
13. `date` - Report date YYYY-MM-DD (required)
14. `public_report_url` - URL to report (optional)
15. `notes` - Additional notes (optional)
16. `pmids` - Comma-separated PubMed IDs (optional)
17. `assertion_criteria_url` - Criteria URL (required)

**Header Row**: Must be in row 6
**Data Rows**: Start at row 13

---

## Creating Submissions

### Via Spreadsheet Upload

**For New Submissions** (no `sgc_id`):

1. Download the official template
2. Fill in required fields for each new submission
3. Leave `sgc_id` column blank
4. Provide unique `local_key` for each row
5. Upload to your draft job
6. System creates submissions in `draft_new` state
7. Validation runs automatically
8. Valid submissions ready for submission

**For Updating Existing Submissions** (with `sgc_id`):

1. Include the existing `SGC-1XXXXX` identifier
2. Update other fields as needed
3. Upload to your draft job
4. System moves submission to `draft_republish` state
5. `origin_state` set to `published`
6. `origin_snapshot` captures original values
7. Validation runs automatically
8. Valid submissions ready for submission

**Important Notes**:
- Gene cannot be changed on existing submissions
- Updates to published submissions automatically handled
- Duplicate `sgc_id` values will cause errors

### Via UI

**Creating New Submission**:

1. Navigate to Jobs page
2. Open your draft job (or create one)
3. Click "Add Submission"
4. Fill in required fields:
   - Gene (HGNC ID or symbol search)
   - Disease (MONDO/OMIM/ORPHA ID or name search)
   - Mode of Inheritance (dropdown)
   - Classification (dropdown)
   - Report Date
   - Assertion Criteria URL
   - Local Key (your identifier)
5. Add optional fields:
   - PubMed IDs
   - Public Report URL
   - Notes
6. Save - submission enters `draft_new` state
7. Real-time validation runs
8. Fix any errors before submitting job

**Editing Draft Submission**:

1. Locate submission in draft job
2. Click edit icon next to field
3. Make changes in modal dialog
4. Save changes
5. Real-time validation runs
6. Status updated based on validation results

---

## Submission Workflows

### 1. New Submission Workflow

**States**: `draft_new` → `submitted_new` → `published`

```
Create Submission
      ↓
  draft_new (editable, in draft job)
      ↓ Submit Job
 submitted_new (locked, awaiting processing)
      ↓ run:publish daily
  published (live on GenCC)
```

**Use Case**: First-time submission of a gene-disease curation

**Can Cancel?** Yes, before job is submitted (delete submission)

---

### 2. Republish Workflow (Published → Updated)

**States**: `published` → `draft_republish` → `submitted_republish` → `published`

```
Published Submission
      ↓ Click Edit or Upload with SGC ID
 draft_republish (editable, in draft job)
      ↓ origin_state='published'
      ↓ origin_snapshot=original values
      ↓ Submit Job
submitted_republish (locked, awaiting processing)
      ↓ run:publish daily
  published (updated version live)
```

**Use Case**: Updating classification, adding new evidence, correcting data

**Can Cancel?** Yes - click "Cancel" to restore original values and return to published state

**Key Feature**: Changes are isolated in draft until published. Original remains live during editing.

---

### 3. Unpublish Workflow (Remove from Public)

**States**: `published` → `draft_unpublish` → `submitted_unpublish` → `unpublished`

```
Published Submission
      ↓ Click Unpublish
 draft_unpublish (read-only, in draft job)
      ↓ Submit Job
submitted_unpublish (locked, awaiting processing)
      ↓ run:publish daily
  unpublished (hidden from public)
```

**Use Case**: Retracting incorrect or disputed curation

**Can Cancel?** Yes - click "Cancel" to return to published state

**Important**: `draft_unpublish` is read-only - you cannot edit fields, only confirm unpublish

---

### 4. Republish from Unpublished

**States**: `unpublished` → `draft_republish` → `submitted_republish` → `published`

```
Unpublished Submission
      ↓ Click Republish
 draft_republish (editable, in draft job)
      ↓ origin_state='unpublished'
      ↓ Submit Job
submitted_republish (locked, awaiting processing)
      ↓ run:publish daily
  published (restored to public)
```

**Use Case**: Restoring previously retracted curation after new evidence emerges

**Can Cancel?** Yes - click "Cancel" to return to unpublished state

---

## Job Workflows

### Job States

**3 states in linear progression**:

```
draft → submitted → completed
```

### Draft State

**What You Can Do**:
- Add new submissions
- Edit existing draft submissions
- Delete draft submissions
- Upload spreadsheets
- Submit the job when ready

**Requirements to Submit**:
- All submissions must be in draft state (`draft_new`, `draft_republish`, or `draft_unpublish`)
- All submissions must have no validation errors
- At least one submission in the job

**Limitations**:
- Only one draft job per submitter
- Cannot create new draft job if one exists
- Cannot create new draft job if submitted job exists

### Submitted State

**What Happens**:
- Job locked - no edits allowed
- All draft submissions transition to submitted state:
  - `draft_new` → `submitted_new`
  - `draft_republish` → `submitted_republish`
  - `draft_unpublish` → `submitted_unpublish`
- Job queued for next `run:publish` execution
- Blocks creation of new draft jobs for this submitter

**What You Can Do**:
- View job and submissions (read-only)
- Cancel job (returns to draft, reverses all state transitions)
- Wait for daily processing

**Cannot**:
- Edit submissions
- Add new submissions
- Delete submissions
- Create new draft job

### Completed State

**What Happened**:
- `run:publish` processed all submissions successfully
- Submitted submissions transitioned to final states:
  - `submitted_new` → `published`
  - `submitted_republish` → `published`
  - `submitted_unpublish` → `unpublished`
- Job marked as complete with timestamp
- `processed_submission_ids` array populated with audit trail

**What You Can Do**:
- View job and submissions (read-only)
- Create new draft job (submitter unblocked)
- Republish or unpublish completed submissions (creates new draft job)

**Cannot**:
- Edit or delete the job
- Modify submissions in the completed job

---

## Publication Process

### Daily Publication Cycle

The `run:publish` command executes once daily (typically overnight) and processes all submitted jobs.

**Processing Sequence**:

1. **Query Submitted Jobs**
   - Find all jobs with `status = 'submitted'`
   - Sort by submission date (oldest first)

2. **For Each Job**:
   - Validate all submissions still error-free
   - Connect to GenCC Search API
   - Process each submission based on state:

3. **Submission Processing by State**:

   **submitted_new** & **submitted_republish**:
   ```
   - Generate JSON payload with full curation data
   - POST to GenCC Search API /submit endpoint
   - If success:
     - Update submission: status = 'published'
     - Set released_at = now()
     - Clear origin_state, origin_snapshot, origin_job_id
   - If failure:
     - Log error
     - Keep submission in submitted state
     - Mark job as having errors
   ```

   **submitted_unpublish**:
   ```
   - Generate unpublish command with SGC ID
   - POST to GenCC Search API /unpublish endpoint
   - If success:
     - Update submission: status = 'unpublished'
     - Clear released_at
   - If failure:
     - Log error
     - Keep submission in submitted state
     - Mark job as having errors
   ```

4. **Job Completion**:
   ```
   - If all submissions processed successfully:
     - Update job: status = 'completed'
     - Set completed_at = now()
     - Record processed_submission_ids with actions
   - If any submission failed:
     - Job remains in 'submitted' state
     - Will retry in next run:publish cycle
   ```

5. **Audit Trail**:
   ```
   - For each processed submission, job records:
     {
       "sid": "SGC-12345",
       "action": "published|republished|unpublished"
     }
   ```

### Manual Publication (Development/Testing)

For development or testing, you can manually trigger publication:

```bash
php artisan run:publish
```

**Output Shows**:
- Jobs being processed
- Submissions being published/unpublished
- Success/failure for each submission
- Final job status

---

## State Reference

### Submission States (8)

| State | Editable | Deletable | In Job Type | Can Cancel | Description |
|-------|----------|-----------|-------------|------------|-------------|
| `draft_new` | ✅ Yes | ✅ Yes | draft | ❌ No | New submission being created |
| `submitted_new` | ❌ No | ❌ No | submitted | ✅ Yes* | New submission awaiting publication |
| `published` | ❌ No | ❌ No | completed | ❌ No | Live on GenCC website |
| `draft_republish` | ✅ Yes | ❌ No | draft | ✅ Yes | Editing published submission |
| `submitted_republish` | ❌ No | ❌ No | submitted | ✅ Yes* | Update awaiting publication |
| `draft_unpublish` | ❌ No | ❌ No | draft | ✅ Yes | Confirming removal (read-only) |
| `submitted_unpublish` | ❌ No | ❌ No | submitted | ✅ Yes* | Removal awaiting processing |
| `unpublished` | ❌ No | ❌ No | completed | ❌ No | Hidden from public view |

*Can only cancel if job is cancelled before `run:publish`

### Job States (3)

| State | Description | User Actions Available |
|-------|-------------|----------------------|
| `draft` | Work in progress | Add/edit/delete submissions, upload files, submit job |
| `submitted` | Awaiting processing | View only, cancel job (before processing) |
| `completed` | Processing finished | View only, create new draft job |

### State Constants (Code)

**Jobs**:
```php
Job::STATUS_DRAFT      = 'draft'
Job::STATUS_SUBMITTED  = 'submitted'
Job::STATUS_COMPLETED  = 'completed'
```

**Submissions**:
```php
Submission::STATUS_DRAFT_NEW             = 'draft_new'
Submission::STATUS_SUBMITTED_NEW         = 'submitted_new'
Submission::STATUS_PUBLISHED             = 'published'
Submission::STATUS_DRAFT_REPUBLISH       = 'draft_republish'
Submission::STATUS_SUBMITTED_REPUBLISH   = 'submitted_republish'
Submission::STATUS_DRAFT_UNPUBLISH       = 'draft_unpublish'
Submission::STATUS_SUBMITTED_UNPUBLISH   = 'submitted_unpublish'
Submission::STATUS_UNPUBLISHED           = 'unpublished'
```

---

## Best Practices

### 1. Use UUIDs for Local Keys
```
✅ Good: "550e8400-e29b-41d4-a716-446655440000"
❌ Bad: "1", "submission_001", "my-gene"
```

### 2. Validate Before Submitting
- Review all submissions in draft job
- Check for red error indicators
- Verify all required fields populated
- Test with small batch first if uploading many submissions

### 3. Understand State Implications
- **draft**: You have full control, can make changes
- **submitted**: Locked in, wait for processing
- **published**: Live on website, need republish workflow to update

### 4. Use Spreadsheets for Bulk Operations
- Multiple new submissions: Use spreadsheet
- Single new submission: Use UI (faster)
- Updating many published: Use spreadsheet with SGC IDs
- Updating one published: Use UI republish button

### 5. Leverage Cancel Feature
- Made a mistake in draft? Cancel and return to original
- Changed your mind? Cancel before submitting job
- Submitted by accident? Cancel job immediately (if processing hasn't started)

### 6. Track Your Submissions
- Use consistent local_key format
- Keep your own spreadsheet with SGC IDs
- Use notes field for internal tracking
- Review processed_submission_ids in completed jobs

### 7. Plan for Daily Processing
- Submit jobs in the morning for next-day processing
- Allow 24 hours for publication
- Check completed jobs the next day
- Address any failures promptly

### 8. Version Management
- System auto-increments versions on republish
- Track major changes in notes field
- Use origin_snapshot to compare versions
- Keep audit trail of classification changes

---

## Troubleshooting

### "Cannot submit job - submissions have errors"

**Cause**: One or more submissions in draft job have validation errors

**Solution**:
1. Open the job detail page
2. Look for submissions with red error indicator
3. Click on each errored submission
4. Review the error messages
5. Correct the invalid fields
6. Verify error clears
7. Try submitting again

---

### "Cannot create draft job - you already have a submitted job"

**Cause**: You have a job in submitted state, blocking new draft creation

**Solution**:
- **Option A**: Wait for daily processing to complete your submitted job
- **Option B**: Cancel the submitted job (if processing hasn't started)
- **Option C**: Contact administrator if job is stuck

---

### "Gene cannot be changed on existing submission"

**Cause**: Attempting to change HGNC ID via spreadsheet update

**Solution**:
1. Leave the old submission as-is (or unpublish it)
2. Create a new submission with correct gene
3. Gene IDs cannot be changed after creation

---

### "Submission not found" when uploading with SGC ID

**Cause**: SGC ID in spreadsheet doesn't exist in database

**Solution**:
1. Verify SGC ID is correct (check format: `SGC-1XXXXX`)
2. Check if submission belongs to your organization
3. Verify submission hasn't been deleted
4. Try uploading as new submission (leave `sgc_id` blank)

---

### Job stuck in "submitted" state

**Cause**: Processing failed, job remains submitted

**Solution**:
1. Check if `run:publish` command is running daily
2. Review submission errors in job detail page
3. Cancel job if needed
4. Fix errors in draft
5. Resubmit

---

### Changes not appearing after republish

**Cause**: May still be in draft or submitted state

**Solution**:
1. Check submission state - is it `published`?
2. If `submitted_republish`, wait for daily processing
3. If `draft_republish`, submit the job
4. Check completed jobs for processing results

---

### Cannot edit published submission

**Cause**: Published submissions are read-only

**Solution**:
1. Click "Republish" button on submission
2. System moves to `draft_republish` state
3. Make your edits
4. Submit job when ready

---

## Advanced Topics

### Batch Operations

**Publishing 100+ Submissions**:
1. Use spreadsheet upload (not UI)
2. Split into smaller jobs (50-100 per job)
3. Submit jobs sequentially, not all at once
4. Monitor each job for errors before submitting next

### Handling Validation Errors

**25 Error Limit**:
- Spreadsheet validation stops at 25 errors
- Fix the first 25, re-upload to see more
- Common errors: missing required fields, invalid CURIEs

**Field-Level Errors**:
- Red indicators show which fields have errors
- Hover over indicator to see error message
- Some errors clear automatically (e.g., duplicate detection)

### Understanding processed_submission_ids

When a job completes, it records what was processed:

```json
[
  {"sid": "SGC-10001", "action": "published"},
  {"sid": "SGC-10002", "action": "republished"},
  {"sid": "SGC-10003", "action": "unpublished"}
]
```

**Actions**:
- `published`: New submission (was `submitted_new`)
- `republished`: Updated submission (was `submitted_republish`)
- `unpublished`: Removed submission (was `submitted_unpublish`)

This audit trail persists even if submissions move to other jobs.

---

## Related Documentation

- [STATE_MODEL_USER_GUIDE.md](STATE_MODEL_USER_GUIDE.md) - User-friendly state model guide with visual diagrams
- [STATE_MODEL_QUICK_REFERENCE.md](STATE_MODEL_QUICK_REFERENCE.md) - Developer API reference with code examples
- [STATE_MODEL_DIAGRAMS.md](STATE_MODEL_DIAGRAMS.md) - Complete Mermaid state diagrams
- [SUBMISSION_FILE_VALIDATION_DOCUMENTATION.md](SUBMISSION_FILE_VALIDATION_DOCUMENTATION.md) - Detailed file validation rules

---

**Version**: 2.0
**Last Updated**: November 13, 2025
**State Model**: String-Based (V2)
