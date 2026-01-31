# Plan: Immutable Records for Published, Unpublished, and Submitted States

## Problem

Submissions and jobs in `published`, `unpublished`, and `submitted` states represent immutable records — they should not be modifiable. Currently there are no guards at the database, model, API, or UI layers to enforce this. A published submission's fields (gene, disease, classification, mechanism, evidence, etc.) can all be changed via the API `SubmissionController@update()` endpoint with no status checks.

## Scope

**Immutable states:**
- Submission: `published`, `unpublished`, `new` (when job is `submitted`), `republish` (when job is `submitted`), `unpublish` (when job is `submitted`)
- Job: `submitted`, `released`

**Exception:** The release process (`GenccRelease` / `ReleaseController`) must still be able to transition:
- Submitted submissions → `published` or `unpublished`
- Submitted jobs → `released`

## Implementation Layers

### Layer 1: Eloquent Model Guards (Database-Level Protection)

The strongest protection. Add `updating` model events that reject changes to immutable records.

#### Submission Model (`app/Models/Submission.php`)

Add an `updating` event in the `booted()` method that:

1. Checks if the submission is in an immutable state:
   - `status` is `published` or `unpublished`, OR
   - The submission's job has `status = submitted`
2. If immutable, only allow changes to a whitelist of fields:
   - **Release process fields**: `status`, `released_at`, `unpublished_at`, `is_most_recent`, `is_live`, `original_submission_data`, `job_id` (for failed publish → draft move)
   - **System fields**: `submission_errors` (for publish error recording)
3. If any non-whitelisted field is dirty, throw an `\RuntimeException` with a descriptive message
4. Add a bypass mechanism for the release process: a static flag `$bypassImmutability = false` that can be temporarily set to `true` by trusted system processes

```php
// In Submission::booted()
static::updating(function (Submission $submission) {
    if (static::$bypassImmutability) {
        return true;
    }

    $immutableStatuses = [self::STATUS_PUBLISHED, self::STATUS_UNPUBLISHED];
    $isImmutable = in_array($submission->status, $immutableStatuses);

    // Also check if submission is in a submitted job
    if (!$isImmutable && $submission->job) {
        $isImmutable = $submission->job->status === Job::STATUS_SUBMITTED;
    }

    if (!$isImmutable) {
        return true;
    }

    // Fields that the release process and system are allowed to change
    $allowedFields = [
        'status', 'released_at', 'unpublished_at',
        'is_most_recent', 'is_live',
        'original_submission_data',
        'job_id',              // failed publish moves submission to draft job
        'submission_errors',   // publish error recording
    ];

    $dirty = array_keys($submission->getDirty());
    $disallowed = array_diff($dirty, $allowedFields);

    if (!empty($disallowed)) {
        throw new \RuntimeException(
            "Cannot modify immutable submission {$submission->sid} (status: {$submission->status}). " .
            "Disallowed fields: " . implode(', ', $disallowed)
        );
    }
});
```

#### Job Model (`app/Models/Job.php`)

Add an `updating` event in the `booted()` method that:

1. Checks if the job is in an immutable state: `submitted` or `released`
2. If immutable, only allow changes to:
   - **Release process fields**: `status`, `released_at`, `processed_submission_ids`, `is_publishing`
3. Throw `\RuntimeException` for disallowed changes
4. Same `$bypassImmutability` static flag

```php
// In Job::booted()
static::updating(function (Job $job) {
    if (static::$bypassImmutability) {
        return true;
    }

    $immutableStatuses = [self::STATUS_SUBMITTED, self::STATUS_RELEASED];
    if (!in_array($job->status, $immutableStatuses)) {
        return true;
    }

    // Only released is truly terminal. Submitted can transition.
    $allowedFields = [
        'status', 'released_at',
        'processed_submission_ids',
        'is_publishing',  // flag toggled during release
    ];

    $dirty = array_keys($job->getDirty());
    $disallowed = array_diff($dirty, $allowedFields);

    if (!empty($disallowed)) {
        throw new \RuntimeException(
            "Cannot modify immutable job {$job->slug} (status: {$job->status}). " .
            "Disallowed fields: " . implode(', ', $disallowed)
        );
    }
});
```

### Layer 2: API Controller Guards

Add an explicit status check at the top of `SubmissionController@update()` and `JobController@update()`.

#### SubmissionController@update() (`app/Http/Controllers/API/SubmissionController.php`)

After the submission is found (line ~138), before the switch statement (line ~149):

```php
// Reject updates to immutable submissions
if (SubmissionStateMachine::isImmutable($submission)) {
    return response()->json([
        'success' => 'false',
        'status_code' => 3020,
        'message' => 'This submission is locked and cannot be edited in its current state'
    ], 200);
}
```

The `favorites` case should be exempted from this check since it modifies user preferences, not the submission itself. Move the favorites check before the immutability guard, or add it as a special case.

#### JobController (`app/Http/Controllers/API/JobController.php`)

Add similar guard to `update_name()` and any other methods that modify job fields.

### Layer 3: State Machine Additions

Add `isImmutable()` methods to both state machines.

#### SubmissionStateMachine (`app/Services/SubmissionStateMachine.php`)

```php
/**
 * Check if a submission is immutable (cannot have fields edited).
 * Published, unpublished, and submitted-stage submissions are immutable.
 */
public static function isImmutable($submissionOrState): bool
{
    if ($submissionOrState instanceof Submission) {
        // Check released states
        if (self::isReleasedState($submissionOrState->status)) {
            return true;
        }
        // Check if in submitted job (pending release)
        if ($submissionOrState->job &&
            $submissionOrState->job->status === Job::STATUS_SUBMITTED) {
            return true;
        }
        return false;
    }

    // String-only check (no job context)
    return self::isReleasedState($submissionOrState);
}
```

#### JobStateMachine (`app/Services/JobStateMachine.php`)

```php
public static function isImmutable($jobOrState): bool
{
    $state = $jobOrState instanceof Job ? $jobOrState->status : $jobOrState;
    return in_array($state, [Job::STATUS_SUBMITTED, Job::STATUS_RELEASED]);
}
```

### Layer 4: Frontend Guards (Already Mostly Correct)

The frontend already hides edit buttons for non-editable states via `jobHasStatusProcessingOrError()` in `SubmissionItem.vue`. Verify:

1. **SubmissionItem.vue:224-245** — `jobHasStatusProcessingOrError()` returns `false` for published/unpublished/submitted submissions. This correctly hides all edit buttons. **No changes needed.**
2. Add `disabled` state to any buttons that are visible but should be non-functional as a defense-in-depth measure.
3. The `isNotEditable` computed property (line ~37) already covers most cases.

### Layer 5: Console Command Updates

#### ProcessSubmissions.php

This command directly sets `$submission->status` and `$job->status` bypassing state machines. Refactor to use state machine transitions:

```php
// Before (line 54):
$submission->status = Submission::STATUS_PUBLISHED;

// After:
SubmissionStateMachine::transition($submission, Submission::STATUS_PUBLISHED);
```

This is already done correctly in `ReleaseController::send_job()` (line 200). The `ProcessSubmissions` command appears to be a legacy fallback — verify if it's still used. If not, deprecate it.

#### GenccRelease.php

Already uses `JobStateMachine::complete()` (line 325 of ReleaseController) and `SubmissionStateMachine::transition()` (line 200). **No changes needed** — the model-level guards with allowed fields will permit these transitions.

### Layer 6: Raw Query Protection

The `ReleaseController::send_job()` method uses raw `Submission::where()->update()` calls (lines 210-215, 226-231) to update `is_most_recent` and `is_live` on previous versions. These bypass Eloquent model events.

Options:
1. Refactor to use individual model saves (slower but protected)
2. Accept the raw updates for these specific system operations since they only modify allowed fields
3. Add database triggers (heavyweight, not recommended for this app)

**Recommendation:** Option 2 — the raw updates only touch `is_most_recent` and `is_live`, which are in the allowed whitelist. Document this as an accepted pattern for the release process.

## Files to Modify

| File | Change |
|------|--------|
| `app/Models/Submission.php` | Add `$bypassImmutability` flag and `updating` guard in `booted()` |
| `app/Models/Job.php` | Add `$bypassImmutability` flag and `updating` guard in `booted()` |
| `app/Services/SubmissionStateMachine.php` | Add `isImmutable()` method |
| `app/Services/JobStateMachine.php` | Add `isImmutable()` method |
| `app/Http/Controllers/API/SubmissionController.php` | Add immutability check at top of `update()` |
| `app/Http/Controllers/API/JobController.php` | Add immutability check to `update_name()` |
| `app/Console/Commands/ProcessSubmissions.php` | Refactor to use state machine transitions (or deprecate) |

## Testing Strategy

1. **Unit tests** for model guards:
   - Attempt to modify a published submission's gene_id → expect RuntimeException
   - Attempt to modify a published submission's status via release process → expect success
   - Attempt to modify a submitted job's name → expect RuntimeException
   - Attempt to complete a submitted job → expect success

2. **Feature tests** for API guards:
   - POST to `/api/submissions/{ident}` with type=mechanism_of_disease on published submission → expect 3020 error
   - POST to `/api/submissions/{ident}` with type=favorites on published submission → expect success (not a submission field)

3. **Regression tests**:
   - Full publish flow: draft → submitted → released still works
   - Republish flow: published → draft_republish → edit → submit → published still works
   - Unpublish flow: published → draft_unpublish → submit → unpublished still works
   - Failed publish: submission moved to draft job still works

## Risk Assessment

- **Low risk**: API/UI guards — defensive, won't break existing flows
- **Medium risk**: Model `updating` guards — could break the release process if allowed fields list is incomplete. Mitigate with `$bypassImmutability` flag and thorough testing of publish/unpublish flows
- **Low risk**: State machine additions — new methods, no changes to existing logic
