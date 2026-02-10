# State Model Quick Reference

**Version**: 3.0 (Simplified Status Model)
**Last Updated**: February 2026

> **For Users**: Looking for a simpler overview? See [STATE_MODEL_USER_GUIDE.md](STATE_MODEL_USER_GUIDE.md)
>
> **This Document**: Technical reference for developers with code examples and API usage

## Simplified State Model Overview

The V3 state model uses **5 submission statuses** (reduced from 8):

- **Pending statuses** (action-based): `new`, `republish`, `unpublish`
- **Released statuses** (visibility-based): `published`, `unpublished`

**Key concept**: Stage (draft/submitted) is derived from `Job.status`, not stored in submission.

## Submission Statuses (5)

| Status | Constant | Description | Editable | Can Delete |
|--------|----------|-------------|----------|------------|
| **new** | `Submission::STATUS_NEW` | New submission awaiting first release | ✅ In draft job | ✅ In draft job |
| **republish** | `Submission::STATUS_REPUBLISH` | Update to existing submission | ✅ In draft job | ❌ No |
| **unpublish** | `Submission::STATUS_UNPUBLISH` | Marked for unpublishing | ❌ Read-only | ❌ No |
| **published** | `Submission::STATUS_PUBLISHED` | Published to public | ❌ No | ❌ No |
| **unpublished** | `Submission::STATUS_UNPUBLISHED` | Hidden from public | ❌ No | ❌ No |

### Legacy Compatibility Constants

These constants map to the same values for backwards compatibility:

```php
// All map to 'new'
Submission::STATUS_DRAFT_NEW = 'new';
Submission::STATUS_SUBMITTED_NEW = 'new';

// All map to 'republish'
Submission::STATUS_DRAFT_REPUBLISH = 'republish';
Submission::STATUS_SUBMITTED_REPUBLISH = 'republish';

// All map to 'unpublish'
Submission::STATUS_DRAFT_UNPUBLISH = 'unpublish';
Submission::STATUS_SUBMITTED_UNPUBLISH = 'unpublish';
```

## Job States (3)

| State | Constant | Description | Editable | Can Delete | Can Submit |
|-------|----------|-------------|----------|------------|------------|
| **draft** | `Job::STATUS_DRAFT` | Draft job, submissions editable | ✅ Yes | ✅ Yes | ✅ Yes |
| **submitted** | `Job::STATUS_SUBMITTED` | Awaiting release processing | ❌ No | ❌ No | ❌ No |
| **released** | `Job::STATUS_RELEASED` | All submissions released | ❌ No | ❌ No | ❌ No |

## State Transitions

### New Submission Flow
```
new → published (via run:publish when job released)
```

### Republish Flow
```
published → republish → published (via run:publish)
unpublished → republish → published (via run:publish)
```

### Unpublish Flow
```
published → unpublish → unpublished (via run:publish)
```

### Job Flow
```
draft → submitted → released
```

## State Machine Usage

### Check if Transition is Valid
```php
use App\Services\SubmissionStateMachine;

// Check transition
if (SubmissionStateMachine::canTransition($submission, 'published')) {
    // Transition is valid
}

// Or validate with exception
try {
    SubmissionStateMachine::validateTransition($submission->status, 'published');
} catch (Exception $e) {
    // Invalid transition
}
```

### Check if Submission is Pending or Released
```php
use App\Services\SubmissionStateMachine;

// Check if pending (awaiting release)
SubmissionStateMachine::isPending($submission); // true for new, republish, unpublish
SubmissionStateMachine::isPendingState('new'); // true

// Check if released
SubmissionStateMachine::isReleased($submission); // true for published, unpublished
SubmissionStateMachine::isReleasedState('published'); // true
```

### Check if Submission is Immutable
```php
use App\Services\SubmissionStateMachine;

// Immutable = cannot edit fields
// True for: released statuses OR submissions in submitted jobs
SubmissionStateMachine::isImmutable($submission);
```

### Execute State Transition
```php
use App\Services\SubmissionStateMachine;

// Transition submission
SubmissionStateMachine::transition($submission, 'published');
$submission->save();
```

### Submit a Job
```php
use App\Services\JobStateMachine;

// Submit job (validates all submissions are pending with no errors)
try {
    JobStateMachine::submit($job);
    $job->save();
    // Job.status = submitted
    // All submissions now immutable
    // submitted_at timestamp set on submissions
} catch (Exception $e) {
    // Job cannot be submitted
}
```

### Cancel a Submitted Job
```php
use App\Services\JobStateMachine;

// Cancel submitted job (before run:publish)
if (JobStateMachine::canCancel($job->status)) {
    $job->status = Job::STATUS_DRAFT;
    $job->save();
    // Submissions become editable again
}
```

### Complete a Job After Release
```php
use App\Services\JobStateMachine;

// Complete job after successful run:publish
JobStateMachine::complete($job);
$job->save();
// Job.status = released
// Job.released_at = now()
```

### Handle Partial Failure
```php
use App\Services\JobStateMachine;

// Create new draft job with failed submissions
$failedIds = [1, 2, 3];
$newDraftJob = JobStateMachine::handlePartialFailure($job, $failedIds);
// Original job is marked released
// Failed submissions moved to new draft job
```

## Helper Methods

### Submission State Checks
```php
use App\Services\SubmissionStateMachine;

// Check if pending state
SubmissionStateMachine::isPendingState('new'); // true
SubmissionStateMachine::isPendingState('published'); // false

// Check if released state
SubmissionStateMachine::isReleasedState('published'); // true
SubmissionStateMachine::isReleasedState('new'); // false

// Check if editable (only new and republish in draft job)
SubmissionStateMachine::isEditable('new'); // true
SubmissionStateMachine::isEditable('unpublish'); // false (read-only)

// Check if deletable
SubmissionStateMachine::canDelete('new'); // true

// Get description
SubmissionStateMachine::getStateDescription('new');
// "New submission (v1) awaiting release"
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

// Check if immutable (cannot edit)
JobStateMachine::isImmutable('submitted'); // true

// Check if terminal
JobStateMachine::isTerminal('released'); // true

// Get state counts
$counts = JobStateMachine::getStateCounts();
// ['draft' => 5, 'submitted' => 2, 'released' => 100]
```

## Database Queries

### Query by Status
```php
// Get all new submissions
$new = Submission::where('status', Submission::STATUS_NEW)->get();

// Get all published submissions
$published = Submission::where('status', Submission::STATUS_PUBLISHED)->get();

// Get all draft jobs
$draftJobs = Job::where('status', Job::STATUS_DRAFT)->get();

// Get all submitted jobs
$submittedJobs = Job::where('status', Job::STATUS_SUBMITTED)->get();
```

### Query Pending vs Released
```php
// Get all pending submissions (awaiting release)
$pending = Submission::whereIn('status', [
    Submission::STATUS_NEW,
    Submission::STATUS_REPUBLISH,
    Submission::STATUS_UNPUBLISH
])->get();

// Get all released submissions
$released = Submission::whereIn('status', [
    Submission::STATUS_PUBLISHED,
    Submission::STATUS_UNPUBLISHED
])->get();
```

### Query by Stage (via Job)
```php
// Get all submissions in draft stage (pending + draft job)
$draftStage = Submission::whereIn('status', [
    Submission::STATUS_NEW,
    Submission::STATUS_REPUBLISH,
    Submission::STATUS_UNPUBLISH,
])->whereHas('job', function($query) {
    $query->where('status', Job::STATUS_DRAFT);
})->get();

// Get all submissions in submitted stage (pending + submitted job)
$submittedStage = Submission::whereIn('status', [
    Submission::STATUS_NEW,
    Submission::STATUS_REPUBLISH,
    Submission::STATUS_UNPUBLISH,
])->whereHas('job', function($query) {
    $query->where('status', Job::STATUS_SUBMITTED);
})->get();
```

### Query with Job
```php
// Get all submissions in draft jobs
$submissions = Submission::whereHas('job', function($query) {
    $query->where('status', Job::STATUS_DRAFT);
})->get();

// Get job with submissions
$job = Job::with('submissions')->find($id);

// Count submissions by status in a job
$job->submissions()
    ->where('status', Submission::STATUS_NEW)
    ->count();
```

## Common Workflows

### Create New Submission
```php
$submission = Submission::create([
    'status' => Submission::STATUS_NEW,
    'job_id' => $draftJob->id,
    // ... other fields
]);
```

### Move Published to Republish
```php
// User clicks "Republish"
$submission->status = Submission::STATUS_REPUBLISH;
$submission->origin_state = Submission::STATUS_PUBLISHED;
$submission->original_job_id = $submission->job_id;
$submission->job_id = $draftJob->id;
$submission->save();
```

### Move Published to Unpublish
```php
// User clicks "Unpublish"
$submission->status = Submission::STATUS_UNPUBLISH;
$submission->origin_state = Submission::STATUS_PUBLISHED;
$submission->original_job_id = $submission->job_id;
$submission->job_id = $draftJob->id;
$submission->save();
```

### Cancel Draft Operation
```php
// User clicks "Cancel" on republish or unpublish
$submission->status = $submission->origin_state; // Back to published or unpublished
$submission->job_id = $submission->original_job_id;
$submission->origin_state = null;
$submission->original_job_id = null;
$submission->save();
```

### Submit a Draft Job
```php
// User clicks "Submit"
$job = Job::find($id);

// Validate and transition
JobStateMachine::submit($job);
$job->save();

// Submissions are now immutable (Job.status = submitted)
// submitted_at timestamp set on all pending submissions
```

### Release Submissions (in run:publish)
```php
// In run:publish command
foreach ($job->submissions as $submission) {
    switch ($submission->status) {
        case Submission::STATUS_NEW:
        case Submission::STATUS_REPUBLISH:
            // Send to GenCC-Search
            $this->publishToGenCC($submission);

            // Update status
            $submission->status = Submission::STATUS_PUBLISHED;
            $submission->released_at = now();
            $submission->origin_state = null;
            $submission->original_job_id = null;
            $submission->save();
            break;

        case Submission::STATUS_UNPUBLISH:
            // Send unpublish to GenCC-Search
            $this->unpublishFromGenCC($submission);

            // Update status
            $submission->status = Submission::STATUS_UNPUBLISHED;
            $submission->released_at = null;
            $submission->save();
            break;
    }
}

// Mark job as released
JobStateMachine::complete($job);
$job->save();
```

## Color Coding (Frontend)

### Submission Statuses (with stage context)
- 🟡 `new` (draft) - Yellow (new submission in draft)
- 🔵 `new` (submitted) - Blue (awaiting release)
- 🟢 `published` - Green (live/active)
- 🟠 `republish` (draft) - Orange (updating)
- 🔵 `republish` (submitted) - Blue (awaiting release)
- 🟤 `unpublish` (draft) - Dark orange (removing, read-only)
- 🔵 `unpublish` (submitted) - Blue (awaiting release)
- ⚫ `unpublished` - Gray (hidden)

### Job States
- 🟡 `draft` - Yellow (work in progress)
- 🔵 `submitted` - Blue (awaiting processing)
- 🟢 `released` - Green (done)

## Icons (Frontend)

### Actions
- Create: `pi-plus-circle`
- Republish: `pi-refresh`
- Unpublish: `pi-eye-slash`
- Submit: `pi-send`
- Cancel: `pi-times-circle`
- Delete: `pi-trash`

### Statuses
- new: `pi-file-edit`
- republish: `pi-pencil`
- unpublish: `pi-ban`
- published: `pi-check-circle`
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

### "is not a pending state"
```
Cause: Trying to call draft/submitted methods on released submission
Solution: Only pending statuses (new, republish, unpublish) can be in jobs
```

## Understanding Immutability

### When is a submission immutable?

A submission is **immutable** (cannot have fields edited) when:

1. **Released status**: `published` or `unpublished`
2. **In submitted job**: Status is pending but `Job.status = submitted`

```php
// Check if immutable
SubmissionStateMachine::isImmutable($submission);
```

### Stage vs Status

| Term | Definition |
|------|------------|
| **Status** | Stored in `submissions.status` field (new, republish, unpublish, published, unpublished) |
| **Stage** | Derived from `jobs.status` (draft or submitted) |

A submission with `status = 'new'` could be in either:
- **Draft stage**: `Job.status = 'draft'` → Editable
- **Submitted stage**: `Job.status = 'submitted'` → Immutable

---

## Related Documentation

- [STATE_MODEL_USER_GUIDE.md](STATE_MODEL_USER_GUIDE.md) - User-friendly guide with visual workflows
- [STATE_MODEL_DIAGRAMS.md](STATE_MODEL_DIAGRAMS.md) - Detailed Mermaid state diagrams

---

**Quick Reference Version**: 3.0
**Last Updated**: February 2026
