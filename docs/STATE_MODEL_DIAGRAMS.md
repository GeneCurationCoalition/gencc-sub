# Submission and Job State Model - Visual Diagrams

**Version**: 2.0 (String-Based States)
**Last Updated**: November 13, 2025

This document contains Mermaid state diagrams for the submission and job state workflows.

> **For Users**: Looking for a simpler overview? See [STATE_MODEL_USER_GUIDE.md](STATE_MODEL_USER_GUIDE.md)
>
> **This Document**: Complete technical diagrams for all state transitions and workflows
>
> **Note**: These diagrams render in GitHub, GitLab, and any Markdown viewer that supports Mermaid.
> Colors and styling are defined using Mermaid's theming capabilities.

## Table of Contents

- [Complete Submission State Diagram](#complete-submission-state-diagram) - All 8 submission states
- [Complete Job State Diagram](#complete-job-state-diagram) - All 3 job states
- [Combined System Flow](#combined-system-flow) - Jobs + Submissions together
- [Individual Workflows](#submission-lifecycle-by-action) - Detailed flow charts
- [State Transition Matrix](#state-transition-matrix) - Reference table

---

## Combined System Flow

This diagram shows how jobs and submissions work together in the complete workflow.

```mermaid
graph TB
    subgraph Job[Job Lifecycle]
        J1[Create Draft Job]
        J2[Submit Job]
        J3[Process Job run:publish]
        J4[Job Processed]

        J1 -->|Add submissions| J1
        J1 -->|All valid| J2
        J2 -->|Daily cycle| J3
        J3 -->|Success| J4
        J3 -->|All fail| J2
    end

    subgraph New[New Submission Flow]
        S1[draft_new]
        S2[submitted_new]
        S3[published]

        S1 -->|Job submitted| S2
        S2 -->|run:publish| S3
    end

    subgraph Republish[Republish Flow]
        S4[draft_republish]
        S5[submitted_republish]

        S3 -->|Click Edit| S4
        S4 -->|Store origin_snapshot| S4
        S4 -->|Job submitted| S5
        S5 -->|run:publish| S3
        S4 -->|Restore| S3
    end

    subgraph Unpublish[Unpublish Flow]
        S6[draft_unpublish]
        S7[submitted_unpublish]
        S8[unpublished]

        S3 -->|Click Unpublish| S6
        S6 -->|Job submitted| S7
        S7 -->|run:publish| S8
        S6 -->|Restore| S3
        S8 -->|Click Republish| S4
    end

    J1 -.->|Contains| S1
    J2 -.->|Contains| S2
    J4 -.->|Records| REC[processed_submission_ids]

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
    style REC fill:#FEE2E2,stroke:#EF4444,stroke-width:2px
```

**Legend:**

- 🟡 **Yellow** = Draft states (editable)
- 🔵 **Blue** = Submitted states (awaiting processing)
- 🟢 **Green** = Published/Processed (active/complete)
- ⚫ **Gray** = Unpublished (hidden)

---

## Complete Submission State Diagram

This diagram shows all possible state transitions for submissions in the V2 state machine.

```mermaid
stateDiagram-v2
    [*] --> draft_new: Create New Submission

    draft_new --> submitted_new: Submit Job
    draft_new --> [*]: Delete Submission

    submitted_new --> published: Publish Success
    submitted_new --> draft_new: Restore Job

    published --> draft_republish: Click Edit
    published --> draft_unpublish: Click Unpublish

    draft_republish --> submitted_republish: Submit Job
    draft_republish --> published: Restore from published
    draft_republish --> unpublished: Restore from unpublished

    submitted_republish --> published: Publish Success
    submitted_republish --> draft_republish: Restore Job

    draft_unpublish --> submitted_unpublish: Submit Job
    draft_unpublish --> published: Restore

    submitted_unpublish --> unpublished: Unpublish Success
    submitted_unpublish --> draft_unpublish: Restore Job

    unpublished --> draft_republish: Click Republish

    note right of draft_new
        🟡 Draft New
        ━━━━━━━━━━━━━━
        • Editable
        • In draft job
        • Can have errors
        • Stores: origin_job_id
    end note

    note right of submitted_new
        🔵 Submitted New
        ━━━━━━━━━━━━━━━━
        • Not editable
        • In submitted job
        • No errors allowed
        • Awaits daily processing
    end note

    note right of published
        🟢 Published
        ━━━━━━━━━━━━
        • Public visible
        • Not editable
        • Has SGC ID
        • Can republish/unpublish
    end note

    note right of draft_republish
        🟠 Draft Republish
        ━━━━━━━━━━━━━━━━━
        • Editable
        • In draft job
        • Stores: origin_state,
          origin_snapshot,
          origin_job_id
    end note

    note right of draft_unpublish
        🟤 Draft Unpublish
        ━━━━━━━━━━━━━━━━━
        • Read-only
        • In draft job
        • Stores: origin_state,
          origin_job_id
    end note

    note right of unpublished
        ⚫ Unpublished
        ━━━━━━━━━━━━━━
        • Hidden from public
        • Not editable
        • Has SGC ID
        • Can republish
    end note

    classDef draftStyle fill:#FEF3C7,stroke:#F59E0B,stroke-width:2px,color:#000
    classDef submittedStyle fill:#DBEAFE,stroke:#3B82F6,stroke-width:2px,color:#000
    classDef publishedStyle fill:#86EFAC,stroke:#10B981,stroke-width:2px,color:#000
    classDef unpublishedStyle fill:#D1D5DB,stroke:#6B7280,stroke-width:2px,color:#000

    class draft_new,draft_republish,draft_unpublish draftStyle
    class submitted_new,submitted_republish,submitted_unpublish submittedStyle
    class published publishedStyle
    class unpublished unpublishedStyle
```

## New Submission Workflow

```mermaid
stateDiagram-v2
    [*] --> draft_new: Create via UI or Upload

    draft_new --> submitted_new: Job Submitted
    draft_new --> [*]: Delete

    submitted_new --> published: Publish Success
    submitted_new --> draft_new: Job Restored

    note right of draft_new
        Initial State
        - Editable
        - In draft job
        - Has validation
    end note

    note right of submitted_new
        Awaiting Publish
        - Not editable
        - In submitted job
        - No errors allowed
    end note

    note right of published
        Terminal State
        - Public visible
        - Can republish or unpublish
    end note
```

## Republish Workflow (from Published)

```mermaid
stateDiagram-v2
    published --> draft_republish: Click Republish

    draft_republish --> submitted_republish: Job Submitted
    draft_republish --> published: Restore

    submitted_republish --> published: Publish Success
    submitted_republish --> draft_republish: Job Restored

    note right of draft_republish
        Update Mode
        - Editable
        - In draft job
        - origin_state = 'published'
    end note

    note right of submitted_republish
        Awaiting Republish
        - Not editable
        - In submitted job
    end note
```

## Republish Workflow (from Unpublished)

```mermaid
stateDiagram-v2
    unpublished --> draft_republish: Click Republish

    draft_republish --> submitted_republish: Job Submitted
    draft_republish --> unpublished: Restore

    submitted_republish --> published: Publish Success
    submitted_republish --> draft_republish: Job Restored

    note right of draft_republish
        Restore Mode
        - Editable
        - In draft job
        - origin_state = 'unpublished'
    end note

    note right of unpublished
        Hidden State
        - Not public visible
        - Can be restored
    end note
```

## Unpublish Workflow

```mermaid
stateDiagram-v2
    published --> draft_unpublish: Click Unpublish

    draft_unpublish --> submitted_unpublish: Job Submitted
    draft_unpublish --> published: Restore

    submitted_unpublish --> unpublished: Unpublish Success
    submitted_unpublish --> draft_unpublish: Job Restored

    note right of draft_unpublish
        Unpublish Mode
        - NOT editable
        - In draft job
    end note

    note right of submitted_unpublish
        Awaiting Unpublish
        - Not editable
        - In submitted job
    end note

    note right of unpublished
        Hidden State
        - Not public visible
        - Can be republished
    end note
```

## Complete Job State Diagram

This diagram shows all possible state transitions for jobs in the V2 state machine.

```mermaid
stateDiagram-v2
    [*] --> draft: Create Job

    draft --> submitted: Submit Job
    draft --> [*]: Delete Job

    submitted --> processed: Publish Success
    submitted --> submitted: Publish All Failed
    submitted --> draft: Restore Before Processing

    processed --> [*]

    note right of draft
        🟡 Draft
        ━━━━━━━━━━
        • Add/edit/remove submissions
        • All submissions must be draft_xxx
        • Can delete if empty
        • One per submitter limit
    end note

    note right of submitted
        🔵 Submitted
        ━━━━━━━━━━━━━
        • No edits allowed
        • Can restore before run:publish
        • Processed daily automatically
        • Blocks new draft creation
    end note

    note right of processed
        🟢 Processed
        ━━━━━━━━━━━━━
        • Terminal state
        • All submissions processed
        • Cannot be deleted
        • Records processed_submission_ids:
          [{sid, action}]
    end note

    classDef draftStyle fill:#FEF3C7,stroke:#F59E0B,stroke-width:2px,color:#000
    classDef submittedStyle fill:#DBEAFE,stroke:#3B82F6,stroke-width:2px,color:#000
    classDef processedStyle fill:#86EFAC,stroke:#10B981,stroke-width:2px,color:#000

    class draft draftStyle
    class submitted submittedStyle
    class processed processedStyle
```

## Job State with Failure Handling

```mermaid
stateDiagram-v2
    [*] --> draft: Create Job

    draft --> submitted: Submit

    submitted --> processing: Publish Starts

    processing --> processed: All Success
    processing --> failed_partial: Some Failed
    processing --> submitted: All Failed

    failed_partial --> processed: Mark Original
    failed_partial --> draft: Create New Job with Failures

    note right of processing
        During run:publish
        - Processing submissions
        - Validating data
        - Sending to GenCC-Search
    end note

    note right of failed_partial
        Partial Failure
        - Successful submissions → processed
        - Failed submissions → new draft job
        - Failed submissions reset to draft_xxx
    end note
```

## Submission Lifecycle by Action

### Create New Submission

```mermaid
flowchart TD
    A[User Creates Submission] --> B{Has Errors?}
    B -->|Yes| C[draft_new with errors]
    B -->|No| D[draft_new no errors]

    C --> E[User Fixes Errors]
    E --> D

    D --> F[User Submits Job]
    F --> G[submitted_new]

    G --> H[run:publish Executes]
    H --> I{Success?}
    I -->|Yes| J[published]
    I -->|No| K[Back to draft_new with errors]
```

### Republish Existing Submission

```mermaid
flowchart TD
    A[published submission] --> B[User clicks Republish]
    B --> C[Move to draft job]
    C --> D[draft_republish state]
    D --> E{Store origin data}
    E --> F[origin_state = 'published']
    E --> G[origin_snapshot = field values]
    E --> H[origin_job_id = original job]

    H --> I[User Edits]
    I --> J{User Action?}

    J -->|Submit| K[Job Submitted]
    K --> L[submitted_republish]
    L --> M[run:publish]
    M --> N[published + processed_submission_ids]

    J -->|Restore| O[Revert all changes]
    O --> P[Restore from origin_snapshot]
    O --> Q[Back to published state]
    O --> R[Back to original_job_id]
```

### Unpublish Submission

```mermaid
flowchart TD
    A[published submission] --> B[User clicks Unpublish]
    B --> C[Move to draft job]
    C --> D[draft_unpublish state - READ ONLY]
    D --> E{Store origin data}
    E --> F[origin_state = 'published']
    E --> G[origin_job_id = original job]

    G --> H{User Action?}

    H -->|Submit| I[Job Submitted]
    I --> J[submitted_unpublish]
    J --> K[run:publish unpublish]
    K --> L[unpublished + processed_submission_ids]

    H -->|Restore| M[Revert]
    M --> N[Back to published]
    M --> O[Back to original job]
```

## Job Lifecycle

```mermaid
flowchart TD
    A[Create Job] --> B[draft state]

    B --> C[Add Submissions]
    C --> D{All Valid?}
    D -->|No| E[Fix Errors]
    E --> D
    D -->|Yes| F[Ready to Submit]

    F --> G[User Submits]
    G --> H[submitted state]

    H --> I{run:publish Scheduled?}
    I -->|Not Yet| J{User Restores?}
    J -->|Yes| K[Restore Job]
    K --> B

    I -->|Yes| L[run:publish Executes Daily]
    L --> M{Results?}

    M -->|All Success| N[processed state]
    N --> O[Record processed_submission_ids]
    M -->|All Fail| P[Stay submitted with errors]
    M -->|Partial| Q[Split]

    Q --> R[Successful → processed]
    R --> O
    Q --> S[Failed → new draft job]
```

## State Transition Matrix

### Submission States

| From State | To State(s) | Trigger | Validation |
|------------|------------|---------|------------|
| `draft_new` | `submitted_new` | Job submitted | No errors |
| `draft_new` | DELETED | User deletes | In draft job |
| `submitted_new` | `published` | run:publish success | - |
| `submitted_new` | `draft_new` | Job restored | Before run:publish |
| `published` | `draft_republish` | User republishes | - |
| `published` | `draft_unpublish` | User unpublishes | - |
| `draft_republish` | `submitted_republish` | Job submitted | No errors |
| `draft_republish` | `published` | User restores | origin_state='published' |
| `draft_republish` | `unpublished` | User restores | origin_state='unpublished' |
| `submitted_republish` | `published` | run:publish success | - |
| `submitted_republish` | `draft_republish` | Job restored | Before run:publish |
| `draft_unpublish` | `submitted_unpublish` | Job submitted | - |
| `draft_unpublish` | `published` | User restores | - |
| `submitted_unpublish` | `unpublished` | run:publish success | - |
| `submitted_unpublish` | `draft_unpublish` | Job restored | Before run:publish |
| `unpublished` | `draft_republish` | User republishes | - |

### Job States

| From State | To State(s) | Trigger | Validation |
|------------|------------|---------|------------|
| `draft` | `submitted` | User submits | All submissions valid, no errors |
| `draft` | DELETED | User deletes | Can delete if empty or only draft submissions |
| `submitted` | `processed` | run:publish success | At least one submission succeeds |
| `submitted` | `submitted` | run:publish failure | All submissions fail |
| `submitted` | `draft` | User restores | Before run:publish starts |

### Job Processing Actions

When a job transitions to `processed` state, the following data is recorded:

- **processed_submission_ids**: Array of objects tracking each processed submission
  - Structure: `[{"sid": "SGC-12345", "action": "published|republished|unpublished"}]`
  - Actions:
    - `published`: New submission (submitted_new → published)
    - `republished`: Updated submission (submitted_republish → published)
    - `unpublished`: Removed submission (submitted_unpublish → unpublished)
- This data persists even if submissions are moved to other jobs

## Color Coding Guide

Actual implementation uses the following color scheme:

### Submission States (Tag Colors)
- `draft_new`: **Light Orange** (#FEF3C7) - New submission in draft
- `submitted_new`: **Light Blue** (#DBEAFE) - New submission processing
- `published`: **Green** (#86EFAC) - Live/active submission
- `draft_republish`: **Medium Orange** (#FDE68A) - Editing published submission
- `submitted_republish`: **Medium Blue** (#BFDBFE) - Update processing
- `draft_unpublish`: **Dark Orange** (#FBBF24) - Pending removal (read-only)
- `submitted_unpublish`: **Dark Blue** (#60A5FA) - Unpublish processing
- `unpublished`: **Dark Gray** (#3F3F46) - Removed/hidden

### Submission Row Colors
- **Normal**: White background (#ffffff)
- **With Errors**: Light red background (#fef2f2)
- Error indicator: Red warning triangle icon

### Job States
- `draft`: **Orange/Yellow** tag - Work in progress
- `submitted`: **Blue** tag - Awaiting daily processing
- `processed`: **Green** tag - Completed processing

### Job Header Colors (Detail View)
- `draft`: Yellow header (bg-yellow-500)
- `submitted`: Blue header (bg-blue-500)
- `processed`: Green header (bg-green-500)

## Icon Recommendations

### Submission Actions (Actual Implementation)
- **Create New**: Not specific icon, part of job creation
- **Edit (Republish)**: `pi-pencil` (pencil icon)
- **Unpublish**: `pi-eye-slash` (eye-slash icon)
- **Restore**: `pi-times-circle` (times-circle icon) - Used for cancel/restore
- **View/Edit**: `pi-arrow-right` (arrow icon)
- **Submit Job**: Button with "Submit" text
- **Delete**: `pi-trash`

### Submission Error Indicators
- **Has Errors**: `pi-exclamation-triangle` (red warning triangle)

### Job Error Indicators
- **Draft with Errors**: `pi-exclamation-triangle` (red warning triangle in status column)

## Glossary

- **Draft State** (`draft_xxx`): Submission can be edited (except draft_unpublish), belongs to a draft job
- **Submitted State** (`submitted_xxx`): Submission locked, awaiting run:publish processing
- **Terminal State**: Final state that cannot transition (except `published` and `unpublished` which can be republished)
- **Origin State** (`origin_state`): Stored state for restore operations (published/unpublished)
- **Origin Snapshot** (`origin_snapshot`): JSON snapshot of all editable field values before entering draft_republish or draft_unpublish state
- **Origin Job** (`origin_job_id`): Foreign key to the job the submission belonged to before being moved to a draft job
- **Processed Submission IDs** (`processed_submission_ids`): Array tracking which submissions were processed by a job with their action type
- **run:publish**: Backend command that processes submitted jobs daily and publishes to GenCC-Search
- **Restore**: Action to cancel draft changes and return submission to its original state, job, and field values
- **Republish**: Action to edit and re-publish an already published submission
- **Unpublish**: Action to remove a published submission from public view

---

## Related Documentation

- [STATE_MODEL_USER_GUIDE.md](STATE_MODEL_USER_GUIDE.md) - User-friendly guide with simple visual workflows
- [STATE_MODEL_QUICK_REFERENCE.md](STATE_MODEL_QUICK_REFERENCE.md) - Technical reference with code examples
- [DASHBOARD_TECHNICAL_GUIDE.md](DASHBOARD_TECHNICAL_GUIDE.md) - Dashboard implementation details

---

**Version**: 2.0
**Last Updated**: November 13, 2025
