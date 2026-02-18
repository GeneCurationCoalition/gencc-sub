# GenCC Submission Portal - Development Guide

> **Consolidated development documentation for the GenCC Submission Portal**
>
> Last Updated: December 2025

## Quick Links

- [Project Overview](#project-overview)
- [Technology Stack](#technology-stack)
- [Development Setup](#development-setup)
- [Architecture](#architecture)
- [Testing](#testing)
- [Deployment](#deployment)

---

## Project Overview

GenCC Submission Portal - A Laravel 10 + Inertia.js + Vue 3 application for managing gene-disease relationship submissions. This system processes both API and file-based submissions, validates them against external data sources (HGNC, MONDO, OMIM), and publishes curated data.

### Key Features

- **Dual Submission Modes**: API endpoints + Excel file uploads
- **External Validation**: HGNC genes, MONDO/OMIM/Orphanet diseases, HP inheritance terms
- **Workflow Management**: Job-based submission grouping with state machine
- **Background Processing**: Asynchronous upload handling with polling-based progress
- **Data Publishing**: Staged review and publication of curated submissions

---

## Technology Stack

### Backend
- **Framework**: Laravel 10 (PHP 8.1+)
- **Authentication**: Laravel Jetstream with Sanctum
- **Permissions**: Spatie Laravel Permission
- **Queue**: Database-based queue with ProcessSubmissionsUpload job
- **Data Import/Export**: Maatwebsite Excel

### Frontend
- **Framework**: Vue 3 + Composition API
- **Routing**: Inertia.js (server-side routing, no separate API calls)
- **UI Components**: PrimeVue
- **Styling**: Tailwind CSS
- **Build**: Vite with HMR

### External Data Sources
- **HGNC**: Gene nomenclature
- **MONDO**: Disease ontology (primary)
- **OMIM**: Disease database
- **Orphanet**: Rare disease database
- **PubMed**: Article metadata via E-utilities API

---

## Development Setup

### Prerequisites

```bash
# Required
PHP >= 8.1
Composer
Node.js >= 22 (LTS)
NPM
MySQL/MariaDB

# Optional
Redis (for caching)
PM2 (for process management - recommended)
```

### Installation

```bash
# Install dependencies
composer install
npm install

# Environment setup
cp .env.example .env
php artisan key:generate

# Database
php artisan migrate
php artisan db:seed  # Optional: seed test data

# Build assets
npm run dev          # Development with HMR
npm run build        # Production build
```

### Running the Application

**Option 1: Using Podman/Docker (Recommended for Isolation)**

Run the entire application stack in containers with no local dependencies beyond Podman.

```bash
# Start all services (app + MySQL database)
podman-compose -f docker-compose.dev.yml up -d

# Watch the logs
podman-compose -f docker-compose.dev.yml logs -f app

# Stop everything
podman-compose -f docker-compose.dev.yml down
```

**Access Points:**
| Service | URL |
|---------|-----|
| Laravel App | http://localhost:8001 |
| Vite HMR | http://localhost:5173 |
| MySQL | localhost:3306 |

**What Happens on Startup:**
1. MySQL container starts and waits until healthy
2. App container runs `composer install` and `npm install`
3. Generates app key and runs migrations
4. Starts PM2 with Laravel server, Vite, and queue worker

**Useful Container Commands:**
```bash
# Rebuild after Dockerfile changes
podman-compose -f docker-compose.dev.yml up -d --build

# Run artisan commands
podman exec -it gencc-dev php artisan migrate:status

# Access MySQL
podman exec -it gencc-db mysql -u gencc -pgencc_dev_password gencc_sub

# View PM2 status inside container
podman exec -it gencc-dev pm2 status

# Shell into the container
podman exec -it gencc-dev bash
```

**Note:** First startup may take a few minutes to build the image and install dependencies. Subsequent starts are much faster since dependencies are cached in named volumes.

**Option 2: Using PM2 (Local Install)**

PM2 manages all services automatically with auto-restart, logging, and monitoring.

```bash
# Start all services (dev server, Vite, queue worker)
pm2 start ecosystem.dev.config.cjs

# View status
pm2 status

# View logs
pm2 logs

# Stop all services
pm2 stop all
```

**See [PM2.md](archives/PM2.md) for complete PM2 documentation including:**
- Individual service management
- Log file locations
- Memory limits and auto-restart
- Production setup
- Troubleshooting

**Option 3: Manual (3 terminals)**

```bash
# Terminal 1: Laravel dev server
php artisan serve

# Terminal 2: Vite dev server (HMR)
npm run dev

# Terminal 3: Queue worker (for background jobs)
php artisan queue:work --timeout=3600
```

### Key Configuration

**.env settings:**
```bash
# Database
DB_CONNECTION=mysql
DB_DATABASE=gencc_sub

# Queue (required for async uploads)
QUEUE_CONNECTION=database

# External APIs
HGNC_API_URL=...
MONDO_URL=...
OMIM_API_KEY=...

# Broadcasting (optional - using polling instead)
BROADCAST_DRIVER=log
```

---

## Architecture

### Core Concepts

#### 1. Job → Submission Hierarchy

**Job** (`J-1XXXXX`)
- Groups one or more submissions together
- Has single submitter (organization)
- Tracks overall workflow state
- Can have attached documents (Excel uploads)

**Submission** (`SGC-1XXXXX`)
- Individual gene-disease curation record
- Belongs to exactly one Job
- Has validation state and errors
- Contains evidence (PMIDs) and classification

#### 2. State Machine

**Job States:**
```
INITIALIZING → QUEUED → PROCESSING → COMPLETE/ERRORS → STAGED → PUBLISHED/REMOVED
```

**Submission States:**
```
INITIALIZING → NEW → PROCESSING → ERRORS/PUBLISHED → REMOVED
```

**Document Upload States:**
```
null → validating → validation_failed OR validated → uploading → upload_complete/upload_partial
```

#### 3. Disease Equivalence System

**MONDO-First Architecture:**

1. **MONDO** diseases are canonical (type = 1, status = ACTIVE)
2. **OMIM/Orphanet** diseases have `mondo_id` FK pointing to MONDO
3. Equivalence determined by:
   - **Priority 1**: `skos:exactMatch` (1:1 relationship)
   - **Priority 2**: `xrefs` (may be 1:many, use first)
   - **Priority 3**: OMIM equivalence in Orphanet uses Orphanet MONDO equivalence from Priority 1 or 2 if available.

**Deprecated Diseases:**
- `status` = 8 (DEPRECATED)
- `name` = preserved (last active name)
- `deprecated_name` = new obsolete name or "REMOVED-" prefix
- `mondo_id` = preserved forever (never cleared)

**See:** Legacy `DISEASE_UPDATE_COMPREHENSIVE.md` for full details

---

### Async Upload Workflow

**Problem Solved:** Eliminated WebSocket timing issues, improved UX for large file uploads

**Architecture:**

1. **Phase 1 - Synchronous Validation** (< 5 seconds):
   - User uploads Excel file
   - Blocking dialog shows progress
   - File format validated, row count checked
   - File associated with job regardless of validation result
   - State: `null` → `validating` → `validation_failed` OR `validated`

2. **Phase 2 - Background Processing** (asynchronous):
   - Laravel queue job (`ProcessSubmissionsUpload`)
   - Processes submissions row-by-row
   - User can navigate away
   - Progress via HTTP polling (every 3 seconds)
   - State: `validated` → `uploading` → `upload_complete` OR `upload_partial`

3. **Phase 3 - Job Locking**:
   - `jobs.is_processing = true` during upload
   - All edit buttons disabled
   - Read-only banner with progress bar
   - Auto-unlocks when complete

**Key Files:**
- `app/Jobs/ProcessSubmissionsUpload.php` - Background processor
- `app/Http/Controllers/API/DocumentController.php` - Upload endpoint
- `resources/js/Components/JobItem.vue` - Polling logic

**See:** Legacy `ASYNC_UPLOAD_REFACTOR_SPEC.md` for implementation details

---

### PubMed Integration

**Consolidated Command:** `php artisan pubmed:sync`

**Options:**
```bash
--scope=pending         # Process only INITIALIZING records (default)
--scope=all             # Process ALL records in pubmeds table
--scope=submissions     # Scan ALL submissions for PMIDs
--force                 # Force re-fetch even if data exists
--create-missing        # Create Pubmed records for missing PMIDs
--silent                # Minimal output
```

**Batch Processing:**
- NCBI allows 200 PMIDs per esummary API call
- Rate limiting: 3 req/sec (no key) or 10 req/sec (with key)
- Records transition: INITIALIZING (20) → SUMMARY_COMPLETE (21)

**Key Files:**
- `app/Console/Commands/Pubmed/SyncPubmed.php` - Unified sync command
- `app/Models/Pubmed.php` - Batch processing logic

**Removed:**
- ❌ `update:pubmed` - Redundant with sync
- ❌ `pubmed:query` - Redundant with sync
- ❌ `pubmed:refresh` - Redundant with sync
- ❌ `pubmed:efetch` - No longer fetching abstracts
- ❌ Abstract fetching - Summary data sufficient for now

---

### External Links Helper

**Purpose:** Generate clickable links to external databases for disease CURIEs and gene HGNC IDs

**File:** `resources/js/utils/externalLinks.js`

**Functions:**
```javascript
getDiseaseUrl('MONDO:12345')    → 'https://monarchinitiative.org/MONDO:12345'
getDiseaseUrl('OMIM:12345')     → 'https://omim.org/entry/12345'
getDiseaseUrl('Orphanet:12345') → 'https://www.orpha.net/en/disease/detail/12345'
getGeneUrl('HGNC:12345')        → 'https://www.genenames.org/data/gene-symbol-report/#!/hgnc_id/HGNC:12345'
```

**Usage:**
```vue
<a :href="getDiseaseUrl(disease.curie)" target="_blank" rel="noopener noreferrer">
  {{ disease.curie }}
</a>
```

**Implemented in:**
- `SubmissionsListing.vue` - Gene/disease columns
- `SubmissionItem.vue` - Gene/disease detail sections

---

## Key Artisan Commands

### Data Import/Update

```bash
# Initial data load
php artisan import:gencc            # Import GenCC curated data
php artisan import:genes            # Import HGNC gene data
php artisan import:diseases         # Import MONDO disease data
php artisan import:tables           # Import lookup tables

# Regular updates
php artisan update:genes            # Update gene information
php artisan update:diseases         # Update disease data (MONDO-first)
php artisan pubmed:sync             # Sync PubMed metadata
```

### Processing

```bash
# Submission processing
php artisan process:submissions     # Process pending submissions

# Background jobs
php artisan queue:work --timeout=3600  # Start queue worker

# Publishing
php artisan run:publish            # Publish staged submissions
```

### Maintenance

```bash
# Search indexing
php artisan update:search-submissions  # Update submission search index

# User management
php artisan add:user              # Add new user

# Database backup
php artisan make-prod-db          # Create production database dump
```

### User/Organization Import (YAML)

```bash
# Import from local files or GCS (gs:// URLs)
php artisan gencc:import-submitters [file]  # Import submitters/organizations
php artisan gencc:import-teams [file]       # Import teams
php artisan gencc:import-users [file]       # Import users

# Environment variables for file paths:
# GENCC_SUBMITTERS_FILE, GENCC_TEAMS_FILE, GENCC_USERS_FILE
```

See `DEPLOYMENT.md` for GCS setup and YAML format details.

---

## Testing

### Running Tests

```bash
# All tests
php artisan test

# Specific test file
./vendor/bin/phpunit tests/Unit/SubmissionValidationTest.php

# Specific test method
./vendor/bin/phpunit --filter=testValidGeneSubmission

# Only unit tests
./vendor/bin/phpunit tests/Unit

# Only feature tests
./vendor/bin/phpunit tests/Feature
```

### Test Coverage

**Unit Tests:** (`tests/Unit/`)
- Submission validation logic
- Disease equivalence (rosetta methods)
- Status transitions
- Data transformations

**Feature Tests:** (`tests/Feature/`)
- API endpoints
- File upload workflows
- Authentication
- Permission checks

**Browser Tests:** (Future)
- End-to-end user workflows
- JavaScript interactions
- Upload progress polling

### Code Quality

```bash
# Format code with Laravel Pint
./vendor/bin/pint

# Run before committing
./vendor/bin/pint && php artisan test
```

---

## Deployment

### Production Checklist

1. **Environment:**
   ```bash
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-domain.com
   QUEUE_CONNECTION=database
   ```

2. **Queue Worker:**
   - Use Supervisor to keep queue worker running
   - See `deployment/QUEUE_SETUP.md` for configuration

3. **Build Assets:**
   ```bash
   npm run build
   ```

4. **Optimize:**
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

5. **Database:**
   ```bash
   php artisan migrate --force
   ```

6. **Permissions:**
   ```bash
   chmod -R 755 storage bootstrap/cache
   chown -R www-data:www-data storage bootstrap/cache
   ```

### Scheduled Tasks

Add to crontab:
```bash
* * * * * cd /path/to/gencc-sub && php artisan schedule:run >> /dev/null 2>&1
```

**Scheduled commands** (defined in `app/Console/Kernel.php`):
- Disease updates
- Gene updates
- PubMed sync
- Metrics generation

---

## API Documentation

### Submission API

**Submit New Data:**
```bash
POST /api/submit/{action?}
# action: 'check' for validation only
```

**Query Submission:**
```bash
GET /api/submit/{id}
GET /api/submit/{id}/status
```

**Query Job:**
```bash
GET /api/query/job/{id}
```

**Remove Submission:**
```bash
GET /api/submit/{id}/remove
```

### Internal API (Web Middleware)

**Validate IDs:**
```bash
GET /api/lookup/gene/{id}
GET /api/lookup/disease/{id}
```

**Update Records:**
```bash
POST /api/submissions/{id}
POST /api/jobs/{id}
```

**Upload Document:**
```bash
POST /api/documents/{id}
```

**Upload Progress:**
```bash
GET /api/jobs/{id}/upload-progress
```

---

## Database Schema

### Core Tables

**jobs** - Job records with workflow state
- `id`, `slug` (J-1XXXXX), `status`, `is_processing`
- `user_id` (submitter), `submitter_id` (organization)

**submissions** - Individual gene-disease curations
- `id`, `sid` (SGC-1XXXXX), `job_id`, `status`
- `gene_id`, `disease_id`, `original_disease_id`
- `classification_id`, `inheritance_id`, `mechanism_id`
- `submission_data` (JSON), `submission_errors` (JSON)
- `evidence` (JSON array of PMIDs)

**documents** - Uploaded files
- `id`, `job_id`, `file_name`, `local_path`
- `upload_state`, `processed_submissions`, `total_submissions`

**diseases** - Disease ontology data
- `id`, `curie`, `type`, `status`
- `name`, `deprecated_name`, `mondo_id` (FK)
- `xrefs` (JSON)

**genes** - HGNC gene data
- `id`, `hgnc_id`, `symbol`, `name`

**pubmeds** - PubMed article metadata
- `id`, `pmid`, `title`, `authors`, `pubdate`
- `status` (INITIALIZING → SUMMARY_COMPLETE)

**pivot: pubmed_submission** - Links submissions to PMIDs

---

## Common Development Tasks

### Adding a New Submission Field

1. **Migration:**
   ```bash
   php artisan make:migration add_field_to_submissions_table
   ```

2. **Model:** Add to `$fillable` in `app/Models/Submission.php`

3. **Validation:** Update `SubmissionColumns.php` service

4. **Frontend:** Add input component in `resources/js/Components/`

5. **API:** Update `SubmissionController.php` to handle field

### Adding a New Lookup Table

1. **Migration:**
   ```bash
   php artisan make:migration create_table_name_table
   ```

2. **Model:** Create model with relationships

3. **Import:** Add import command in `app/Console/Commands/Import/`

4. **Validation:** Add lookup validation in `Submission::load_from_json()`

### Debugging Tips

**Laravel Debugbar** (development only):
```bash
composer require barryvdh/laravel-debugbar --dev
```

**Log Files:**
```bash
tail -f storage/logs/laravel.log
```

**Database Queries:**
```php
DB::enableQueryLog();
// ... your code ...
dd(DB::getQueryLog());
```

**Vue DevTools:**
- Install Vue DevTools browser extension
- Inspect component state, props, events

---

## Contributing

### Code Style

- **PHP:** PSR-12 (enforced by Laravel Pint)
- **JavaScript:** Prettier + ESLint
- **Vue:** Composition API (not Options API)
- **CSS:** Tailwind utility classes (avoid custom CSS)

### Git Workflow

```bash
# Create feature branch
git checkout -b feature/your-feature-name

# Make changes, commit frequently
git add .
git commit -m "Add: description of changes"

# Format code before pushing
./vendor/bin/pint

# Push and create PR
git push origin feature/your-feature-name
```

### Pull Request Checklist

- [ ] Tests pass (`php artisan test`)
- [ ] Code formatted (`./vendor/bin/pint`)
- [ ] No debug statements (dd, console.log)
- [ ] Frontend built successfully (`npm run build`)
- [ ] Migration runs cleanly
- [ ] Documentation updated if needed

---

## Troubleshooting

### Queue Jobs Not Processing

**Symptoms:** File uploads stuck in "uploading" state

**Solution:**
```bash
# Check queue worker is running
ps aux | grep "queue:work"

# Restart queue worker
php artisan queue:restart
php artisan queue:work --timeout=3600
```

### Frontend Changes Not Appearing

**Solution:**
```bash
# Clear compiled views
php artisan view:clear

# Restart Vite dev server
npm run dev
```

### Database Lock Errors

**Symptoms:** "Table is locked" errors during uploads

**Solution:**
- Ensure `DB_CONNECTION=mysql` (not sqlite)
- Check database connection timeout settings
- Verify transactions are properly committed

### CORS Errors

**Solution:**
```bash
# Check CORS config
php artisan config:clear

# Verify API routes use correct middleware
# routes/api.php should have 'api' middleware
```

---

## Additional Resources

### Documentation
- **Laravel 10**: https://laravel.com/docs/10.x
- **Vue 3**: https://vuejs.org/guide/
- **Inertia.js**: https://inertiajs.com/
- **PrimeVue**: https://primevue.org/
- **Tailwind CSS**: https://tailwindcss.com/docs

### External APIs
- **HGNC**: https://www.genenames.org/
- **MONDO**: https://mondo.monarchinitiative.org/
- **OMIM**: https://omim.org/
- **Orphanet**: https://www.orpha.net/
- **PubMed E-utilities**: https://www.ncbi.nlm.nih.gov/books/NBK25501/

### Project-Specific Docs

- `../CLAUDE.md` - Instructions for Claude Code AI assistant
- `DEPLOYMENT.md` - Production deployment guide
- `STATE_MODEL_QUICK_REFERENCE.md` - Job/submission state transitions
- `SUBMISSION_PROCESSING_GUIDE.md` - Detailed submission workflow

---

## Changelog

### December 2025

**PubMed Refactoring:**
- Consolidated 3 commands into `pubmed:sync`
- Removed duplicate `query_summary()` and `query_efetch()` methods
- Dropped `efetch` and `abstract` columns (summary data sufficient)
- Eliminated 311 lines of redundant code

**External Links:**
- Added clickable links for disease CURIEs (MONDO, OMIM, Orphanet)
- Added clickable links for gene HGNC IDs
- All links open in new tabs with proper security attributes

**Documentation:**
- Created consolidated DEVELOPMENT.md
- Archived historical/temporary documentation
- Reduced 8 root-level docs to 2 + this guide

### November 2025

**Async Upload Workflow:**
- Implemented polling-based progress (removed WebSocket dependency)
- Added `upload_state` to documents table
- Created `ProcessSubmissionsUpload` queue job
- Added job locking during uploads

**Disease System:**
- MONDO-first architecture with exact_match priority
- Added `deprecated_name` column for audit trail
- Implemented comprehensive reconciliation
- Name preservation for deprecated diseases

---

## Support

For questions or issues:
1. Check this documentation first
2. Review relevant legacy docs in `/archives`
3. Search closed issues in project repository
4. Contact the development team

**Last Updated:** December 3, 2025
