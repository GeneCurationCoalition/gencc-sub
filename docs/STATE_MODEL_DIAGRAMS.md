# Submission and Job State Model - Visual Diagrams

**Version**: 3.0 (Simplified Status Model)
**Last Updated**: February 2026

This document contains Mermaid state diagrams for the submission and job state workflows.

> **For Users**: Looking for a simpler overview? See [STATE_MODEL_USER_GUIDE.md](STATE_MODEL_USER_GUIDE.md)
>
> **This Document**: Complete technical diagrams for all state transitions and workflows
>
> **Note**: These diagrams render in GitHub, GitLab, and any Markdown viewer that supports Mermaid.

## Table of Contents

- [Simplified State Model Overview](#simplified-state-model-overview) - Key concepts
- [Complete Submission State Diagram](#complete-submission-state-diagram) - All 5 submission statuses
- [Complete Job State Diagram](#complete-job-state-diagram) - All 3 job states
- [Combined System Flow](#combined-system-flow) - Jobs + Submissions together
- [Individual Workflows](#submission-lifecycle-by-action) - Detailed flow charts
- [State Transition Matrix](#state-transition-matrix) - Reference table

---

## Simplified State Model Overview

### Key Concepts

The V3 state model simplifies submission status from 8 states to **5 statuses**:

1. **Stage is derived from Job.status**, not stored in submission
   - Submission's `status` field only tracks the action/visibility
   - Whether a submission is in "draft" or "submitted" stage comes from the parent Job

2. **Pending statuses (action-based)**:
   - `new` - New submission awaiting first release
   - `republish` - Update to existing submission awaiting release
   - `unpublish` - Submission marked for unpublishing

3. **Released statuses (visibility-based)**:
   - `published` - Published and visible to public
   - `unpublished` - Unpublished and hidden from public

### Status vs Stage

| Submission Status | Job Status = draft | Job Status = submitted |
|-------------------|-------------------|------------------------|
| `new` | Draft new | Submitted new |
| `republish` | Draft republish | Submitted republish |
| `unpublish` | Draft unpublish | Submitted unpublish |
| `published` | N/A (not in job) | N/A (not in job) |
| `unpublished` | N/A (not in job) | N/A (not in job) |

---

## Combined System Flow

This diagram shows how jobs and submissions work together in the complete workflow.

```mermaid
graph TB
    subgraph Job[Job Lifecycle]
        J1[Create Draft Job]
        J2[Submit Job]
        J3[Process Job run:publish]
        J4[Job Released]

        J1 -->|Add submissions| J1
        J1 -->|All valid| J2
        J2 -->|Daily cycle| J3
        J3 -->|Success| J4
        J3 -->|All fail| J2
    end

    subgraph New[New Submission Flow]
        S1[status: new<br>stage: draft]
        S2[status: new<br>stage: submitted]
        S3[status: published]

        S1 -->|Job submitted| S2
        S2 -->|run:publish| S3
    end

    subgraph Republish[Republish Flow]
        S4[status: republish<br>stage: draft]
        S5[status: republish<br>stage: submitted]

        S3 -->|Click Edit| S4
        S4 -->|Job submitted| S5
        S5 -->|run:publish| S3
        S4 -->|Cancel| S3
    end

    subgraph Unpublish[Unpublish Flow]
        S6[status: unpublish<br>stage: draft]
        S7[status: unpublish<br>stage: submitted]
        S8[status: unpublished]

        S3 -->|Click Unpublish| S6
        S6 -->|Job submitted| S7
        S7 -->|run:publish| S8
        S6 -->|Cancel| S3
        S8 -->|Click Republish| S4
    end

    J1 -.->|Contains| S1
    J2 -.->|Contains| S2

    style J1 fill:#FEF3C7,stroke:#F59E0B,stroke-width:2px
    style J2 fill:#DBEAFE,stroke:#3B82F6,stroke-width:2px
    style J3 fill:#BFDBFE,stroke:#3B82F6,stroke-width:2px
    style J4 fill:#86EFAC,stroke:#10B981,stroke-width:2px
    style S1 fill:#FEF3C7,stroke:#F59E0B,stroke-width:2px
    style S2 fill:#DBEAFE,stroke:#3B82F6,stroke-width:2px
    style S3 fill:#86EFAC,stroke:#10B981,stroke-width:2px
    style S4 fill:#FDE68A,stroke:#F59E0B,stroke-width:2px
    style S5 fill:#BFDBFE,stroke:#3B82F6,stroke-width:2px
    style S6 fill:#FBBF24,stroke:#F59E0B,stroke-width:2px
    style S7 fill:#60A5FA,stroke:#3B82F6,stroke-width:2px
    style S8 fill:#D1D5DB,stroke:#6B7280,stroke-width:2px
```

**Legend:**

- 🟡 **Yellow** = Draft stage (editable)
- 🔵 **Blue** = Submitted stage (awaiting processing)
- 🟢 **Green** = Published/Released (active/complete)
- ⚫ **Gray** = Unpublished (hidden)

---

## Complete Submission State Diagram

This diagram shows all possible state transitions for submissions in the V3 (simplified) state machine.

```mermaid
stateDiagram-v2
    [*] --> new: Create New Submission

    new --> published: Release (via run:publish)
    new --> [*]: Delete Submission

    published --> republish: Click Edit
    published --> unpublish: Click Unpublish

    republish --> published: Release Success
    republish --> published: Cancel (was published)
    republish --> unpublished: Cancel (was unpublished)

    unpublish --> unpublished: Release Success
    unpublish --> published: Cancel

    unpublished --> republish: Click Republish

    note right of new
        🟡/🔵 New
        ━━━━━━━━━━━━━━
        • Pending status
        • Stage from Job.status
        • Editable in draft stage
        • Can have errors
    end note

    note right of published
        🟢 Published
        ━━━━━━━━━━━━
        • Released status
        • Public visible
        • Not editable
        • Has SGC ID
        • Can republish/unpublish
    end note

    note right of republish
        🟠/🔵 Republish
        ━━━━━━━━━━━━━━━━━
        • Pending status
        • Stage from Job.status
        • Editable in draft stage
        • Stores origin_state
    end note

    note right of unpublish
        🟤/🔵 Unpublish
        ━━━━━━━━━━━━━━━━━
        • Pending status
        • Stage from Job.status
        • NOT editable (read-only)
        • Stores origin_state
    end note

    note right of unpublished
        ⚫ Unpublished
        ━━━━━━━━━━━━━━
        • Released status
        • Hidden from public
        • Not editable
        • Has SGC ID
        • Can republish
    end note

    classDef pendingStyle fill:#FEF3C7,stroke:#F59E0B,stroke-width:2px,color:#000
    classDef releasedStyle fill:#86EFAC,stroke:#10B981,stroke-width:2px,color:#000
    classDef unpublishedStyle fill:#D1D5DB,stroke:#6B7280,stroke-width:2px,color:#000

    class new,republish,unpublish pendingStyle
    class published releasedStyle
    class unpublished unpublishedStyle
```

## New Submission Workflow

```mermaid
stateDiagram-v2
    [*] --> new: Create via UI or Upload

    state new {
        draft_stage --> submitted_stage: Job Submitted
        submitted_stage --> draft_stage: Job Cancelled
    }

    new --> published: Release Success
    new --> [*]: Delete

    note right of new
        Pending Status: new
        - Stage derived from Job.status
        - Editable when Job.status = draft
        - Immutable when Job.status = submitted
    end note

    note right of published
        Released Status
        - Public visible
        - Can republish or unpublish
    end note
```

## Republish Workflow

```mermaid
stateDiagram-v2
    published --> republish: Click Republish
    unpublished --> republish: Click Republish

    state republish {
        draft_stage --> submitted_stage: Job Submitted
        submitted_stage --> draft_stage: Job Cancelled
    }

    republish --> published: Release Success
    republish --> published: Cancel (origin_state=published)
    republish --> unpublished: Cancel (origin_state=unpublished)

    note right of republish
        Pending Status: republish
        - origin_state tracks where to restore
        - Editable when Job.status = draft
        - Immutable when Job.status = submitted
    end note
```

## Unpublish Workflow

```mermaid
stateDiagram-v2
    published --> unpublish: Click Unpublish

    state unpublish {
        draft_stage --> submitted_stage: Job Submitted
        submitted_stage --> draft_stage: Job Cancelled
    }

    unpublish --> unpublished: Release Success
    unpublish --> published: Cancel

    note right of unpublish
        Pending Status: unpublish
        - NOT editable (read-only)
        - Only action is submit or cancel
    end note

    note right of unpublished
        Released Status
        - Hidden from public
        - Can be republished
    end note
```

## Complete Job State Diagram

This diagram shows all possible state transitions for jobs in the V3 state machine.

```mermaid
stateDiagram-v2
    [*] --> draft: Create Job

    draft --> submitted: Submit Job
    draft --> [*]: Delete Job

    submitted --> released: Release Success
    submitted --> submitted: Release All Failed
    submitted --> draft: Cancel Before Processing

    released --> [*]

    note right of draft
        🟡 Draft
        ━━━━━━━━━━
        • Add/edit/remove submissions
        • All submissions editable
        • Can delete if appropriate
        • One per submitter limit
    end note

    note right of submitted
        🔵 Submitted
        ━━━━━━━━━━━━━
        • No edits allowed
        • Can cancel before run:publish
        • Processed by scheduled job
        • Submissions immutable
    end note

    note right of released
        🟢 Released
        ━━━━━━━━━━━━━
        • Terminal state
        • All submissions released
        • Cannot be deleted
        • Tracks released_at timestamp
    end note

    classDef draftStyle fill:#FEF3C7,stroke:#F59E0B,stroke-width:2px,color:#000
    classDef submittedStyle fill:#DBEAFE,stroke:#3B82F6,stroke-width:2px,color:#000
    classDef releasedStyle fill:#86EFAC,stroke:#10B981,stroke-width:2px,color:#000

    class draft draftStyle
    class submitted submittedStyle
    class released releasedStyle
```

## Job State with Failure Handling

```mermaid
stateDiagram-v2
    [*] --> draft: Create Job

    draft --> submitted: Submit

    submitted --> processing: Release Starts

    processing --> released: All Success
    processing --> failed_partial: Some Failed
    processing --> submitted: All Failed

    failed_partial --> released: Mark Original
    failed_partial --> draft: Create New Job with Failures

    note right of processing
        During run:publish
        - Processing submissions
        - Validating data
        - Sending to GenCC-Search
    end note

    note right of failed_partial
        Partial Failure
        - Successful submissions → released
        - Failed submissions → new draft job
        - Failed submissions keep pending status
    end note
```

## Submission Lifecycle by Action

### Create New Submission

```mermaid
flowchart TD
    A[User Creates Submission] --> B{Has Errors?}
    B -->|Yes| C[status: new, has_errors: true]
    B -->|No| D[status: new, no errors]

    C --> E[User Fixes Errors]
    E --> D

    D --> F[User Submits Job]
    F --> G[Job.status: submitted]
    G --> H[Submission immutable]

    H --> I[run:publish Executes]
    I --> J{Success?}
    J -->|Yes| K[status: published]
    J -->|No| L[Move to new draft job]
```

### Republish Existing Submission

```mermaid
flowchart TD
    A[published submission] --> B[User clicks Republish]
    B --> C[Move to draft job]
    C --> D[status: republish]
    D --> E{Store origin data}
    E --> F[origin_state = 'published']

    F --> G[User Edits]
    G --> H{User Action?}

    H -->|Submit| I[Job Submitted]
    I --> J[Job.status: submitted]
    J --> K[run:publish]
    K --> L[status: published]

    H -->|Cancel| M[Revert all changes]
    M --> N[Back to published status]
    M --> O[Back to original job]
```

### Unpublish Submission

```mermaid
flowchart TD
    A[published submission] --> B[User clicks Unpublish]
    B --> C[Move to draft job]
    C --> D[status: unpublish - READ ONLY]
    D --> E{Store origin data}
    E --> F[origin_state = 'published']

    F --> G{User Action?}

    G -->|Submit| H[Job Submitted]
    H --> I[Job.status: submitted]
    I --> J[run:publish]
    J --> K[status: unpublished]

    G -->|Cancel| L[Revert]
    L --> M[Back to published]
    L --> N[Back to original job]
```

## Job Lifecycle

```mermaid
flowchart TD
    A[Create Job] --> B[status: draft]

    B --> C[Add Submissions]
    C --> D{All Valid?}
    D -->|No| E[Fix Errors]
    E --> D
    D -->|Yes| F[Ready to Submit]

    F --> G[User Submits]
    G --> H[status: submitted]
    H --> I[Submissions immutable]

    I --> J{run:publish Scheduled?}
    J -->|Not Yet| K{User Cancels?}
    K -->|Yes| L[Cancel Job]
    L --> B

    J -->|Yes| M[run:publish Executes]
    M --> N{Results?}

    N -->|All Success| O[status: released]
    O --> P[Record released_at]
    N -->|All Fail| Q[Stay submitted with errors]
    N -->|Partial| R[Split]

    R --> S[Successful → released]
    S --> P
    R --> T[Failed → new draft job]
```

## State Transition Matrix

### Submission Statuses

| From Status | To Status(es) | Trigger | Validation |
|-------------|---------------|---------|------------|
| `new` | `published` | run:publish success | In submitted job |
| `new` | DELETED | User deletes | In draft job |
| `published` | `republish` | User republishes | - |
| `published` | `unpublish` | User unpublishes | - |
| `republish` | `published` | run:publish success | In submitted job |
| `republish` | `published` | User cancels | origin_state='published' |
| `republish` | `unpublished` | User cancels | origin_state='unpublished' |
| `unpublish` | `unpublished` | run:publish success | In submitted job |
| `unpublish` | `published` | User cancels | - |
| `unpublished` | `republish` | User republishes | - |

### Job States

| From State | To State(s) | Trigger | Validation |
|------------|-------------|---------|------------|
| `draft` | `submitted` | User submits | All submissions valid, no errors |
| `draft` | DELETED | User deletes | Can delete if empty or only pending submissions |
| `submitted` | `released` | run:publish success | At least one submission succeeds |
| `submitted` | `submitted` | run:publish failure | All submissions fail |
| `submitted` | `draft` | User cancels | Before run:publish starts |

### Stage Derivation

The submission's "stage" (draft vs submitted) is determined by its parent Job:

| Job.status | Pending Submission Stage | Can Edit Submission? |
|------------|--------------------------|---------------------|
| `draft` | Draft | ✅ Yes (except unpublish) |
| `submitted` | Submitted | ❌ No (immutable) |
| `released` | N/A | N/A (submissions are released) |

## Color Coding Guide

### Submission Statuses

| Status | Color | Description |
|--------|-------|-------------|
| `new` (draft) | **Light Orange** (#FEF3C7) | New submission in draft job |
| `new` (submitted) | **Light Blue** (#DBEAFE) | New submission awaiting release |
| `republish` (draft) | **Medium Orange** (#FDE68A) | Editing published submission |
| `republish` (submitted) | **Medium Blue** (#BFDBFE) | Update awaiting release |
| `unpublish` (draft) | **Dark Orange** (#FBBF24) | Pending removal (read-only) |
| `unpublish` (submitted) | **Dark Blue** (#60A5FA) | Unpublish awaiting release |
| `published` | **Green** (#86EFAC) | Live/active submission |
| `unpublished` | **Dark Gray** (#3F3F46) | Removed/hidden |

### Job States

| State | Color | Description |
|-------|-------|-------------|
| `draft` | **Orange/Yellow** | Work in progress |
| `submitted` | **Blue** | Awaiting daily processing |
| `released` | **Green** | Completed processing |

## Glossary

- **Pending Status** (`new`, `republish`, `unpublish`): Submission awaiting release
- **Released Status** (`published`, `unpublished`): Submission has been released
- **Stage**: Derived from Job.status - either "draft" (editable) or "submitted" (immutable)
- **Origin State** (`origin_state`): Stored status for cancel operations (published/unpublished)
- **Immutable**: Cannot have fields edited - either released or in submitted job
- **run:publish**: Backend command that processes submitted jobs and releases submissions
- **Cancel**: Action to cancel draft changes and return submission to its original status and job
- **Republish**: Action to edit and re-publish an already published submission
- **Unpublish**: Action to remove a published submission from public view

---

## Related Documentation

- [STATE_MODEL_USER_GUIDE.md](STATE_MODEL_USER_GUIDE.md) - User-friendly guide with simple visual workflows
- [STATE_MODEL_QUICK_REFERENCE.md](STATE_MODEL_QUICK_REFERENCE.md) - Technical reference with code examples

---

**Version**: 3.0
**Last Updated**: February 2026
