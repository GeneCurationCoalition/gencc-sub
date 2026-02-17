# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

GenCC Submission Portal - A Laravel 10 + Inertia.js + Vue 3 application for managing gene-disease relationship submissions. This system processes both API and file-based submissions, validates them against external data sources (HGNC, MONDO, OMIM), and publishes curated data.

## Technology Stack

- **Backend**: Laravel 10 (PHP 8.1+)
- **Frontend**: Vue 3 + Inertia.js + PrimeVue
- **Styling**: Tailwind CSS
- **Build**: Vite
- **Authentication**: Laravel Jetstream with Sanctum
- **Broadcasting**: Ably (configurable)
- **Data Import/Export**: Maatwebsite Excel
- **Permissions**: Spatie Laravel Permission

## Development Commands

### Setup & Dependencies
```bash
composer install          # Install PHP dependencies
npm install              # Install JavaScript dependencies
php artisan key:generate # Generate application key (if needed)
php artisan migrate      # Run database migrations
```

### Development
```bash
npm run dev              # Start Vite dev server with hot reload
php artisan serve        # Start Laravel development server

# Run both for full development environment:
# Terminal 1: npm run dev
# Terminal 2: php artisan serve
```

### Build
```bash
npm run build           # Build production assets
```

### Testing
```bash
php artisan test                    # Run all tests
./vendor/bin/phpunit               # Run PHPUnit directly
./vendor/bin/phpunit --filter=TestName  # Run specific test
./vendor/bin/phpunit tests/Unit           # Run only unit tests
./vendor/bin/phpunit tests/Feature        # Run only feature tests
```

### Code Quality
```bash
./vendor/bin/pint       # Format code with Laravel Pint
```

## Key Console Commands

The application includes several custom artisan commands for data management:

### Import Commands
```bash
php artisan import:gencc            # Import GenCC curated data
php artisan import:genes            # Import gene data from HGNC
php artisan import:diseases         # Import disease data from MONDO
php artisan import:clingen          # Import ClinGen data
php artisan import:submission       # Import submissions from file
php artisan import:tables           # Import lookup tables
```

### Update Commands
```bash
php artisan update:genes            # Update gene information from HGNC
php artisan update:diseases         # Update disease information
php artisan update:omim             # Update OMIM disease mappings
php artisan update:pubmed           # Update PubMed article data
php artisan update:sources          # Update source data
php artisan update:search-submissions  # Update submission search index
```

### Processing Commands
```bash
php artisan process:submissions     # Process pending submissions
php artisan pubmed:query           # Query PubMed for new articles
php artisan pubmed:efetch          # Fetch PubMed article details
php artisan pubmed:sync            # Sync PubMed data (replaces legacy check/fix/back/past commands)
php artisan pubmed:status          # Show PubMed sync status
```

### Operational Commands
```bash
php artisan run:metrics            # Generate metrics reports
php artisan run:notify             # Send notifications
php artisan run:publish            # Publish staged submissions
php artisan gencc:release process  # Process and release pending jobs
php artisan gencc:release repair   # Repair incomplete release (regenerate outputs)
php artisan add:user              # Add new user
php artisan make-prod-db          # Create production database dump
```

### Debugging
```bash
php artisan debug:document-processor  # Debug document processing
```

## Architecture Overview

### Core Models & Relationships

**Job** (`app/Models/Job.php`) - Groups one or more submissions together
- `hasMany` Submission
- `hasMany` Document
- `hasMany` Action
- `belongsTo` User (submitter)
- `belongsTo` Submitter (organization)
- Auto-generates slug as `J-1XXXXX` format
- Status workflow: Initializing → Queued → Processing → Complete/Errors → Staged → Published/Removed

**Submission** (`app/Models/Submission.php`) - Individual gene-disease curation records
- `belongsTo` Job
- `belongsTo` Gene, Disease, Classification, Inheritance, Mechanism
- `belongsToMany` Pubmed
- Auto-generates sid as `SGC-1XXXXX` format
- JSON fields: `submission_data`, `original_submission_data`, `submission_errors`, `evidence`, `history`
- Status workflow: Initializing → New → Processing → Errors/Published → Removed

**Gene** - HGNC gene data
**Disease** - Disease ontology data (MONDO, OMIM)
**Classification** - Curation classification (Definitive, Strong, Moderate, etc.)
**Inheritance** - Mode of Inheritance (HP terms)
**Mechanism** - Mechanism of disease

### API Endpoints

**Submission API** (`routes/api.php`)
```
POST /api/submit/{action?}          # Submit new data (action: 'check' validates only)
GET  /api/submit/{id}               # Query submission by ID
GET  /api/query/job/{id}            # Query job status
GET  /api/submit/{id}/status        # Get submission status
GET  /api/submit/{id}/remove        # Remove submission
```

**Internal API** (requires web middleware)
```
GET  /api/lookup/gene/{id}          # Validate gene HGNC ID
GET  /api/lookup/disease/{id}       # Validate disease ID
POST /api/submissions/{id}          # Update submission
POST /api/jobs/{id}                 # Update job
GET  /api/jobs/publish/{id}         # Publish job
GET  /api/jobs/unpublish/{id}       # Unpublish job
POST /api/documents/{id}            # Upload documents
```

### Web Routes

All authenticated routes use Sanctum + Jetstream auth:
```
/dashboard                          # Main dashboard
/jobs                              # List all jobs
/jobs/{id}                         # Job details
/submissions                       # List all submissions
/submissions/{id}                  # Submission details
/profile                           # User profile
/aliases                           # Manage aliases
```

### Frontend Structure

**Pages** (`resources/js/Pages/`)
- Dashboard.vue - Main dashboard with metrics
- Jobs.vue, Job.vue - Job listing and detail views
- Submissions.vue, Submission.vue - Submission listing and detail views
- Profile.vue - User profile management
- Aliases.vue - Alias management

**Components** (`resources/js/Components/`)
- Change*.vue - Modal dialogs for editing submission fields (ChangeGene.vue, ChangeDisease.vue, etc.)
- Input*.vue - Form input components with dialogs
- JobsListing.vue - Reusable job listing component
- Dashboard.vue - Dashboard widget component

### Key Services

**SubmissionColumns** (`app/Services/SubmissionColumns.php`) - Defines submission data structure and validation

### Jobs & Queues

**ProcessUpload** - Handles file upload processing
**ProcessPubmed** - Fetches PubMed article metadata

### Imports

**SubmissionImport** (`app/Imports/SubmissionImport.php`) - Excel submission file import using Maatwebsite Excel
**GenccImport** - Import existing GenCC data

### Broadcasting

Uses Ably for real-time updates (SpreadsheetUpdate event)

## Data Flow

1. **Submission Entry**: API POST or Excel upload
2. **Validation**: Gene (HGNC), Disease (MONDO/OMIM), MOI (HP), Classification validated via `load_from_json()`
3. **Job Creation**: Creates Job record with status QUEUED
4. **Processing**: Background processing validates data, fetches PubMed metadata
5. **Curation**: User reviews/edits via web portal
6. **Publishing**: Staged submissions published via `run:publish` command
7. **Export**: Published data available for downstream systems

## Important Implementation Details

### Submission Validation (`Submission::load_from_json()`)

The submission model validates against:
- **Gene**: Must match HGNC ID in genes table
- **Disease**: Must match ID in diseases table (supports MONDO, OMIM, ORPHA via rosetta method)
- **MOI** (Mode of Inheritance): Must match HP term in inheritances table
- **Classification**: Must match GENCC classification term
- **Report Date**: Required, must be valid date format
- **Report URL**: Optional, must be valid URL format
- **PMIDs**: Validated as numeric, automatically fetched via PubMed API

Invalid values are added to `submission_errors` JSON field and default placeholder values are used.

### ID Generation

Both Job and Submission models use Laravel model events to auto-generate slugs after creation:
- Job: `J-1` + 5-digit zero-padded ID
- Submission: `SGC-1` + 5-digit zero-padded ID

### Status Constants

Use model constants (not magic numbers):
```php
Job::STATUS_DRAFT, Job::STATUS_SUBMITTED, Job::STATUS_PROCESSED
Submission::STATUS_DRAFT_NEW, Submission::STATUS_SUBMITTED_NEW, Submission::STATUS_PUBLISHED, etc.
```

### Soft Deletes

Both Job and Submission models use soft deletes - always consider `withTrashed()` when needed.

## Database Structure

Key tables:
- `jobs` - Job records
- `submissions` - Submission records
- `genes` - HGNC gene data
- `diseases` - Disease ontology data
- `classifications` - Curation classifications
- `inheritances` - Mode of inheritance terms
- `mechanisms` - Mechanism of disease
- `pubmeds` - PubMed article metadata
- `pubmed_submission` - Pivot table linking submissions to PMIDs
- `documents` - Uploaded files
- `actions` - Audit trail

## Environment Configuration

Key .env variables:
- Database connection settings
- `BROADCAST_DRIVER=ably` (or pusher/null)
- `ABLY_KEY` for real-time updates
- API keys for external services (HGNC, OMIM, PubMed)

## Development Workflow Notes

- Frontend uses Inertia.js - no separate API calls for page data, props passed from controllers
- PrimeVue components used extensively (DataTable, Dialog, etc.)
- Form validation uses vee-validate + yup
- Real-time updates via Laravel Echo + Ably broadcasting
- Excel imports/exports via Maatwebsite Excel package
- Authentication via Jetstream with team support

## Deployment

The application deploys to GCP using a two-phase approach: **Terraform** for infrastructure provisioning, then **Ansible** for VM configuration and application deployment.

### Directory Structure

```
deployment/
├── terraform/           # GCP infrastructure (VPC, VM, firewall)
├── ansible/             # VM configuration and app deployment
│   ├── playbooks/       # Main entrypoint (site.yml)
│   ├── inventories/     # Per-environment inventories + group_vars
│   └── roles/           # Ordered role execution
└── supervisor/          # Legacy supervisor configs
```

### Phase 1: Terraform (Infrastructure)

Provisions GCP resources via `deployment/terraform/`:

- **Network**: Dedicated VPC + subnet with configurable CIDR
- **Compute**: Ubuntu VM with static external IP
- **Firewall**: HTTP/HTTPS ingress (80/443), IAP SSH (22 from 35.235.240.0/20)

**Key Terraform Variables** (per-environment `.tfvars`):
| Variable | Description |
|----------|-------------|
| `project_id` | GCP project ID |
| `region`, `zone` | GCP location (e.g., `us-east1`, `us-east1-b`) |

**Usage**:
```bash
cd deployment/terraform
terraform init && terraform plan && terraform apply
```

### Phase 2: Ansible (Configuration & Deployment)

Configures the VM and deploys containers via `deployment/ansible/`. The playbook runs roles in order:

| Role | Purpose |
|------|---------|
| `base` | System packages, `gencc` user, podman, directories, linger |
| `ops_scripts` | Operational helper scripts |
| `mysql` | Host MySQL server, users, and grants |
| `quadlet` | Podman containers as systemd Quadlet units |
| `db_bootstrap` | Database restore and/or Laravel migrations |
| `nginx_tls` | nginx reverse proxy + certbot TLS (Let's Encrypt HTTP-01) |
| `timers` | Systemd timers for scheduled tasks |

**Inventory structure** — variables are layered: `group_vars/all/` (shared defaults) → `group_vars/<env>/` (per-environment overrides + vault):

| Scope | Key Variables |
|-------|--------------|
| `all/vars.yml` | `gencc_db_bootstrap_mode`, `gencc_enable_letsencrypt`, ports, paths, MySQL users |
| `staging/vars.yml` | `gencc_sub_image`, `gencc_search_image`, hostnames, cert name, DB restore settings |
| `production/vars.yml` | hostnames, cert name, image overrides |
| `<env>/vault.yml` | MySQL passwords, `.env` file contents, backup config |

**Usage**:
```bash
cd deployment/ansible
# Staging
ansible-playbook -i inventories/staging.ini playbooks/site.yml --ask-vault-pass
# Production
ansible-playbook -i inventories/production.ini playbooks/site.yml --ask-vault-pass
```

### Architecture Notes

- **Rootless Podman**: Containers run under a dedicated `gencc` user with `loginctl enable-linger`
- **Container → Host MySQL**: Containers connect via slirp4netns gateway (`DB_HOST=10.0.2.2`)
- **TLS Termination**: nginx on the VM terminates TLS; containers serve HTTP on localhost ports
- **SSH Access**: IAP tunneling + OS Login (requires `roles/iap.tunnelResourceAccessor` and `roles/compute.osAdminLogin`)
- **Scheduled Tasks**: Systemd timers for gene updates, disease updates, PubMed sync, and database backups
