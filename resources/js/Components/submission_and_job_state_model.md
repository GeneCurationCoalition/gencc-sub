# Submission and Job State Model - Implementation Rules

This document defines the state transition rules for both Submission and Job records in the GenCC Submission Portal.

## Submission States

### `draft_new`
**Description**: New submission created in the UI or uploaded without an SGC_ID that does not match an existing published gene-disease-mode of inheritance record.

**Transitions**:
- → `submitted_new` (when job is submitted)
- → DELETED (can be deleted)

**Properties**:
- Editable via submission portal
- Must belong to a draft job
- Can have validation errors

---

### `submitted_new`
**Description**: A `draft_new` submission associated with a submitted job awaiting processing.

**Originates from**: `draft_new`

**Transitions**:
- → `published` (when run:publish succeeds)
- → `draft_new` (if submitted job is cancelled before processing)

**Properties**:
- NOT editable
- Must belong to a submitted job
- Cannot have errors (errors must be fixed before job submission)

---

### `published`
**Description**: An existing submission with an SGC_ID that has been successfully processed through run:publish and is visible to the public in the GenCC search system.

**Originates from**: `submitted_new` OR `submitted_republish`

**Transitions**:
- → `draft_republish` (user clicks Edit/Republish)
- → `draft_unpublish` (user clicks Unpublish)

**Properties**:
- NOT editable directly
- Has SGC_ID assigned
- Publicly visible
- Tracks `released_at` timestamp

---

### `draft_republish`
**Description**: An existing `published` or `unpublished` submission that has been moved to a new draft job for editing and republishing.

**Originates from**: `published` OR `unpublished`

**Transitions**:
- → `submitted_republish` (when job is submitted)
- → `published` (if cancelled/restored and origin_state='published')
- → `unpublished` (if cancelled/restored and origin_state='unpublished')

**Properties**:
- Editable via submission portal
- Belongs to a new draft job
- Tracks origin data:
  - `origin_state`: The state it came from ('published' or 'unpublished')
  - `origin_snapshot`: JSON snapshot of all field values before editing
  - `origin_job_id`: Foreign key to the job it came from
- On restore: reverts to origin_state, restores field values from origin_snapshot, returns to origin_job_id

---

### `submitted_republish`
**Description**: A `draft_republish` submission associated with a submitted job awaiting processing.

**Originates from**: `draft_republish`

**Transitions**:
- → `published` (when run:publish succeeds - updates existing record)
- → `draft_republish` (if submitted job is cancelled before processing)

**Properties**:
- NOT editable
- Must belong to a submitted job
- Updates existing published submission when processed

---

### `draft_unpublish`
**Description**: An existing `published` submission that has been moved to a new draft job for unpublishing.

**Originates from**: `published`

**Transitions**:
- → `submitted_unpublish` (when job is submitted)
- → `published` (if cancelled/restored)

**Properties**:
- **NOT editable** (read-only state)
- Belongs to a new draft job
- Tracks origin data:
  - `origin_state`: Always 'published'
  - `origin_job_id`: Foreign key to the job it came from
- On restore: reverts to published state, returns to origin_job_id

---

### `submitted_unpublish`
**Description**: A `draft_unpublish` submission associated with a submitted job awaiting processing.

**Originates from**: `draft_unpublish`

**Transitions**:
- → `unpublished` (when run:publish succeeds)
- → `draft_unpublish` (if submitted job is cancelled before processing)

**Properties**:
- NOT editable
- Must belong to a submitted job
- Will be hidden from public view when processed

---

### `unpublished`
**Description**: An existing submission that has been unpublished and is NOT visible to the public in the GenCC search system.

**Originates from**: `submitted_unpublish`

**Transitions**:
- → `draft_republish` (user clicks Edit/Republish to restore)

**Properties**:
- NOT editable directly
- Has SGC_ID
- Hidden from public view
- Can be republished to restore

---

## Submission Error Handling

Errors may occur when adding, editing, or uploading submissions in a draft state.

**Error Tracking**:
- Errors stored in `submission_errors` JSON field
- Revalidated when submissions are deleted, edited, or reuploaded
- Draft jobs can only be submitted when all submissions have NO errors
- Submissions with errors display red warning triangle icon

---

## Job States

### `draft`
**Description**: Initial state when a job is created. Submissions can be added, edited, or removed.

**Transitions**:
- → `submitted` (when user submits job - all submissions must be valid)
- → DELETED (can be deleted if empty or only contains draft submissions)

**Properties**:
- Can add/edit/remove submissions
- All submissions must be in `draft_xxx` states
- Can only be deleted if:
  - Job has zero submissions, OR
  - Job only contains draft submissions (no published/unpublished)
- When deleted:
  - All `draft_new` submissions are hard deleted
  - All `draft_republish` and `draft_unpublish` submissions are restored to their prior state and job

**Workflow Rules**:
- Only ONE draft job allowed per submitter at a time
- Cannot create new draft job if submitted job(s) exist
- These rules ensure linear workflow and prevent confusion

---

### `submitted`
**Description**: A job that has been submitted for processing. No edits are allowed.

**Originates from**: `draft`

**Transitions**:
- → `processed` (when run:publish succeeds for at least one submission)
- → `submitted` (if run:publish fails for ALL submissions)
- → `draft` (if user cancels before run:publish starts)

**Properties**:
- NO edits allowed to job or submissions
- Can be cancelled ONLY before run:publish processing begins
- Processed automatically during daily run:publish cycle
- If complete failure: stays in submitted with errors
- If partial failure:
  - Successful submissions → marked as `published`/`unpublished`
  - Failed submissions → moved to new draft job with errors
  - Original job → marked as `processed`

---

### `processed`
**Description**: A job that has been fully processed. All submissions have been successfully processed.

**Originates from**: `submitted`

**Transitions**: NONE (terminal state)

**Properties**:
- Terminal state - cannot be changed
- NO edits allowed
- Cannot be deleted
- Records `processed_submission_ids`: Array of objects tracking each processed submission
  - Structure: `[{"sid": "SGC-12345", "action": "published|republished|unpublished"}]`
  - Actions:
    - `published`: New submission (submitted_new → published)
    - `republished`: Updated submission (submitted_republish → published)
    - `unpublished`: Removed submission (submitted_unpublish → unpublished)
  - Persists even if submissions are moved to other jobs

---

## State Transition Summary

### Submission State Flow

```
draft_new → submitted_new → published
                              ↓
                         draft_republish → submitted_republish → published
                              ↓
                         draft_unpublish → submitted_unpublish → unpublished
                                                                    ↓
                                                              draft_republish (restore)
```

### Job State Flow

```
draft → submitted → processed (terminal)
  ↓                    ↑
DELETE              (success)
```

---

## Key Implementation Details

### Cancel/Restore Operations

When a submission in `draft_republish` or `draft_unpublish` is restored:

1. **State Restoration**: Transitions back to `origin_state` (published or unpublished)
2. **Job Restoration**: Moves back to `origin_job_id`
3. **Field Value Restoration** (draft_republish only): Restores all field values from `origin_snapshot`
4. **Cleanup**: Clears `origin_state`, `origin_snapshot`, and `origin_job_id`

### Publishing Operations

When run:publish processes a submitted job:

1. **Process Each Submission**: Sends to GenCC-Search
2. **Record Success**: Adds entry to job's `processed_submission_ids` array
3. **Update Timestamps**: Sets `released_at` for published submissions
4. **Handle Failures**: Moves failed submissions to new draft job
5. **Complete Job**: Transitions job to `processed` state

### Submission Filters

The submission listing provides these filters:
- **Show All**: All submissions regardless of state
- **Show New**: draft_new and submitted_new only
- **Show Published**: published only
- **Show All Drafts**: All draft_xxx states
- **Show Unpublished**: unpublished only

---

## Terminology

- **Submit** (not "stage"): Action to send draft job for processing
- **Republish** (not "update"): Action to edit and re-publish a published submission
- **Unpublish**: Action to remove a published submission from public view
- **Restore** (not "cancel"): Action to revert draft changes and return to original state
- **Processed** (not "completed"): Terminal state for jobs after successful processing

---

## Database Fields Reference

### Submission Table
- `status`: Current V2 status (draft_new, submitted_new, published, etc.)
- `origin_state`: State before entering draft_republish/draft_unpublish
- `origin_snapshot`: JSON snapshot of field values for restore
- `origin_job_id`: Foreign key to original job
- `submission_errors`: JSON object of validation errors
- `released_at`: Timestamp of publication

### Job Table
- `status`: Current V2 status (draft, submitted, processed)
- `processed_submission_ids`: Array of {sid, action} objects for audit trail

---

Last Updated: 2025-11-05
