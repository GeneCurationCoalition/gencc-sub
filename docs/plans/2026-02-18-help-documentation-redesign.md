# Help & Documentation Page Redesign

## Overview

Consolidate the existing `/help` and `/submission-directions` pages into a single Help & Documentation page that provides:
- Downloadable User Guide PDF
- Downloadable Submission Spreadsheet template
- Placeholder for future API documentation
- Contact information for technical and general inquiries

## Current State

**Help page** (`/help`):
- Links to Submission Directions page
- Contact Us mailto link

**Submission Directions page** (`/submission-directions`):
- Download link for submission spreadsheet
- Reference lists for Submitter IDs, Classification IDs, Mode of Inheritance codes
- Disclaimer notice

## New Design

### Page Structure

Single page with three sections:

1. **Downloads** - Card grid with downloadable resources
2. **API Documentation** - Coming soon placeholder
3. **Contact Us** - Two contact options

### Downloads Section

**User Guide Card:**
- Title: "User Guide"
- File badge: PDF
- Description: "Complete guide to using the GenCC Submission Portal, including:"
  - Dashboard overview and navigation
  - Uploading submissions from spreadsheets
  - Adding submissions manually
  - Publishing, unpublishing, and republishing workflows
  - Troubleshooting common errors
- Download: `/documents/UserGuide.pdf`

**Submission Spreadsheet Card:**
- Title: "Submission Spreadsheet Template"
- File badge: Excel
- Description: "Official GenCC submission template (Version 2) with built-in guidance. The spreadsheet includes reference lists for Submitter IDs, Classification codes, and Mode of Inheritance codes."
- Download: `/download/template`

### API Documentation Section

**API Documentation Card:**
- Title: "API Documentation"
- File badge: PDF (greyed/disabled)
- Description: "Guide for programmatic interaction with the submission portal."
- Status: "Coming Soon" badge
- Download button disabled

### Contact Us Section

Two contact options:

1. **General Inquiries** (first)
   - Email: gencc@thegencc.org
   - For: Scientific questions, data inquiries, participation interest

2. **Technical Support** (second)
   - Email: gencc-tech@broadinstitute.org
   - For: Portal functionality, upload issues, error troubleshooting

## Implementation

### Files to Modify

1. `resources/js/Pages/Help.vue` - Rewrite with consolidated layout
2. `routes/web.php` - Add redirect from `/submission-directions` to `/help`

### Files to Delete

1. `resources/js/Pages/SubmissionDirections.vue` - Replaced by consolidated page
2. `resources/js/Components/Help.vue` - No longer needed

### Files to Rename

1. `public/documents/user guide.pdf` → `public/documents/UserGuide.pdf`

### Route Changes

```php
// Keep existing
Route::get('/help', function () {
    return Inertia::render('Help');
})->name('help');

// Change to redirect
Route::redirect('/submission-directions', '/help');
```

## Reference Content Removal

The following reference lists are being removed from the web page (users can find them in the submission spreadsheet):
- Submitter IDs (GENCC:000101 - GENCC:000121)
- Classification IDs (GENCC:100001 - GENCC:100009)
- Mode of Inheritance IDs (HP terms)

The disclaimer notice is also removed as this information is covered in the User Guide.
