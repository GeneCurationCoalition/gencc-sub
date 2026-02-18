# Admin Document Upload Feature Design

**Date:** 2026-02-18

## Overview

Allow admin users to update portal documentation files (User Guide, API Guide, Submission Spreadsheet) directly from the Help page without requiring a new deployment.

## Design Decisions

1. **Location:** Inline on Help page - Update button appears next to Download button for admins only
2. **Storage:** Overwrite with backup - Previous version saved as `.backup` before replacement
3. **UI:** Inline button - Small "Update" button next to each Download button

## File Structure

Files remain in `public/documents/`:
- `UserGuide.pdf`
- `APIGuide.pdf`
- `GenCC Submission Spreadsheet.xlsx`

Backups stored alongside:
- `UserGuide.pdf.backup`
- `APIGuide.pdf.backup`
- `GenCC Submission Spreadsheet.xlsx.backup`

## Backend Implementation

### API Endpoint

```
POST /api/admin/documents/{type}
```

Where `{type}` is one of: `user-guide`, `api-guide`, `spreadsheet`

### Controller Method

Add to `AdminPageController.php`:

```php
public function updateDocument(Request $request, string $type)
{
    $this->checkAdmin();

    $config = [
        'user-guide' => [
            'rules' => ['file', 'mimes:pdf', 'max:10240'],
            'filename' => 'UserGuide.pdf',
        ],
        'api-guide' => [
            'rules' => ['file', 'mimes:pdf', 'max:10240'],
            'filename' => 'APIGuide.pdf',
        ],
        'spreadsheet' => [
            'rules' => ['file', 'mimes:xlsx', 'max:5120'],
            'filename' => 'GenCC Submission Spreadsheet.xlsx',
        ],
    ];

    // Validate type, validate file, backup existing, save new
}
```

### Route

In `routes/api.php`:
```php
Route::post('/admin/documents/{type}', [AdminPageController::class, 'updateDocument'])
    ->middleware(['auth:sanctum']);
```

## Frontend Implementation

### Help.vue Changes

1. **Props from controller:**
   - `isAdmin` - boolean
   - `documentMeta` - object with file sizes and last modified dates

2. **Update button:** Appears next to Download when `isAdmin` is true
   ```
   [Download]  [Update]
   ```

3. **Hidden file inputs:** One per document type, triggered by Update button

4. **Upload flow:**
   - Click Update → file picker opens
   - Select file → axios POST with FormData
   - Show loading spinner during upload
   - Success: toast notification + page refresh
   - Error: toast with error message

### New HelpController

Create controller to serve Help page with admin check and document metadata:

```php
class HelpController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $isAdmin = $user && $user->isGenccAdmin();

        return Inertia::render('Help', [
            'isAdmin' => $isAdmin,
            'documentMeta' => $this->getDocumentMeta(),
        ]);
    }
}
```

## Error Handling

| Scenario | Response |
|----------|----------|
| Wrong file type | 422: "Please upload a PDF file" |
| File too large | 422: "File exceeds maximum size" |
| Upload fails | 500: "Upload failed. Please try again." |
| Not admin | 403: Forbidden |

## Files to Create/Modify

| File | Action |
|------|--------|
| `app/Http/Controllers/AdminPageController.php` | Add `updateDocument()` method |
| `routes/api.php` | Add POST route |
| `app/Http/Controllers/HelpController.php` | Create new controller |
| `routes/web.php` | Update `/help` route |
| `resources/js/Pages/Help.vue` | Add upload UI and logic |

## Validation Rules

| Document | Allowed Types | Max Size |
|----------|--------------|----------|
| User Guide | PDF | 10MB |
| API Guide | PDF | 10MB |
| Spreadsheet | XLSX | 5MB |
