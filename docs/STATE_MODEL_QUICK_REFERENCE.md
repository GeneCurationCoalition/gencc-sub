# State Model Quick Reference

**Version**: 2.0 (String-Based States)
**Last Updated**: November 13, 2025

> **For Users**: Looking for a simpler overview? See [STATE_MODEL_USER_GUIDE.md](STATE_MODEL_USER_GUIDE.md)
>
> **This Document**: Technical reference for developers with code examples and API usage

## Submission States (8)

| State | Code | Description | Editable | Can Delete | Can Cancel |
|-------|------|-------------|----------|------------|------------|
| **draft_new** | `Submission::STATUS_DRAFT_NEW` | New submission being drafted | ✅ Yes | ✅ Yes | ❌ No |
| **submitted_new** | `Submission::STATUS_SUBMITTED_NEW` | New submission awaiting publish | ❌ No | ❌ No | ✅ Yes* |
| **published** | `Submission::STATUS_PUBLISHED` | Published to public | ❌ No | ❌ No | ❌ No |
| **draft_republish** | `Submission::STATUS_DRAFT_REPUBLISH` | Published submission being updated | ✅ Yes | ❌ No | ✅ Yes |
| **submitted_republish** | `Submission::STATUS_SUBMITTED_REPUBLISH` | Updated submission awaiting publish | ❌ No | ❌ No | ✅ Yes* |
| **draft_unpublish** | `Submission::STATUS_DRAFT_UNPUBLISH` | Published submission marked for unpublish | ❌ No | ❌ No | ✅ Yes |
| **submitted_unpublish** | `Submission::STATUS_SUBMITTED_UNPUBLISH` | Submission awaiting unpublish | ❌ No | ❌ No | ✅ Yes* |
| **unpublished** | `Submission::STATUS_UNPUBLISHED` | Unpublished, hidden from public | ❌ No | ❌ No | ❌ No |

*Can cancel if job is cancelled before run:publish

## Job States (3)

| State | Code | Description | Editable | Can Delete | Can Submit | Can Cancel |
|-------|------|-------------|----------|------------|------------|------------|
| **draft** | `Job::STATUS_DRAFT` | Draft job, submissions can be added/edited | ✅ Yes | ✅ Yes | ✅ Yes | ❌ No |
| **submitted** | `Job::STATUS_SUBMITTED` | Submitted, awaiting publication | ❌ No | ❌ No | ❌ No | ✅ Yes* |
| **completed** | `Job::STATUS_COMPLETED` | All submissions processed | ❌ No | ❌ No | ❌ No | ❌ No |

*Can cancel before run:publish starts

## State Transitions

### New Submission Flow
```
draft_new → submitted_new → published
```

### Republish Flow (from published)
```
published → draft_republish → submitted_republish → published
```

### Republish Flow (from unpublished)
```
unpublished → draft_republish → submitted_republish → published
```

### Unpublish Flow
```
published → draft_unpublish → submitted_unpublish → unpublished
```

### Job Flow
```
draft → submitted → completed
```

## State Machine Usage

### Check if Transition is Valid
```php
use App\Services\SubmissionStateMachine;

// Check transition
if (SubmissionStateMachine::canTransition($submission->status, 'published')) {
    // Transition is valid
}

// Or validate with exception
try {
    SubmissionStateMachine::validateTransition($submission->status, 'published');
} catch (Exception $e) {
    // Invalid transition
}
```

### Execute State Transition
```php
use App\Services\SubmissionStateMachine;

// Transition submission
SubmissionStateMachine::transition($submission, 'submitted_new');
$submission->save();

// Transition with origin state (for draft_republish)
SubmissionStateMachine::transition(
    $submission,
    'draft_republish',
    'published' // origin_state
);
$submission->save();
```

### Cancel Draft Operation
```php
use App\Services\SubmissionStateMachine;

// Cancel draft_republish or draft_unpublish
if (SubmissionStateMachine::isCancellable($submission->status)) {
    SubmissionStateMachine::cancel($submission);
    $submission->save();
}
```

### Submit a Job
```php
use App\Services\JobStateMachine;

// Submit job (validates all submissions are draft_xxx with no errors)
try {
    JobStateMachine::submit($job);
    $job->save();
} catch (Exception $e) {
    // Job cannot be submitted
}
```

### Cancel a Job
```php
use App\Services\JobStateMachine;

// Cancel submitted job (reverts all submissions to draft)
if (JobStateMachine::canCancel($job->status)) {
    JobStateMachine::cancel($job);
    $job->save();
}
```

### Handle Partial Failure
```php
use App\Services\JobStateMachine;

// Create new draft job with failed submissions
$failedIds = [1, 2, 3];
$newDraftJob = JobStateMachine::handlePartialFailure($job, $failedIds);
// Original job is marked completed
// Failed submissions moved to new draft job
```

## Helper Methods

### Submission State Checks
```php
use App\Services\SubmissionStateMachine;

// Check if draft state
SubmissionStateMachine::isDraftState('draft_new'); // true

// Check if submitted state
SubmissionStateMachine::isSubmittedState('submitted_new'); // true

// Check if editable
SubmissionStateMachine::isEditable('draft_new'); // true
SubmissionStateMachine::isEditable('draft_unpublish'); // false

// Check if deletable
SubmissionStateMachine::canDelete('draft_new'); // true

// Check if cancellable
SubmissionStateMachine::isCancellable('draft_republish'); // true

// Get submitted state for draft
SubmissionStateMachine::getSubmittedStateFor('draft_new'); // 'submitted_new'

// Get draft state for submitted
SubmissionStateMachine::getDraftStateFor('submitted_new'); // 'draft_new'

// Get description
SubmissionStateMachine::getStateDescription('draft_new');
// "New submission being drafted"
```

### Job State Checks
```php
use App\Services\JobStateMachine;

// Check if editable
JobStateMachine::isEditable('draft'); // true

// Check if deletable
JobStateMachine::canDelete('draft'); // true

// Check if can submit
JobStateMachine::canSubmit('draft'); // true

// Check if can cancel
JobStateMachine::canCancel('submitted'); // true

// Check if terminal
JobStateMachine::isTerminal('completed'); // true

// Get state counts
$counts = JobStateMachine::getStateCounts();
// ['draft' => 5, 'submitted' => 2, 'completed' => 100]
```

## Database Queries

### Query by State
```php
// Get all draft submissions
$drafts = Submission::where('status', Submission::STATUS_DRAFT_NEW)->get();

// Get all published submissions
$published = Submission::where('status', Submission::STATUS_PUBLISHED)->get();

// Get all draft jobs
$draftJobs = Job::where('status', Job::STATUS_DRAFT)->get();

// Get all submitted jobs
$submittedJobs = Job::where('status', Job::STATUS_SUBMITTED)->get();
```

### Query Multiple States
```php
// Get all draft_xxx submissions
$draftSubmissions = Submission::whereIn('status', [
    Submission::STATUS_DRAFT_NEW,
    Submission::STATUS_DRAFT_REPUBLISH,
    Submission::STATUS_DRAFT_UNPUBLISH
])->get();

// Get all submitted_xxx submissions
$submittedSubmissions = Submission::whereIn('status', [
    Submission::STATUS_SUBMITTED_NEW,
    Submission::STATUS_SUBMITTED_REPUBLISH,
    Submission::STATUS_SUBMITTED_UNPUBLISH
])->get();

// Using LIKE for pattern matching
$allDrafts = Submission::where('status', 'LIKE', 'draft_%')->get();
$allSubmitted = Submission::where('status', 'LIKE', 'submitted_%')->get();
```

### Query with Job
```php
// Get all submissions in draft jobs
$submissions = Submission::whereHas('job', function($query) {
    $query->where('status', Job::STATUS_DRAFT);
})->get();

// Get job with submissions
$job = Job::with('submissions')->find($id);

// Count submissions by state in a job
$job->submissions()
    ->where('status', Submission::STATUS_DRAFT_NEW)
    ->count();
```

## Display Strings

### Submission States
```php
// In Submission model
$submission->status_strings['draft_new']; // "Draft (New)"
$submission->status_strings['published']; // "Published"
```

### Job States
```php
// In Job model
$job->status_strings['draft']; // "Draft"
$job->status_strings['submitted']; // "Submitted"
$job->status_strings['completed']; // "Completed"
```

## Common Workflows

### Create New Submission
```php
$submission = Submission::create([
    'status' => Submission::STATUS_DRAFT_NEW,
    // ... other fields
]);
```

### Move Published to Republish
```php
// User clicks "Republish"
$submission->status = Submission::STATUS_DRAFT_REPUBLISH;
$submission->origin_state = Submission::STATUS_PUBLISHED;
$submission->original_job_id = $submission->job_id;
$submission->job_id = $draftJob->id;
$submission->save();
```

### Move Published to Unpublish
```php
// User clicks "Unpublish"
$submission->status = Submission::STATUS_DRAFT_UNPUBLISH;
$submission->original_job_id = $submission->job_id;
$submission->job_id = $unpublishJob->id;
$submission->save();
```

### Cancel Draft Operation
```php
// User clicks "Cancel"
if ($submission->status === Submission::STATUS_DRAFT_REPUBLISH) {
    $submission->status = $submission->origin_state; // Back to published or unpublished
    $submission->job_id = $submission->original_job_id;
    $submission->origin_state = null;
    $submission->original_job_id = null;
    $submission->save();
}
```

### Submit a Draft Job
```php
// User clicks "Submit"
$job = Job::find($id);

// Validate and transition
JobStateMachine::submit($job);
$job->save();

// All draft_xxx submissions are now submitted_xxx
```

### Publish Submissions
```php
// In run:publish command
foreach ($job->submissions as $submission) {
    switch ($submission->status) {
        case Submission::STATUS_SUBMITTED_NEW:
        case Submission::STATUS_SUBMITTED_REPUBLISH:
            // Send to GenCC-Search
            $this->publishToGenCC($submission);

            // Update state
            $submission->status = Submission::STATUS_PUBLISHED;
            $submission->released_at = now();
            $submission->origin_state = null;
            $submission->original_job_id = null;
            $submission->save();
            break;

        case Submission::STATUS_SUBMITTED_UNPUBLISH:
            // Send unpublish to GenCC-Search
            $this->unpublishFromGenCC($submission);

            // Update state
            $submission->status = Submission::STATUS_UNPUBLISHED;
            $submission->released_at = null;
            $submission->save();
            break;
    }
}

// Mark job as completed
$job->status = Job::STATUS_COMPLETED;
$job->save();
```

## Migration Notes

The status model migration has been completed. The `status` column now uses string-based states
(e.g., 'published', 'draft_new') instead of integer constants. The old `status` column has
been renamed to `status` and the data has been migrated.

## API Endpoints (Phase 2)

### Job Endpoints
```
POST   /api/jobs/{id}/submit     # Submit draft job
POST   /api/jobs/{id}/cancel     # Cancel submitted job
DELETE /api/jobs/{id}             # Delete draft job
```

### Submission Endpoints
```
POST /api/submissions/{id}/republish  # Move to draft_republish
POST /api/submissions/{id}/unpublish  # Move to draft_unpublish
POST /api/submissions/{id}/cancel     # Cancel draft operation
```

## Color Coding (Frontend)

### Submission States
- 🟡 `draft_new` - Yellow (needs attention)
- 🔵 `submitted_new` - Blue (in progress)
- 🟢 `published` - Green (success/live)
- 🟠 `draft_republish` - Orange (updating)
- 🔵 `submitted_republish` - Blue (in progress)
- 🟣 `draft_unpublish` - Purple (removing)
- 🔵 `submitted_unpublish` - Blue (in progress)
- ⚫ `unpublished` - Gray (inactive)

### Job States
- 🟡 `draft` - Yellow (work in progress)
- 🔵 `submitted` - Blue (awaiting processing)
- 🟢 `completed` - Green (done)

## Icons (Frontend)

### Actions
- Create: `pi-plus-circle`
- Republish: `pi-refresh`
- Unpublish: `pi-eye-slash`
- Submit: `pi-send`
- Cancel: `pi-times-circle`
- Delete: `pi-trash`

### States
- draft_new: `pi-file-edit`
- submitted_new: `pi-clock`
- published: `pi-check-circle`
- draft_republish: `pi-pencil`
- submitted_republish: `pi-clock`
- draft_unpublish: `pi-ban`
- submitted_unpublish: `pi-clock`
- unpublished: `pi-eye-slash`

## Common Errors

### "Invalid state transition"
```
Cause: Attempting an invalid state transition
Solution: Check SubmissionStateMachine::canTransition() first
```

### "Only draft jobs can be submitted"
```
Cause: Trying to submit a job that isn't in draft state
Solution: Check JobStateMachine::canSubmit() first
```

### "Job contains submissions with errors"
```
Cause: Attempting to submit job with validation errors
Solution: Fix all submission errors before submitting
```

### "Cannot cancel from state 'published'"
```
Cause: Trying to cancel a submission not in draft state
Solution: Only draft_republish and draft_unpublish can be cancelled
```

## Legacy Compatibility

### Old Constants (Still Available)
```php
// Submissions (LEGACY - use STATUS_* instead)
Submission::STATUS_INITIALIZING  // 0
Submission::STATUS_NEW           // 1
Submission::STATUS_PROCESSING    // 3
Submission::STATUS_ERRORS        // 4
Submission::STATUS_REMOVED       // 9
Submission::STATUS_PUBLISHED     // 20

// Jobs (LEGACY - use STATUS_* instead)
Job::STATUS_INITIALIZING         // 0
Job::STATUS_QUEUED               // 1
Job::STATUS_PROCESSING           // 2
Job::STATUS_COMPLETE             // 3
Job::STATUS_ERRORS               // 4
Job::STATUS_STAGED               // 5
Job::STATUS_REMOVED              // 9
Job::STATUS_FAILED               // 99
```

### Old vs New Mapping

**Jobs**:
- 0,1,2,4 → `draft`
- 5 → `submitted`
- 3 → `completed`

**Submissions**:
- 20 → `published`
- 3 (with publish_date) → `draft_republish`
- 0,1,3,4 (without publish_date) → `draft_new`

---

## Related Documentation

- [STATE_MODEL_USER_GUIDE.md](STATE_MODEL_USER_GUIDE.md) - User-friendly guide with visual workflows
- [STATE_MODEL_DIAGRAMS.md](STATE_MODEL_DIAGRAMS.md) - Detailed Mermaid state diagrams
- [DASHBOARD_TECHNICAL_GUIDE.md](DASHBOARD_TECHNICAL_GUIDE.md) - Dashboard implementation details

---

**Quick Reference Version**: 2.0
**Last Updated**: November 13, 2025
