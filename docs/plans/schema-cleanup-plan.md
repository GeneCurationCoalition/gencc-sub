# Schema Cleanup Plan: Submission and Job Status Management

## Goal

Simplify and clarify the submission and job schema to better reflect the conceptual model:
- **Jobs**: Active (DRAFT/SUBMITTED) vs Released
- **Pending Submissions**: Action-based status (NEW/REPUBLISH/UNPUBLISH) with optional errors
- **Released Submissions**: Visibility-based status (PUBLISHED/UNPUBLISHED) with version tracking

## Design Decisions (Confirmed)

1. **Action field retained after release** - Keep for audit trail
2. **Keep `is_most_recent`** - Useful for identifying highest version including pending drafts
3. **Rename Job `processed` to `released`** - Consistency with terminology
4. **Archived is derived** - `is_live=false AND released_at IS NOT NULL`
5. **Stage derived from Job** - With proper indexes, join performance is acceptable
6. **Phased migration approach** - Gradual rollout
7. **No deprecation period needed** - No external dependencies on status values

---

## Current State Analysis

### Current Submission Status Values (8 total)
```
draft_new           - New submission being drafted (v1)
draft_republish     - Editing existing published submission (v2+)
draft_unpublish     - Pending unpublish action (v2+)
submitted_new       - New submission submitted for review
submitted_republish - Updated submission submitted for review
submitted_unpublish - Unpublish submitted for review
published           - Released and visible
unpublished         - Released but hidden
```

### Current Job Status Values (3 total)
```
draft     - Submissions can be added/edited/removed
submitted - Awaiting release processing
processed - All submissions processed (should be renamed to 'released')
```

### Current Boolean Flags
- `is_most_recent` - Highest version number for this SID (may be draft) - **KEEP**
- `is_live` - Current released state for this SID - **KEEP**

### Current Timestamps
- `created_at` - When record created
- `submitted_at` - When transitioned from draft to submitted
- `released_at` - When first released
- `unpublished_at` - When unpublished

## Problems with Current Schema

### 1. Status Conflates Three Concerns
The compound statuses (`draft_new`, `submitted_republish`, etc.) combine:
- **Stage**: draft vs submitted (redundant - matches Job.status)
- **Action**: new vs republish vs unpublish (the submission's purpose)
- **Visibility**: published vs unpublished (post-release state)

### 2. Stage is Redundant
Since submissions in a DRAFT job are always draft_*, and submissions in a SUBMITTED job are always submitted_*, the draft/submitted prefix is derivable from Job.status.

### 3. Too Many Status Values
8 status values when we really only need 5.

---

## Proposed Schema (Simplified)

### New Submission Status Values (5 total, down from 8)

**Pending statuses (action-based):**
| Status | Meaning | Version | Notes |
|--------|---------|---------|-------|
| `new` | First submission for this SGC ID | v1 only | Stage derived from Job |
| `republish` | Updating existing submission | v2+ | Stage derived from Job |
| `unpublish` | Hiding existing submission | v2+ | Stage derived from Job |

**Released statuses (visibility-based):**
| Status | Meaning | Notes |
|--------|---------|-------|
| `published` | Visible in gencc-search | Released via `new` or `republish` |
| `unpublished` | Hidden from gencc-search | Released via `unpublish` |

### New Job Status Values (3 total, renamed)
```
draft     - Submissions can be added/edited/removed
submitted - Awaiting release processing
released  - All submissions released (renamed from 'processed')
```

### Submission Fields

| Field | Type | Purpose |
|-------|------|---------|
| `status` | enum | `new`, `republish`, `unpublish`, `published`, `unpublished` |
| `action` | enum | `new`, `republish`, `unpublish` - Retained for audit trail |
| `version_number` | int | Version (1, 2, 3...) |
| `is_most_recent` | bool | Highest version for this SID (including drafts) |
| `is_live` | bool | Current released state for this SID |
| `has_errors` | computed | Derived from `submission_errors` |

### Stage Derivation

Stage is derived from parent Job, not stored in submission:
```php
// Stage is derived from parent Job
$stage = $submission->job->status; // 'draft', 'submitted', or 'released'

// Display logic
$displayStatus = match(true) {
    $stage === 'draft' => "Draft (" . ucfirst($submission->status) . ")",
    $stage === 'submitted' => "Submitted (" . ucfirst($submission->status) . ")",
    $stage === 'released' => ucfirst($submission->status), // 'Published' or 'Unpublished'
};
```

### Derived States

| State | Query |
|-------|-------|
| **Pending** | `status IN ('new', 'republish', 'unpublish')` |
| **Released** | `status IN ('published', 'unpublished')` |
| **Live** | `status IN ('published', 'unpublished') AND is_live = true` |
| **Archived** | `released_at IS NOT NULL AND is_live = false` |
| **Draft stage** | `job.status = 'draft'` (via join) |
| **Submitted stage** | `job.status = 'submitted'` (via join) |

---

## State Transition Diagrams

### Job Transitions
```
DRAFT ──────────────► SUBMITTED ──────────────► RELEASED
  │                        │
  └── (delete)             └── (cancel) ───────► DRAFT
```

### Submission Transitions

**New Submission (v1):**
```
[created] ──► new ──────────────────────────────► published
              │                                      │
              │ (Job: draft → submitted → released)  │
              └──────────────────────────────────────┘
```

**Republish (v2+):**
```
published/unpublished ──► republish ──► published (new version)
         │                                   │
         └── previous version ───────────────┴──► is_live=false (archived)
```

**Unpublish (v2+):**
```
published ──► unpublish ──► unpublished (new version)
    │                            │
    └── previous version ────────┴──► is_live=false (archived)
```

---

## Migration Plan (Phased)

### Phase 1: Add Action Field & Rename Job Status

**Migration 1a: Add `action` column to submissions**
```php
Schema::table('submissions', function (Blueprint $table) {
    $table->string('action', 20)->nullable()->after('status');
    $table->index('action');
});
```

**Migration 1b: Backfill `action` from current status**
```sql
-- For pending submissions, action matches the status suffix
UPDATE submissions SET action = 'new'
WHERE status IN ('draft_new', 'submitted_new');

UPDATE submissions SET action = 'republish'
WHERE status IN ('draft_republish', 'submitted_republish');

UPDATE submissions SET action = 'unpublish'
WHERE status IN ('draft_unpublish', 'submitted_unpublish');

-- For released submissions, derive from version_number and status
UPDATE submissions SET action = 'new'
WHERE status = 'published' AND version_number = 1;

UPDATE submissions SET action = 'republish'
WHERE status = 'published' AND version_number > 1;

UPDATE submissions SET action = 'unpublish'
WHERE status = 'unpublished';
```

**Migration 1c: Rename Job status `processed` to `released`**
```sql
UPDATE jobs SET status = 'released' WHERE status = 'processed';
```

**Code updates for Phase 1:**
- Add `Job::STATUS_RELEASED = 'released'`
- Deprecate `Job::STATUS_PROCESSED` (alias to STATUS_RELEASED)
- Add `action` to Submission fillable
- Update JobStateMachine transitions

### Phase 2: Simplify Submission Status Values

**Migration 2a: Update submission status values**
```sql
-- Pending submissions: remove draft_/submitted_ prefix
UPDATE submissions SET status = 'new'
WHERE status IN ('draft_new', 'submitted_new');

UPDATE submissions SET status = 'republish'
WHERE status IN ('draft_republish', 'submitted_republish');

UPDATE submissions SET status = 'unpublish'
WHERE status IN ('draft_unpublish', 'submitted_unpublish');

-- Released submissions: status remains 'published' or 'unpublished' (no change needed)
```

**Code updates for Phase 2:**
- Update Submission status constants:
  ```php
  public const STATUS_NEW = 'new';
  public const STATUS_REPUBLISH = 'republish';
  public const STATUS_UNPUBLISH = 'unpublish';
  public const STATUS_PUBLISHED = 'published';
  public const STATUS_UNPUBLISHED = 'unpublished';
  ```
- Remove old compound status constants
- Update SubmissionStateMachine
- Update all controllers and frontend

### Phase 3: Cleanup & Optimization

**Add composite indexes for common queries:**
```php
Schema::table('submissions', function (Blueprint $table) {
    // For finding pending submissions by job
    $table->index(['job_id', 'status']);

    // For finding live/archived submissions
    $table->index(['sid', 'is_live']);
    $table->index(['is_live', 'status']);
});
```

**Remove deprecated code:**
- Remove legacy status constants
- Remove any remaining compound status references
- Update documentation

---

## Code Changes Summary

### Submission Model
```php
// New constants (Phase 2)
public const STATUS_NEW = 'new';
public const STATUS_REPUBLISH = 'republish';
public const STATUS_UNPUBLISH = 'unpublish';
public const STATUS_PUBLISHED = 'published';
public const STATUS_UNPUBLISHED = 'unpublished';

// Helper methods
public function isPending(): bool
{
    return in_array($this->status, [self::STATUS_NEW, self::STATUS_REPUBLISH, self::STATUS_UNPUBLISH]);
}

public function isReleased(): bool
{
    return in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_UNPUBLISHED]);
}

public function getStageAttribute(): string
{
    return $this->job?->status ?? 'unknown';
}

public function getDisplayStatusAttribute(): string
{
    if ($this->isPending()) {
        $stage = $this->stage === 'draft' ? 'Draft' : 'Submitted';
        return "{$stage} (" . ucfirst($this->status) . ")";
    }
    return ucfirst($this->status);
}
```

### Job Model
```php
// Renamed constant (Phase 1)
public const STATUS_DRAFT = 'draft';
public const STATUS_SUBMITTED = 'submitted';
public const STATUS_RELEASED = 'released';

/** @deprecated Use STATUS_RELEASED */
public const STATUS_PROCESSED = 'released';
```

### SubmissionStateMachine (Simplified)
```php
protected static $transitions = [
    // Pending states
    Submission::STATUS_NEW => [
        Submission::STATUS_PUBLISHED,
        'deleted'
    ],
    Submission::STATUS_REPUBLISH => [
        Submission::STATUS_PUBLISHED,
        'cancelled' // Returns to previous released state
    ],
    Submission::STATUS_UNPUBLISH => [
        Submission::STATUS_UNPUBLISHED,
        'cancelled' // Returns to previous released state
    ],
    // Released states
    Submission::STATUS_PUBLISHED => [
        Submission::STATUS_REPUBLISH,
        Submission::STATUS_UNPUBLISH
    ],
    Submission::STATUS_UNPUBLISHED => [
        Submission::STATUS_REPUBLISH
    ]
];
```

---

## Implementation Checklist

### Phase 1: Add Action Field & Rename Job Status
- [ ] Create migration to add `action` field
- [ ] Backfill `action` from existing status values
- [ ] Create migration to rename Job `processed` → `released`
- [ ] Update Job model constants
- [ ] Update JobStateMachine
- [ ] Update job-related controllers
- [ ] Update job-related frontend
- [ ] Run tests, fix any failures
- [ ] Deploy Phase 1

### Phase 2: Simplify Submission Status
- [ ] Create migration to update submission status values
- [ ] Update Submission model constants
- [ ] Update SubmissionStateMachine
- [ ] Update submission-related controllers
- [ ] Update submission-related frontend
- [ ] Update dashboard queries
- [ ] Run tests, fix any failures
- [ ] Deploy Phase 2

### Phase 3: Cleanup
- [ ] Add composite indexes
- [ ] Remove deprecated constants and code
- [ ] Update documentation
- [ ] Final testing
- [ ] Deploy Phase 3

---

## Mapping: Old Status → New Status

| Old Status | New Status | New Action |
|------------|------------|------------|
| `draft_new` | `new` | `new` |
| `submitted_new` | `new` | `new` |
| `draft_republish` | `republish` | `republish` |
| `submitted_republish` | `republish` | `republish` |
| `draft_unpublish` | `unpublish` | `unpublish` |
| `submitted_unpublish` | `unpublish` | `unpublish` |
| `published` | `published` | `new` (v1) or `republish` (v2+) |
| `unpublished` | `unpublished` | `unpublish` |

## Mapping: Old Job Status → New Job Status

| Old Status | New Status |
|------------|------------|
| `draft` | `draft` |
| `submitted` | `submitted` |
| `processed` | `released` |
