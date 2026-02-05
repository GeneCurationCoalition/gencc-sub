# Plan: Background Admin Actions

## Problem
All four admin dashboard actions (Run Publish, Update Diseases, Update Genes, Sync PubMed) run synchronously via `Artisan::call()` inside the HTTP request. If the user navigates away or logs out, the browser aborts the request and the process is killed.

## Solution
Dispatch each action as a queued Laravel Job so it runs in the background via the existing `gencc-queue-worker` PM2 process. The controller returns immediately, and the frontend relies entirely on progress polling to track status and detect completion.

## Changes

### 1. New Job: `app/Jobs/RunAdminCommand.php`
A generic queued job that:
- Accepts: operation name, artisan command string, user ID, and a summary generator class/identifier
- Runs `Artisan::call($command)` and captures output
- Creates an `AdminLog` entry with output, exit code, duration
- Calls `AdminProgressTracker::complete()` or `::fail()` when done
- Has a 30-minute timeout (these commands can be slow)
- Stores result data in the progress tracker so the frontend can retrieve it

### 2. Modify `AdminController::runAndLog()`
Change from synchronous to async:
- Check if the operation is already running via `AdminProgressTracker::isRunning()` — reject duplicates
- Initialize `AdminProgressTracker::start()` from the controller (so polling works immediately)
- Dispatch `RunAdminCommand` to the queue
- Return immediately with `{ started: true, message: 'Operation started' }`

The four action methods (`runPublish`, `updateDiseases`, `updateGenes`, `syncPubmed`) remain unchanged — they still call `runAndLog()` with the same arguments. The summary generator callables need to become serializable (move to a static method or a dedicated class) since closures can't be serialized for queue dispatch.

### 3. Move summary generators to a helper
The anonymous closures currently passed as `$summaryGenerator` to `runAndLog()` can't be serialized for queue jobs. Options:
- Move them to static methods on `AdminController` and pass the method name as a string
- Create a small `AdminSummaryParser` helper class with static methods

I'll use static methods on the job itself to keep it simple, with a `$summaryType` string parameter.

### 4. Add completion status endpoint
Add a `GET /api/admin/status/{operation}` endpoint (or extend the existing progress endpoint) that returns the final result from AdminProgressTracker — including success/failure, summary, and output. The progress tracker `complete()` method will store this data.

Actually, the existing `/api/admin/progress/{operation}` endpoint already returns status. I just need AdminProgressTracker to store result data (output, summary, exit_code) when marking complete/failed. Then the frontend can read it from there.

### 5. Modify `AdminProgressTracker`
- `complete()` — accept additional data: output, summary, exit_code, duration
- `fail()` — accept additional data: output, exit_code, duration
- Increase TTL to 30 minutes for result data availability

### 6. Modify frontend `Dashboard.vue`
Change `runAdminAction()`:
- POST to the endpoint — on success response (`{ started: true }`), start polling (already does this)
- Remove the await on the POST for result processing — the POST just starts the job
- Move result handling entirely into the polling logic:
  - When polling returns `status: 'complete'`, show success toast with summary from progress data
  - When polling returns `status: 'failed'`, show error toast
  - Clear loading state when complete/failed
- On mount / page load: check if any operations are currently running (poll once for each) and resume showing progress if so — this enables the "navigate away and come back" behavior

### 7. Resume running operations on page load
On `onMounted`, poll each operation once. If any returns `status: 'running'`, set that action's loading state to true and start polling. This lets the user navigate away and come back to see the operation still running.

## File Changes Summary

| File | Change |
|------|--------|
| `app/Jobs/RunAdminCommand.php` | **New** — Generic queued job for admin commands |
| `app/Http/Controllers/API/AdminController.php` | Modify `runAndLog()` to dispatch job instead of sync call; move summary generators to serializable form |
| `app/Services/AdminProgressTracker.php` | Extend `complete()`/`fail()` to store result data (output, summary, exit_code, duration) |
| `resources/js/Components/Dashboard.vue` | Change `runAdminAction()` to fire-and-forget POST + polling-driven results; add `onMounted` resume logic |

## Key Decisions
- **Queue vs Process fork**: Using queue because the queue worker already exists and handles timeouts, retries, and monitoring
- **No new endpoints**: Reusing existing progress endpoint with richer completion data
- **Summary generators**: Moved to static methods on `RunAdminCommand` to be serializable
- **Duplicate prevention**: Check `AdminProgressTracker::isRunning()` before dispatching
