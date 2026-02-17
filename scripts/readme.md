# GenCC Scripts

This folder contains utility scripts for the GenCC Submission Portal.

## Contents

```text
scripts/
├── run_clingen_pipeline.py      # ClinGen data sync pipeline (v2.0)
├── start-dev-servers.sh         # Development server startup script
├── version.sh                   # Generate version string from git
├── process-logo.sh              # Process submitter logo images
├── backup/                      # Database backup and restore scripts
│   ├── backup-db.sh             # Production backup to GCS
│   ├── restore-db.sh            # Local development restore
│   ├── restore-db-from-gcs.sh   # Production restore from GCS
│   └── setup-backup-timer.sh    # Setup nightly backup systemd timer
├── clingen/                     # ClinGen pipeline Python package
│   ├── __init__.py
│   ├── config.py               # Centralized configuration
│   ├── logging.py              # Colored logging utilities
│   ├── models.py               # Data models (Submission, ComparisonResult)
│   ├── sources.py              # GeneGraph and GCI Express processors
│   ├── database.py             # Database exporter (pure Python)
│   ├── comparison.py           # Submission comparison logic
│   ├── output.py               # CSV/Excel output generation
│   └── pipeline.py             # Main pipeline orchestration
├── archived_clingen_scripts/    # Deprecated v1.0 scripts (to be removed)
└── readme.md                    # This file
```

---

## start-dev-servers.sh

Development server startup script that manages PM2 or Docker-based development environments.

### Usage

```bash
# Start locally with PM2 (default)
./scripts/start-dev-servers.sh

# Start with Docker/Podman (dev mode - volume mounted)
./scripts/start-dev-servers.sh docker

# Start production-like container for testing before deployment
./scripts/start-dev-servers.sh prod-test

# Stop all servers
./scripts/start-dev-servers.sh stop

# Show status
./scripts/start-dev-servers.sh status

# View logs
./scripts/start-dev-servers.sh logs

# Restore database before starting
./scripts/start-dev-servers.sh --restore
./scripts/start-dev-servers.sh local --restore
```

### Modes

| Mode        | Port | Description                                |
| ----------- | ---- | ------------------------------------------ |
| `local`     | 8001 | PM2-managed local processes                |
| `docker`    | 8001 | Dev container with volume-mounted source   |
| `prod-test` | 8080 | Production-like container (code baked in)  |

### Features

- Automatically detects podman-compose or docker-compose
- Checks and runs pending database migrations
- Clears application caches before starting
- Optional database restore from baseline backup
- **prod-test mode**: Tests production container behavior locally to catch permission issues before deployment

---

## Database Backup Scripts

Scripts for backing up and restoring the MySQL database, located in `scripts/backup/`.

### restore-db.sh (Local Development)

Restores the database from a baseline backup for local development.

```bash
# Interactive mode (prompts for confirmation)
./scripts/backup/restore-db.sh

# Non-interactive mode (for automated scripts)
./scripts/backup/restore-db.sh --no-confirm
```

**Features:**

- Reads database credentials from `.env` file
- Restores from `data/backups/gencc_sub_baseline_*.sql.gz`
- Runs pending migrations after restore
- Copies submitter logos to storage

### backup-db.sh (Production)

Backs up the MySQL database and uploads to Google Cloud Storage.

```bash
# Standard backup (uses environment defaults)
./scripts/backup/backup-db.sh

# Override bucket name
./scripts/backup/backup-db.sh --bucket my-bucket

# Dry run (test without uploading)
./scripts/backup/backup-db.sh --dry-run
```

**Environment variables** (set in `/etc/gencc/backup.env`):

| Variable           | Description                        | Default           |
| ------------------ | ---------------------------------- | ----------------- |
| `BACKUP_BUCKET`    | GCS bucket name                    | (required)        |
| `BACKUP_PREFIX`    | GCS path prefix                    | database-backups  |
| `BACKUP_RETENTION` | Days to keep local backups         | 7                 |
| `DB_HOST`          | Database host                      | 127.0.0.1         |
| `DB_DATABASE`      | Database name                      | gencc_sub         |

### restore-db-from-gcs.sh (Production)

Restores the database from a backup in Google Cloud Storage.

```bash
# List available backups
./scripts/backup/restore-db-from-gcs.sh --list

# Restore most recent backup
./scripts/backup/restore-db-from-gcs.sh --latest

# Restore specific backup
./scripts/backup/restore-db-from-gcs.sh --file gencc_sub-20260215-020000.sql.gz

# Restore from local file
./scripts/backup/restore-db-from-gcs.sh --local /path/to/backup.sql.gz
```

### setup-backup-timer.sh (Production Setup)

One-time setup script for nightly database backups on the production VM.

```bash
sudo ./scripts/backup/setup-backup-timer.sh --bucket gencc-backups
```

**What it does:**

1. Creates `/etc/gencc/backup.env` with configuration
2. Installs backup script to `/opt/gencc/bin/`
3. Sets up systemd service and timer for nightly backups at 2:00 AM UTC
4. Creates log rotation configuration
5. Verifies gcloud storage is configured correctly

**Prerequisites:**

- Root or sudo access
- gcloud CLI installed and configured
- MySQL client installed
- Service account with Storage Object Creator role

**Useful commands after setup:**

```bash
# Run backup manually
systemctl start gencc-backup-db.service

# Check timer status
systemctl status gencc-backup-db.timer
systemctl list-timers gencc-backup-db.timer

# View backup log
journalctl -u gencc-backup-db.service
```

---

## ClinGen Data Processing Pipeline (v2.0)

A comprehensive pipeline for synchronizing ClinGen gene validity data with the GenCC submission system.

### Overview

The pipeline:

1. Downloads and processes ClinGen gene validity data from GeneGraph
2. Downloads and processes GCI Express data from GitHub
3. Merges both datasets into a single target file
4. Exports database submissions for comparison
5. Compares target data with database to identify changes
6. Generates output files (CSV and Excel) for upload

### Prerequisites

```bash
# Required Python packages
pip install mysql-connector-python openpyxl
```

### Running the Pipeline

```bash
# Standard run (cleans up extracted folders)
python3 scripts/run_clingen_pipeline.py

# Keep extracted folders for debugging
python3 scripts/run_clingen_pipeline.py --keep-extracted

# Force re-download of source files
python3 scripts/run_clingen_pipeline.py --force-download
```

### From Laravel

The pipeline can be triggered from the Dashboard (ClinGen users only):

1. Click "GCI Sync Submissions" button
2. Pipeline runs and generates ZIP file
3. ZIP automatically downloads with Excel outputs

Or via artisan:

```bash
php artisan clingen:sync
```

### Pipeline Steps

#### Step 1: Process GeneGraph Data

- **Module**: `clingen.sources.GeneGraphProcessor`
- **Source**: https://storage.googleapis.com/genegraph-public/gene-validity-jsonld-latest.tar.gz
- **Output**: `data/clingen/gene_validity_processed.tsv`
- **Records**: ~3,386 gene-disease curations

#### Step 2: Process GCI Express Data

- **Module**: `clingen.sources.GCIExpressProcessor`
- **Source**: https://raw.githubusercontent.com/clingen-data-model/data-exchange-shared-json/master/json-from-gene-express/gci-express-with-entrez-ids.json
- **Output**: `data/clingen/gci_express_processed.tsv`
- **Records**: ~87 legacy curations

#### Step 3: Merge Source Files

- **Module**: `clingen.sources.merge_source_files()`
- **Output**: `data/clingen/gene_validity_with_gci_express.tsv`
- **Records**: Combined total (~3,473)

#### Step 4: Export Database Submissions

- **Module**: `clingen.database.DatabaseExporter`
- **Output**: `data/clingen/database_submissions_export.tsv`
- **Features**:
  - Pure Python (no PHP dependency)
  - Reads credentials from `.env` file
  - Filters by `is_most_recent=true` (all statuses included)
  - Ensures `local_key` uniqueness within submissions
  - Extracts data from `submission_data` JSON field

#### Step 5: Compare Target vs Database

- **Module**: `clingen.comparison`
- **Output**: `data/clingen/comparison/`
- **Matching Phases**:
  1. **By local_id**: Exact match on submission identifier
  2. **By GDM**: Match by gene+disease+MOI (handles ID changes)
  3. **New**: Target records not in database
  4. **Deleted**: Database records not in target

#### Steps 6-7: Generate Output Files

- **Module**: `clingen.output`
- **Outputs**:

| File | Description |
|------|-------------|
| `all_current_submissions.csv/xlsx` | All target records for full sync |
| `changed_submissions.csv/xlsx` | Only records with changes + new |
| `deleted_submissions_sgc.csv/xlsx` | Records to unpublish |

### Output Files

#### Primary Outputs

```
data/clingen/
├── gene_validity_with_gci_express.tsv      # Combined target
├── database_submissions_export.tsv          # Current database state
└── comparison/
    ├── all_current_submissions.csv          # All records (CSV)
    ├── all_current_submissions.xlsx         # All records (Excel)
    ├── changed_submissions.csv              # Changed + new (CSV)
    ├── changed_submissions.xlsx             # Changed + new (Excel)
    ├── deleted_submissions_sgc.csv          # To unpublish (CSV)
    ├── deleted_submissions.xlsx             # To unpublish (Excel)
    ├── comparison_summary.txt               # Statistics
    ├── updated_by_id.txt                    # Detailed differences
    ├── updated_by_gdm.txt                   # GDM matches
    ├── new_submissions.tsv                  # New records
    └── deleted_submissions.tsv              # Deleted records
```

#### Intermediate Files

```
data/clingen/
├── gene_validity_processed.tsv              # GeneGraph data only
├── gci_express_processed.tsv                # GCI Express data only
└── gci_express_data.json                    # Downloaded JSON

# Auto-deleted after processing (unless --keep-extracted):
├── gene_validity_extracted/                 # JSON files (~3,387)
└── gci_express_extracted/                   # JSON files (87)
```

### Configuration

Configuration is centralized in `clingen/config.py`:

```python
from clingen.config import (
    SourceConfig,      # URLs and paths for source data
    OutputConfig,      # Output file paths
    DatabaseConfig,    # Database connection settings
    CLASSIFICATION_ID_MAP,  # Classification mappings
    SOP_VERSION_URL_MAP,    # SOP version URLs
)
```

#### Database Configuration

The database exporter reads from the project's `.env` file:

```env
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=gencc_sub
DB_USERNAME=your_user
DB_PASSWORD=your_pass
```

### Key Features

#### Data Transformations

- **IDs**: All uppercased (HGNC, MONDO, HP, GENCC prefixes)
- **Dates**: YYYY/MM/DD format
- **Classifications**: Mapped to GENCC:100001-100009
- **PMIDs**: Comma-separated list

#### Classification Mappings

```
Definitive                    → GENCC:100001
Strong                        → GENCC:100002
Moderate                      → GENCC:100003
Limited                       → GENCC:100004
Disputed Evidence             → GENCC:100005
Contradictory (disputed)      → GENCC:100005
Refuted Evidence              → GENCC:100006
Contradictory (refuted)       → GENCC:100006
Animal Model Only             → GENCC:100007
No Known Disease Relationship → GENCC:100008
No Reported Evidence          → GENCC:100008
Supportive                    → GENCC:100009
```

### Output File Format

CSV/Excel files use the GenCC Download format:

| Column | Description |
|--------|-------------|
| SGC ID | GenCC submission ID (blank for new) |
| Action | 'N' (new), 'R' (republish), 'U' (unpublish) |
| Submission ID | Local key / ClinGen identifier |
| HGNC ID | Gene identifier |
| Gene Symbol | (blank - looked up on import) |
| Disease ID (MONDO) | MONDO identifier |
| Disease Name | (blank - looked up on import) |
| Mode of Inheritance ID | HP term |
| Mode of Inheritance Name | (blank - looked up on import) |
| Submitter ID | GENCC:000102 |
| Submitter Name | (blank - looked up on import) |
| Classification ID | GENCC classification code |
| Classification Name | (blank - looked up on import) |
| Report Date | YYYY/MM/DD |
| Public Report URL | Link to evidence |
| Notes | Public curation notes |
| PubMed IDs | Comma-separated |
| Assertion Criteria URL | SOP version link |

### Versioning and is_most_recent

The database export filters by `is_most_recent=true` to ensure:

- Only the most recent version of each submission (SID) is exported
- Historical versions (older version numbers) are excluded from matching
- `local_key` uniqueness within live submissions
- Accurate change detection when comparing against external sources

### Requirements

- Python 3.x
- `mysql-connector-python` (for database export)
- `openpyxl` (for Excel generation)
- Internet connection (for data downloads)

### Troubleshooting

#### Re-download Data

Delete cached files to force re-download:

```bash
rm data/clingen/gene-validity-jsonld-latest.tar.gz
rm data/clingen/gci_express_data.json
```

#### Check Individual Steps

Import and run modules directly:

```python
from clingen.sources import GeneGraphProcessor
from clingen.database import export_database_submissions
from clingen.comparison import run_comparison

# Run individual steps
genegraph = GeneGraphProcessor()
genegraph.run()
```

#### Verify Outputs

```bash
# Check record counts
wc -l data/clingen/*.tsv

# Check comparison summary
cat data/clingen/comparison/comparison_summary.txt
```

### Data Sources

- **ClinGen Gene Validity**: https://storage.googleapis.com/genegraph-public/gene-validity-jsonld-latest.tar.gz
- **GCI Express**: https://github.com/clingen-data-model/data-exchange-shared-json/blob/master/json-from-gene-express/gci-express-with-entrez-ids.json

---

## Archived Scripts

The `archived_clingen_scripts/` folder contains deprecated v1.0 scripts that have been replaced by the modular `clingen` package. These are retained for reference only and will be removed in a future release.

| Legacy Script | Replaced By |
|--------------|-------------|
| `process_clingen_data.py` | `clingen.sources.GeneGraphProcessor` |
| `process_gci_express_data.py` | `clingen.sources.GCIExpressProcessor` |
| `convert_gci_express_to_tsv.py` | (merged into GCIExpressProcessor) |
| `export_clingen_submissions.php` | `clingen.database.DatabaseExporter` |
| `compare_clingen_submissions.py` | `clingen.comparison` |
| `generate_merged_submissions.py` | `clingen.output` |
| `run_clingen_full_process.py` | `run_clingen_pipeline.py` |
