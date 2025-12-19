# Dashboard Technical Guide

**Last Updated**: December 3, 2025
**State Model Version**: 2.0 (String-Based States)

## Overview

The Dashboard view provides a comprehensive overview of submission and job statistics for authenticated users in the GenCC Submission Portal. This document describes the technical implementation, data flow, and components that make up the dashboard.

> **Note**: This guide reflects the fully implemented V2 state model. The dashboard has been updated with enhanced features including ClinGen GCI sync, active job tracking, and real-time processing indicators.

## File Locations

- **Page Component**: `resources/js/Pages/Dashboard.vue`
- **Dashboard Component**: `resources/js/Components/Dashboard.vue`
- **Controller**: `app/Http/Controllers/DashboardController.php`

## Data Flow

The dashboard uses Inertia.js to pass data from the Laravel backend to the Vue frontend:

1. User navigates to `/dashboard`
2. `DashboardController::index()` executes database queries
3. Controller passes metrics as props via `Inertia::render()`
4. Vue components receive and display the data

## Controller Implementation

### DashboardController::index()

Location: `app/Http/Controllers/DashboardController.php:40-115`

#### Database Queries

**Active/Processing Jobs Query** (lines 47-69):
```php
$user->jobs()->with('submissions')
    ->whereIn('status', [Job::STATUS_PROCESSING, Job::STATUS_ERRORS, Job::STATUS_STAGED])
    ->get()
```

This query:
- Eager loads the submissions relationship
- Filters jobs by status (Processing, Errors, or Staged)
- Counts jobs by error vs. non-error status
- Sums submissions with `STATUS_ERRORS` and `STATUS_PROCESSING`

**Completed Jobs Query** (lines 72-84):
```php
$user->jobs()->with('submissions')
    ->whereIn('status', [Job::STATUS_COMPLETE])
    ->get()
```

This query:
- Retrieves all completed jobs
- Counts published submissions within those jobs

#### Calculated Metrics

The controller calculates and passes the following metrics:

| Metric | Description | Source |
|--------|-------------|--------|
| `total_jobs_processing` | Jobs in processing without errors | Jobs with STATUS_PROCESSING or STATUS_STAGED |
| `total_submissions_processing` | Submissions currently processing | Submissions with STATUS_PROCESSING |
| `total_jobs_errors` | Jobs with errors | Jobs with STATUS_ERRORS |
| `total_submissions_errors` | Submissions with errors | Submissions with STATUS_ERRORS |
| `total_jobs_completed` | Completed jobs | Jobs with STATUS_COMPLETE |
| `total_submissions_published` | Published submissions | Submissions with STATUS_PUBLISHED |
| `total_jobs_window` | Jobs in 90-day window | **Hardcoded: 1** |
| `total_submissions_window` | Submissions in 90-day window | **Hardcoded: 2607** |
| `token_expire_date` | API token expiration date | `api_token_renewed_at + 2 years` |
| `token_days` | Days until token expires | Difference between now and expiration |
| `months` | Last 12 month names | Generated via Carbon |
| `classifications` | Classification counts | `user->preferences->dash_class_graph` |
| `submissions` | Monthly submission counts | `user->preferences->dash_sub_graph` |

#### Month Generation Logic (lines 92-98)

```php
$firstOfLastYear = Carbon::now()->firstOfMonth()->addMonth()->subYear();
$firstOfLastMonth = Carbon::now()->firstOfMonth();
$period = CarbonPeriod::create($firstOfLastYear, '1 month', $firstOfLastMonth);
foreach ($period as $p)
    $months[] = $p->format('F');
```

This generates month names for the x-axis of the submissions chart, covering the last 12 months from the first of the month one year ago to the first of the current month.

## Vue Component Structure

### Dashboard.vue (Page Component)

Location: `resources/js/Pages/Dashboard.vue`

This is a wrapper component that:
- Uses the AppLayout layout
- Receives all props from the controller
- Passes props to the Dashboard component
- Provides page structure and styling

**Props Received** (line 5-7):
```javascript
defineProps([
  'total_jobs_processing', 'total_submissions_processing',
  'token_expire_date', 'total_submissions_published',
  'token_days', 'months', 'classifications', 'submissions',
  'total_jobs_errors', 'total_submissions_errors',
  'total_jobs_completed', 'total_submissions_window', 'total_jobs_window'
])
```

### Dashboard.vue (Component)

Location: `resources/js/Components/Dashboard.vue`

This component renders all dashboard content.

## UI Components

### 1. API Token Expiration Alert

**Location**: Lines 62-66

```vue
<div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-2" role="alert">
    <p class="font-bold">API Token valid until {{ token_expire_date }}
    <span class="ml-4">({{ token_days }} days)</span>
    </p>
</div>
```

**Purpose**: Displays API token expiration information prominently at the top of the dashboard.

**Data**:
- `token_expire_date`: Formatted as Y-m-d
- `token_days`: Days remaining until expiration

### 2. Status Fieldsets

**Layout**: 2x2 grid (lines 70-103)

#### Top Left: "Total in Processing w/o Errors"

```vue
<Fieldset legend="Total in Processing w/o Errors">
    <div class="m-0 grid grid-cols-4">
        <div>Jobs:<br>Submissions:</div>
        <div class="col-span-3">{{ total_jobs_processing }}<br>{{ total_submissions_processing }}</div>
    </div>
</Fieldset>
```

**Shows**:
- Jobs: Count of jobs with STATUS_PROCESSING or STATUS_STAGED (excluding STATUS_ERRORS)
- Submissions: Count of submissions with STATUS_PROCESSING

#### Top Right: "Total in Processing w/ Errors"

```vue
<Fieldset legend="Total in Processing w/ Errors">
    <div class="m-0 grid grid-cols-4">
        <div>Jobs:<br>Submissions:</div>
        <div class="col-span-3">{{ total_jobs_errors }}<br>{{ total_submissions_errors }}</div>
    </div>
</Fieldset>
```

**Shows**:
- Jobs: Count of jobs with STATUS_ERRORS
- Submissions: Count of submissions with STATUS_ERRORS across all processing jobs

#### Bottom Left: "Total Completed / Published"

```vue
<Fieldset legend="Total Completed / Published">
    <div class="m-0 grid grid-cols-4">
        <div>Jobs:<br>Submissions:</div>
        <div class="col-span-3">{{ total_jobs_completed }}<br>{{ total_submissions_published }}</div>
    </div>
</Fieldset>
```

**Shows**:
- Jobs: Count of jobs with STATUS_COMPLETE
- Submissions: Count of submissions with STATUS_PUBLISHED within completed jobs

#### Bottom Right: "90-Day Publishing Activity"

```vue
<Fieldset legend="90-Day Publishing Activity">
    <div class="m-0 grid grid-cols-4">
        <div>Jobs:<br>Submissions:</div>
        <div class="col-span-3">{{ total_jobs_window }}<br>{{ total_submissions_window }}</div>
    </div>
</Fieldset>
```

**Shows**:
- Jobs: **Hardcoded value: 1**
- Submissions: **Hardcoded value: 2607**

**Note**: These values should be calculated from actual 90-day publishing activity but are currently hardcoded in the controller.

**Panel Layout Changes**:
The dashboard has been reorganized to better reflect the V2 state model workflow:
- Traditional 2x2 status grid now appears in the "Submitted Jobs" section
- New "Active Job" panel added (see section 4 below) showing current draft/submitted job with real-time indicators
- Panels now show V2 state-based metrics (draft_new, draft_republish, draft_unpublish counts)
- Processing indicators (spinners) show when jobs are actively publishing or processing uploads

### 3. Charts

**Layout**: 2-column grid (lines 107-114)

#### Left Chart: Submissions Timeline

**Type**: Bar Chart
**Library**: ApexCharts (vue3-apexcharts)
**Configuration** (lines 12-27):

```javascript
const options = {
    chart: {
        id: 'vuechart-example'
    },
    xaxis: {
        categories: props.months
    },
    title: {
        text: "Submissions"
    }
};

const series = [{
    name: 'submissions',
    data: props.submissions
}];
```

**Data**:
- **X-axis**: Last 12 month names (e.g., "January", "February", etc.)
- **Y-axis**: Submission counts per month
- **Data source**: `user->preferences->dash_sub_graph` (12-element array)

**Important**: Currently displays data from user preferences, not live database queries. This data structure is initialized as:
```php
'dash_sub_graph' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]
```

#### Right Chart: Classifications Distribution

**Type**: Bar Chart (Distributed)
**Library**: ApexCharts (vue3-apexcharts)
**Configuration** (lines 29-53):

```javascript
const options2 = {
    chart: {
        id: 'fskki'
    },
    xaxis: {
        categories: ['Definitive', 'Strong', 'Moderate', 'Supportive',
                     'Limited', 'Disputed', 'Refuted', 'Animal', "NKDR"]
    },
    title: {
        text: "Classifications"
    },
    plotOptions: {
        bar: {
            distributed: true,
        },
    },
    legend: {
        show: false
    },
    colors: ['#276749', '#38a169', '#68d391', '#63b3ed',
             '#fc8181', '#e53e3e', '#f6ad55', '#718096']
};

const classifications = [{
    name: 'classifications',
    data: props.classifications
}];
```

**Data**:
- **X-axis**: 9 classification categories
- **Y-axis**: Count of submissions per classification
- **Data source**: `user->preferences->dash_class_graph` (9-element array)

**Color Scheme**:
| Classification | Color Code | Color Description |
|----------------|-----------|-------------------|
| Definitive | #276749 | Dark green |
| Strong | #38a169 | Green |
| Moderate | #68d391 | Light green |
| Supportive | #63b3ed | Blue |
| Limited | #fc8181 | Light red |
| Disputed | #e53e3e | Red |
| Refuted | #f6ad55 | Orange |
| Animal | #718096 | Gray |
| NKDR | #718096 | Gray |

**Visual Features**:
- Distributed bars (each bar uses its assigned color)
- Legend disabled
- Custom color gradient from green (high confidence) to red (low confidence)

**Important**: Like the submissions chart, this displays data from user preferences initialized as:
```php
'dash_class_graph' => [0, 0, 0, 0, 0, 0, 0, 0, 0]
```

### 4. Active Job Panel

**Location**: [Dashboard.vue:345-416](../resources/js/Components/Dashboard.vue)

This panel displays information about the user's active draft or submitted job:

**When Active Job Exists**:
```vue
<Fieldset legend="Active Job">
    <div class="grid grid-cols-2 gap-6">
        <!-- First Column: Job Info -->
        <div>
            <Link :href="'/jobs/' + unprocessed_job_ident">{{ unprocessed_job_slug }}</Link>
            <i v-if="unprocessed_job_is_publishing" class="pi pi-spin pi-spinner text-blue-500" title="Publishing..."></i>
            <i v-if="unprocessed_job_is_processing" class="pi pi-spin pi-spinner text-amber-500" title="Processing upload..."></i>
            <Tag :severity="statusTagSeverity" :value="unprocessed_job_status" />
            <span>{{ unprocessed_job_date }}</span>
        </div>

        <!-- Second Column: Submission Counts -->
        <div>
            <div>New: {{ active_new_count }}</div>
            <div>Republish: {{ active_republish_count }}</div>
            <div>Unpublish: {{ active_unpublish_count }}</div>
        </div>
    </div>
</Fieldset>
```

**Processing Indicators** ([Dashboard.vue:352-353](../resources/js/Components/Dashboard.vue)):
- **Blue Spinner**: Job is publishing (`is_publishing` flag set)
- **Amber Spinner**: Draft job is processing uploaded file (`is_processing` flag set)

**When No Active Job**:
```vue
<div>
    <Button label="Upload Submissions" @click="uploadSubmissions" />
    <Button label="Create a New Job" @click="createNewJob" />
</div>
```

**Purpose**: Provides quick access to the user's current work-in-progress job with visual indicators for background processes.

### 5. ClinGen GCI Sync Button

**Location**: [Dashboard.vue:110-120](../resources/js/Pages/Dashboard.vue)

**Visibility**: Only displayed for ClinGen submitter (CURIE: `CLINGEN`)

```vue
<Button
    v-if="isClingenSubmitter"
    label="GCI Sync Submissions"
    icon="pi pi-sync"
    severity="info"
    :loading="isSyncingClingen"
    @click="syncClingenSubmissions"
    title="Sync submissions from ClinGen GCI and download as zip"
/>
```

**Functionality** ([Dashboard.vue:25-70](../resources/js/Pages/Dashboard.vue)):
1. Calls `/api/clingen/sync` endpoint
2. Endpoint runs `clingen:sync` artisan command
3. Command generates zip file of synced submissions
4. Returns download URL for the zip file
5. Browser automatically downloads the file
6. Toast notification shows success/failure

**Backend Implementation** ([DashboardController.php:387-425](../app/Http/Controllers/DashboardController.php)):
```php
public function clingenSync()
{
    Artisan::call('clingen:sync');
    $output = Artisan::output();
    $zipFileName = end(explode("\n", trim($output)));

    return response()->json([
        'success' => true,
        'download_url' => '/storage/' . $zipFileName,
        'filename' => $zipFileName
    ]);
}
```

**Purpose**: Allows ClinGen users to sync their GCI submissions and download them as a packaged zip file for review.

## Technology Stack

- **Frontend Framework**: Vue 3 with Composition API (`<script setup>`)
- **UI Components**: PrimeVue
  - Fieldset
  - Divider
  - Message
- **Charting Library**: ApexCharts via `vue3-apexcharts`
- **CSS Framework**: Tailwind CSS
  - Grid system (2-column responsive layout)
  - Utility classes for spacing, colors, borders
- **Data Transfer**: Inertia.js
- **Backend**: Laravel 10
- **Date Handling**: Carbon

## Data Scope

All metrics displayed on the dashboard are **user-scoped**. The dashboard only shows data for the currently authenticated user (`Auth::user()`).

Queries filter by:
```php
$user->jobs()->...
```

This means each user only sees their own jobs and submissions.

## Status Constants

### V2 State Model (Current/Future)

The system is transitioning to string-based states stored in `status` fields:

**Job States** (`status` field):
| State | Constant | Description |
|-------|----------|-------------|
| `draft` | `Job::STATUS_DRAFT` | Draft job, editable |
| `submitted` | `Job::STATUS_SUBMITTED` | Submitted, awaiting processing |
| `completed` | `Job::STATUS_COMPLETED` | Processing complete |

**Submission States** (`status` field):
| State | Constant | Description |
|-------|----------|-------------|
| `draft_new` | `Submission::STATUS_DRAFT_NEW` | New submission being drafted |
| `submitted_new` | `Submission::STATUS_SUBMITTED_NEW` | New submission awaiting publish |
| `published` | `Submission::STATUS_PUBLISHED` | Published to public |
| `draft_republish` | `Submission::STATUS_DRAFT_REPUBLISH` | Published submission being updated |
| `submitted_republish` | `Submission::STATUS_SUBMITTED_REPUBLISH` | Updated submission awaiting publish |
| `draft_unpublish` | `Submission::STATUS_DRAFT_UNPUBLISH` | Marked for unpublish |
| `submitted_unpublish` | `Submission::STATUS_SUBMITTED_UNPUBLISH` | Awaiting unpublish |
| `unpublished` | `Submission::STATUS_UNPUBLISHED` | Unpublished, hidden |

**Dashboard Migration Plan**:
- **Phase 1**: Continue using legacy `status` field for metrics
- **Phase 2**: Update queries to use `status` field
- **Phase 3**: Add new metrics for draft/submitted/republish/unpublish states

### Legacy Status Constants (Deprecated)

The dashboard currently uses these legacy integer status constants:

**Job Status** (`status` field - Legacy):
| Constant | Value | Description |
|----------|-------|-------------|
| `Job::STATUS_INITIALIZING` | 0 | Job being initialized |
| `Job::STATUS_QUEUED` | 1 | Job queued for processing |
| `Job::STATUS_PROCESSING` | 2 | Job actively processing |
| `Job::STATUS_COMPLETE` | 3 | Job completed and published |
| `Job::STATUS_ERRORS` | 4 | Job has errors |
| `Job::STATUS_STAGED` | 5 | Job staged for publishing |
| `Job::STATUS_REMOVED` | 9 | Job removed |

Dashboard filters (Legacy):
- **Processing**: `STATUS_PROCESSING` (2), `STATUS_ERRORS` (4), `STATUS_STAGED` (5)
- **Completed**: `STATUS_COMPLETE` (3)

Excluded from metrics:
- `STATUS_INITIALIZING` (0)
- `STATUS_QUEUED` (1)
- `STATUS_REMOVED` (9)

**Submission Status** (`status` field - Legacy):
| Constant | Value | Description |
|----------|-------|-------------|
| `Submission::STATUS_INITIALIZING` | 0 | Submission being initialized |
| `Submission::STATUS_NEW` | 1 | New submission |
| `Submission::STATUS_PROCESSING` | 3 | Submission processing |
| `Submission::STATUS_ERRORS` | 4 | Submission has errors |
| `Submission::STATUS_PUBLISHED` | 20 | Submission published |
| `Submission::STATUS_REMOVED` | 9 | Submission removed |

## Known Limitations

### 1. Hardcoded 90-Day Window

**Location**: `DashboardController.php:86-87`

```php
$job_window_count = 1;
$submission_window_count = 2607;
```

These values are hardcoded and do not reflect actual 90-day publishing activity. To implement this properly, the controller should query:

```php
$ninetyDaysAgo = Carbon::now()->subDays(90);

$job_window_count = $user->jobs()
    ->where('status', Job::STATUS_COMPLETE)
    ->where('updated_at', '>=', $ninetyDaysAgo)
    ->count();

$submission_window_count = Submission::whereHas('job', function($query) use ($user) {
        $query->where('user_id', $user->id);
    })
    ->where('status', Submission::STATUS_PUBLISHED)
    ->where('updated_at', '>=', $ninetyDaysAgo)
    ->count();
```

### 2. Chart Data from User Preferences

Both charts display data from user preferences rather than live database aggregation:

- **Submissions Chart**: `user->preferences->dash_sub_graph`
- **Classifications Chart**: `user->preferences->dash_class_graph`

These arrays are initialized with zeros when a user is created:

```php
'dash_sub_graph' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
'dash_class_graph' => [0, 0, 0, 0, 0, 0, 0, 0, 0]
```

**Issue**: There is no mechanism currently implemented to update these preference values based on actual submission data.

**Solution**: Implement a background job or scheduled task to periodically calculate and update these values, or calculate them on-demand in the controller.

### 3. No Real-Time Updates

The dashboard metrics are calculated on page load. Changes to jobs or submissions require a page refresh to update the displayed metrics.

**Potential Enhancement**: Implement real-time updates using Laravel Echo and Ably broadcasting (already configured for spreadsheet uploads).

## User Preferences Structure

User preferences are stored as JSON in the `users.preferences` column. The dashboard-related preferences are:

```php
[
    'notify' => false,
    'dash_sub_graph' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], // 12 months
    'dash_class_graph' => [0, 0, 0, 0, 0, 0, 0, 0, 0],        // 9 classifications
    'job_favorites' => [],
    'sub_favorites' => []
]
```

**Initialization**: Preferences are initialized in `User::initialize_preferences()` method (`app/Models/User.php:323-333`).

## Future Enhancements

### Immediate Priorities
1. **Migrate to V2 State Model**: Update all queries to use `status` fields
2. **Fix 90-Day Window**: Replace hardcoded values with actual date-based queries
3. **Real-Time Chart Data**: Calculate from database instead of user preferences

### Additional Enhancements
4. **V2-Specific Metrics**: Add dashboard cards for:
   - Draft jobs (editable)
   - Submitted jobs (awaiting processing)
   - Draft submissions by type (new/republish/unpublish)
   - Unpublished submissions (hidden but restorable)
5. **Live Updates**: Add WebSocket support for real-time metric updates
6. **Extended Metrics**: Consider adding:
   - Average processing time
   - Submission error rate breakdown
   - Most common error types
   - Activity timeline/heatmap
   - Republish/unpublish activity
7. **User Experience**:
   - Filters by date range, classification, or status
   - Export functionality for dashboard data
   - Period comparisons (week-over-week, month-over-month)
   - Drill-down links from metrics to filtered job/submission views

## State Model Reference

For complete state model documentation, see:
- [STATE_MODEL_QUICK_REFERENCE.md](STATE_MODEL_QUICK_REFERENCE.md) - API usage and examples
- [STATE_MODEL_DIAGRAMS.md](STATE_MODEL_DIAGRAMS.md) - Visual state flow diagrams
