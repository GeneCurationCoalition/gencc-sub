# Legacy Status Migration Plan

> **NOTE**: This migration is now COMPLETE. The `status_v2` column has been renamed to `status`
> and all code now uses the string-based status field directly. This document is retained
> for historical reference only.

## Overview

The GenCC Submission Portal transitioned from a legacy integer-based `status` field to a new
string-based status field. The migration was completed in December 2025. The `status_v2` column
was renamed to `status` and all code updated to use the new field name.

## Status Field Comparison

### Job Status Fields

**Legacy (`status`):**
- `STATUS_INITIALIZING = 0`
- `STATUS_QUEUED = 1`
- `STATUS_PROCESSING = 2`
- `STATUS_COMPLETE = 3`
- `STATUS_ERRORS = 4`
- `STATUS_STAGED = 5`
- `STATUS_PUBLISHED = 6`
- `STATUS_REMOVED = 10`

**V2 (`status_v2`):**
- `STATUS_V2_DRAFT = 'draft'`
- `STATUS_V2_SUBMITTED = 'submitted'`
- `STATUS_V2_PROCESSED = 'processed'`

### Submission Status Fields

**Legacy (`status`):**
- `STATUS_INITIALIZING = 0`
- `STATUS_NEW = 1`
- `STATUS_PROCESSING = 2`
- `STATUS_PUBLISHED = 3`
- `STATUS_ERRORS = 4`
- `STATUS_REMOVED = 10`

**V2 (`status_v2`):**
- `STATUS_V2_DRAFT_NEW = 'draft_new'`
- `STATUS_V2_DRAFT_REPUBLISH = 'draft_republish'`
- `STATUS_V2_DRAFT_UNPUBLISH = 'draft_unpublish'`
- `STATUS_V2_SUBMITTED_NEW = 'submitted_new'`
- `STATUS_V2_SUBMITTED_REPUBLISH = 'submitted_republish'`
- `STATUS_V2_SUBMITTED_UNPUBLISH = 'submitted_unpublish'`
- `STATUS_V2_PUBLISHED = 'published'`
- `STATUS_V2_UNPUBLISHED = 'unpublished'`

## Current State (Phase 1: Dual Status System)

Both `status` and `status_v2` fields are maintained for backward compatibility. All state machine operations update both fields where applicable.

### Frontend Status

✅ **Fully Migrated to V2:**
- All Vue components use `status_v2`
- Status tags display V2 values
- No legacy status references in frontend

### Backend Status

⚠️ **Mixed Usage (V2 Primary, Legacy Maintained):**

#### Files Using ONLY V2 Status:
- `app/Services/JobStateMachine.php` - Uses V2, maintains legacy for compatibility
- `app/Services/SubmissionStateMachine.php` - Uses V2, maintains legacy for compatibility
- `app/Http/Controllers/JobController.php` - Queries V2, checks legacy for migration period
- `app/Http/Controllers/PublishController.php` - Transitioned to V2 with legacy fallback

#### Files Still Using Legacy Status:

**Critical - API Endpoints:**
- `app/Http/Controllers/API/JobController.php`
  - Line 65: `store()` - Sets initial status to `Job::STATUS_PROCESSING`
  - Line 210-211: `destroy()` - Checks `Submission::STATUS_PUBLISHED`
  - Line 223: `destroy()` - Checks `Job::STATUS_COMPLETE`
  - Line 254: `publish()` - Sets `Job::STATUS_STAGED`
  - Line 279: `unpublish()` - Sets `Job::STATUS_PROCESSING`

- `app/Http/Controllers/API/SubmitController.php`
  - Line 104: `store()` - Sets initial job status to `Job::STATUS_PROCESSING`
  - Line 136: Sets job status to `Job::STATUS_ERRORS`
  - Line 138: Sets submission status to `Submission::STATUS_ERRORS`
  - Line 162-164: Sets job status to `Job::STATUS_STAGED`
  - Line 175: Sets submission status to `Submission::STATUS_PROCESSING`
  - Line 300: Sets submission status to `Submission::STATUS_REMOVED`
  - Line 304: Sets job status to `Job::STATUS_REMOVED`
  - Line 325: Sets submission status to `Submission::STATUS_REMOVED`

**Console Commands:**
- `app/Console/Commands/RunPublish.php`
  - Line 82: Queries jobs with `STATUS_ERRORS`
  - Line 92: Queries jobs with `STATUS_STAGED`
  - Line 137: Checks both `STATUS_COMPLETE` and `STATUS_V2_PROCESSED`

**Dashboard:**
- `app/Http/Controllers/DashboardController.php` - May use legacy status for metrics

## Migration Path

### Phase 2: Update API Endpoints (NEXT)

**Goal:** Update all API endpoints to use V2 status while maintaining dual writes.

**Tasks:**
1. Update `API/JobController.php`:
   - `store()`: Set `status_v2` to `draft` instead of legacy status
   - `destroy()`: Check V2 published/unpublished states
   - `publish()`: Use `JobStateMachine::submit()` instead of direct status update
   - `unpublish()`: Add appropriate V2 state transition

2. Update `API/SubmitController.php`:
   - `store()`: Initialize jobs with V2 status
   - Error handling: Use `SubmissionStateMachine` for error states
   - `status()` endpoint: Return both legacy and V2 status with deprecation notice
   - Remove operations: Use state machine transitions

3. Update `RunPublish.php`:
   - Query jobs by V2 status instead of legacy
   - Update error handling to use V2 states

4. Update `DashboardController.php`:
   - Use V2 status for metrics and counts

**Timeline:** 2-4 weeks
**Testing Required:** Full API test suite, manual testing with sample submissions

### Phase 3: Deprecation Period (FUTURE)

**Goal:** Notify API consumers about legacy status deprecation.

**Tasks:**
1. Add deprecation warnings to API responses:
   ```json
   {
     "status": 2,
     "status_v2": "draft",
     "_deprecated": {
       "status": "The 'status' field is deprecated and will be removed in v3.0. Please use 'status_v2' instead."
     }
   }
   ```

2. Update API documentation to highlight V2 status
3. Send notifications to known API consumers
4. Monitor API logs for legacy status usage

**Timeline:** 6-12 months notice period
**Communication Required:** Email to API consumers, documentation updates

### Phase 4: Legacy Removal (FUTURE)

**Goal:** Remove legacy `status` column entirely.

**Prerequisites:**
- All API consumers migrated to V2
- No legacy status queries in logs for 30+ days
- All code uses V2 status exclusively

**Tasks:**
1. Create database migration to drop `status` column
2. Remove all legacy status constants from models
3. Remove backward compatibility code from state machines
4. Update all remaining queries
5. Full regression testing

**Timeline:** TBD (depends on API consumer migration)

## Testing Checklist

When updating each component:

- [ ] Unit tests pass for state transitions
- [ ] API endpoints return correct V2 status
- [ ] Frontend displays correct status tags
- [ ] Database consistency maintained (both fields match during dual-write period)
- [ ] No legacy status queries in updated code
- [ ] State machines handle all edge cases
- [ ] Existing jobs/submissions migrate correctly

## External Dependencies

**Known API Consumers:**
- ClinGen pipeline (uses submission API)
- Internal scripts (may query status)
- Manual API integrations (unknown count)

**Action Required:** Audit API access logs to identify all consumers before Phase 3.

## Questions for Decision

1. **API Versioning:** Should we introduce API versioning (v2, v3) alongside status migration?
2. **Data Cleanup:** Should we backfill status_v2 for all historical records, or only active ones?
3. **Error States:** How should V2 handle error states? Currently errors are tracked separately from status.
4. **Timeline:** What's the target date for completing Phase 2?

## Notes

- State machines already maintain dual writes, so Phase 1 is complete
- Frontend migration is 100% complete
- Main blocker is external API consumers who may depend on legacy status values
- Consider adding status_v2 to database indexes once migration is complete
