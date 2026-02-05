# Plan: Admin vs Non-Admin User Separation (Team-Based Admin)

## Goal
- **Admin users**: Members of the "admin" Team can view/search/list/add/edit/remove submitters AND users across the system. They can also "act as" any submitter via session-based `selected_submitter_id`.
- **Non-admin users**: Can only modify their own submitter info, and primarily manage their submissions and jobs.
- **Remove `is_admin` from submitters**: All submitters are real submitters. Admin status is determined solely by Team membership.

## Key Design Decision: Team-Based Admin

Currently `isGenccAdmin()` checks `submitters()->where('is_admin', true)->exists()`. This requires a fake "GenCC Administrator" submitter with `is_admin = true`.

**New approach:** Admin is determined by membership in the "admin" Team (a non-personal team named "admin" that already exists in the database, owned by lbabb, with members: lbabb, pweller, kferrite, toneill attached via `team_user` pivot). The `is_admin` column on the `submitters` table is removed entirely. Every submitter is a real submitter — no special admin submitter needed.

## Implementation Plan

### Step 1: Migration - Remove `is_admin` from submitters
**New migration file**

- Drop the `is_admin` boolean column from the `submitters` table

### Step 2: Update `User::isGenccAdmin()` to use Team membership
**File: `app/Models/User.php`**

Change from:
```php
return $this->submitters()->where('is_admin', true)->exists();
```
To:
```php
return $this->teams()->where('teams.name', 'admin')->where('personal_team', false)->exists();
```

Uses the existing Jetstream `HasTeams` trait and `team_user` pivot table.

### Step 3: Update HandleInertiaRequests middleware
**File: `app/Http/Middleware/HandleInertiaRequests.php`**

- Remove `->where('is_admin', false)` filters from submitter queries — no longer needed since no submitter has an `is_admin` flag. All submitters appear in the selection dropdown.

### Step 4: Update Submitter model
**File: `app/Models/Submitter.php`**

- Remove `is_admin` from `$casts` and `$fillable` arrays

### Step 5: Update ImportUsers command
**File: `app/Console/Commands/ImportUsers.php`**

- Remove `is_admin` references from YAML import logic (lines ~77, ~101)
- Admin users are managed through the admin Team, not through submitter flags

### Step 6: Backend - Admin Submitter & User Management API
**File: `app/Http/Controllers/API/AdminController.php`**

Add new admin-only endpoints (all use existing `checkAdmin()`):

Submitter CRUD:
- `GET /api/admin/submitters` - List all submitters (with `?search=` query param)
- `GET /api/admin/submitters/{id}` - Get single submitter with its users
- `POST /api/admin/submitters` - Create new submitter
- `PUT /api/admin/submitters/{id}` - Update any submitter
- `DELETE /api/admin/submitters/{id}` - Deactivate a submitter

User CRUD:
- `GET /api/admin/users` - List all users (with `?search=`, `?submitter_id=`)
- `GET /api/admin/users/{id}` - Get single user with submitter associations
- `POST /api/admin/users` - Create new user
- `PUT /api/admin/users/{id}` - Update any user
- `DELETE /api/admin/users/{id}` - Deactivate a user
- `POST /api/admin/users/{id}/submitters` - Update user-submitter associations

### Step 7: Backend - Admin Web Page Controller
**New file: `app/Http/Controllers/AdminPageController.php`**

Inertia page controller with admin guard (`isGenccAdmin()` check in each method):
- `submitters()` - Render Admin/Submitters page with submitter list
- `submitterDetail($id)` - Render Admin/SubmitterDetail page
- `users()` - Render Admin/Users page with user list
- `userDetail($id)` - Render Admin/UserDetail page

### Step 8: Routes
**File: `routes/web.php`** - Add admin web routes within the auth middleware group:
```
GET /admin/submitters         → AdminPageController@submitters
GET /admin/submitters/{id}    → AdminPageController@submitterDetail
GET /admin/users              → AdminPageController@users
GET /admin/users/{id}         → AdminPageController@userDetail
```

**File: `routes/api.php`** - Add API routes within existing admin group:
```
GET/POST/PUT/DELETE /api/admin/submitters[/{id}]
GET/POST/PUT/DELETE /api/admin/users[/{id}]
POST /api/admin/users/{id}/submitters
```

### Step 9: Frontend - Admin Navigation
**File: `resources/js/Layouts/AppLayout.vue`**

Add "Submitters" and "Users" nav links visible only when `isGenccAdmin` is true. These are always visible for admins (not gated by submitter selection like Jobs/Submissions).

### Step 10: Frontend - Admin Submitter List Page
**New file: `resources/js/Pages/Admin/Submitters.vue`**

- PrimeVue DataTable with columns: Name, CURIE, Status, Website, User Count
- Search bar with global filter
- "Add Submitter" button
- Row click navigates to submitter detail page

### Step 11: Frontend - Admin Submitter Detail Page
**New file: `resources/js/Pages/Admin/SubmitterDetail.vue`**

- Display submitter info (reuse layout from SubmitterSettings.vue)
- Edit button using existing ChangeSubmitterInfo.vue component
- Users section showing associated users with add/remove capability

### Step 12: Frontend - Admin User List Page
**New file: `resources/js/Pages/Admin/Users.vue`**

- PrimeVue DataTable with columns: Name, Email, Submitter(s), Title
- Search bar
- "Add User" button
- Row click navigates to user detail

### Step 13: Frontend - Admin User Detail Page
**New file: `resources/js/Pages/Admin/UserDetail.vue`**

- Display user profile info with edit capability
- Submitter associations section (add/remove submitters, toggle contact)

### Step 14: Update tests
**Files: `tests/Feature/AdminControllerTest.php`, `tests/Feature/AdminDashboardTest.php`**

- Update test setup to create admin users by adding them to an "admin" Team instead of associating them with a submitter that has `is_admin = true`
- Update assertions that reference `is_admin` on submitters

## Files Summary

**Modified:**
- `app/Models/User.php` - Change `isGenccAdmin()` to check admin Team membership
- `app/Models/Submitter.php` - Remove `is_admin` from casts/fillable
- `app/Http/Middleware/HandleInertiaRequests.php` - Remove `is_admin` filters
- `app/Http/Controllers/API/AdminController.php` - Add submitter/user CRUD
- `app/Console/Commands/ImportUsers.php` - Remove `is_admin` submitter logic
- `routes/web.php` - Add admin page routes
- `routes/api.php` - Add admin API routes
- `resources/js/Layouts/AppLayout.vue` - Add admin nav links
- `tests/Feature/AdminControllerTest.php` - Update admin setup
- `tests/Feature/AdminDashboardTest.php` - Update admin setup

**New:**
- Migration to drop `is_admin` from submitters table
- `app/Http/Controllers/AdminPageController.php` - Admin Inertia page controller
- `resources/js/Pages/Admin/Submitters.vue` - Submitter list page
- `resources/js/Pages/Admin/SubmitterDetail.vue` - Submitter detail page
- `resources/js/Pages/Admin/Users.vue` - User list page
- `resources/js/Pages/Admin/UserDetail.vue` - User detail page

**Unchanged:**
- `resources/js/Pages/SubmitterSettings.vue` - Stays as-is for non-admin users
- `resources/js/Components/ChangeSubmitterInfo.vue` - Reused in admin detail
- `app/Http/Controllers/API/SubmitterController.php` - Non-admin submitter updates
- Existing admin operational endpoints (run-publish, update-diseases, etc.)
