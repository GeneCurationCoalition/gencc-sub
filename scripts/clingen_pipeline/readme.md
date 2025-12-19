# ClinGen Data Processing Pipeline

This pipeline processes ClinGen gene validity data and GCI Express data, compares it with the database, and generates submission files for upload.

## Quick Start

Run the entire pipeline with a single command:

```bash
python3 scripts/clingen_pipeline/run_clingen_full_process.py
```

To keep the extracted JSON folders for debugging:

```bash
python3 scripts/clingen_pipeline/run_clingen_full_process.py --keep-extracted
```

By default, the extracted folders are automatically removed after processing to save disk space.

## Pipeline Steps

The pipeline executes 7 steps automatically:

### Step 1: Process ClinGen Gene Validity Data
- **Script**: `scripts/clingen_pipeline/process_clingen_data.py`
- **Downloads**: ClinGen gene validity JSON files from Google Storage
- **Processes**: 3,359 records from `gene-validity-jsonld-latest.tar.gz`
- **Output**: `data/clingen/gene_validity_processed.tsv`
- **Extracted to**: `data/clingen/gene_validity_extracted/` (auto-deleted after processing)
- **Features**:
  - Extracts 12 fields per record (including notes)
  - Maps classifications to GENCC IDs
  - Transforms disease IDs (MONDO) and MOI (HP terms)
  - Uppercases all ID fields
  - Maps SOP versions to assertion criteria URLs
  - Properly escapes TSV fields using CSV writer

### Step 2: Download GCI Express Data
- **Script**: `scripts/clingen_pipeline/process_gci_express_data.py`
- **Downloads**: GCI Express JSON from GitHub
- **Source**: `clingen-data-model/data-exchange-shared-json`
- **Processes**: 87 records from `gci-express-with-entrez-ids.json`
- **Output**:
  - `data/clingen/gci_express_data.json` (downloaded)
  - `data/clingen/gci_express_extracted/` (individual JSON files, auto-deleted after processing)

### Step 3: Convert GCI Express to TSV
- **Script**: `scripts/clingen_pipeline/convert_gci_express_to_tsv.py`
- **Input**: Extracted GCI Express JSON files
- **Output**: `data/clingen/gci_express_processed.tsv`
- **Features**:
  - Converts to same format as gene validity data
  - Extracts MOI and PMIDs from nested JSON
  - Maps classifications (including "Contradictory" variants)
  - Generates public report URLs: `CGGCIEX:assertion_XXXX`
  - Determines SOP version (4 or 5) based on data presence

### Step 4: Merge Gene Validity and GCI Express
- **Function**: Built into master script
- **Combines**:
  - Gene validity: 3,359 records
  - GCI Express: 87 records
- **Output**: `data/clingen/gene_validity_with_gci_express.tsv` (3,446 records)
- **Note**: This combined file is the **target state** for comparison

### Step 5: Export Database Submissions
- **Script**: `scripts/clingen_pipeline/export_clingen_submissions.php`
- **Queries**: Laravel database for GENCC:000102 (ClinGen) submissions
- **Output**: `data/clingen/database_submissions_export.tsv`
- **Features**:
  - Includes SGC IDs for matching
  - Uses UI Download format logic
  - Pulls dates from JSON fields (not database columns)

### Step 6: Compare Target vs Database
- **Script**: `scripts/clingen_pipeline/compare_clingen_submissions.py`
- **Compares**: Combined target (3,446) vs Database (varies)
- **Output**: `data/clingen/comparison/`
  - `comparison_summary.txt` - Overview statistics
  - `updated_by_id.txt` - Records matched by local_id with differences
  - `updated_by_gdm.txt` - Records matched by gene+disease+MOI
  - `new_submissions.tsv` - Records only in target
  - `deleted_submissions.tsv` - Records only in database

**Categorization**:
- **Updated by ID**: Same local_id in both files
- **Updated by GDM**: Same gene+disease+MOI but different local_id
- **New Submissions**: In target but not in database
- **Deleted Submissions**: In database but not in target

### Step 7: Generate Merged Submission Files
- **Script**: `scripts/clingen_pipeline/generate_merged_submissions.py`
- **Output**:
  - `merged_submissions.csv` - All submissions for upload
  - `deleted_submissions_sgc.csv` - Submissions to delete

**Format**: Download submission format (17 columns)

**SGC ID Logic**:
- Updated records (by ID or GDM): **Include SGC ID** from database
- New submissions: **Blank SGC ID**
- Name/label fields: **Blank** (per requirements)

## Output Files

### Primary Outputs
```
data/clingen/
├── gene_validity_with_gci_express.tsv      # Combined target (3,446 records)
├── database_submissions_export.tsv          # Current database state
└── comparison/
    ├── merged_submissions.csv               # → Upload to system
    ├── deleted_submissions_sgc.csv          # → Process deletions
    ├── comparison_summary.txt               # Statistics
    ├── updated_by_id.txt                    # Detailed differences
    ├── updated_by_gdm.txt                   # GDM matches
    ├── new_submissions.tsv                  # New records
    └── deleted_submissions.tsv              # Deleted records
```

### Intermediate Files
```
data/clingen/
├── gene_validity_processed.tsv              # ClinGen data only
├── gci_express_processed.tsv                # GCI Express data only
└── gci_express_data.json                    # Downloaded JSON

# Auto-deleted after processing (unless --keep-extracted is used):
├── gene_validity_extracted/                 # ClinGen JSON files (3,360 files)
└── gci_express_extracted/                   # GCI Express JSON files (87 files)
```

## Individual Script Usage

You can run scripts individually for testing:

```bash
# Step 1: Process ClinGen gene validity
python3 scripts/clingen_pipeline/process_clingen_data.py

# Step 2: Download GCI Express
python3 scripts/clingen_pipeline/process_gci_express_data.py

# Step 3: Convert GCI Express to TSV
python3 scripts/clingen_pipeline/convert_gci_express_to_tsv.py

# Step 4: Merge is built into master script
# (Manual: cat gene_validity_processed.tsv > combined.tsv && tail -n +2 gci_express_processed.tsv >> combined.tsv)

# Step 5: Export database
php scripts/clingen_pipeline/export_clingen_submissions.php

# Step 6: Compare
python3 scripts/clingen_pipeline/compare_clingen_submissions.py

# Step 7: Generate merged
python3 scripts/clingen_pipeline/generate_merged_submissions.py
```

## Key Features

### Data Transformations
- **IDs**: All uppercased (HGNC, MONDO, HP, GENCC prefixes)
- **Dates**: YYYY/MM/DD format
- **Classifications**: Mapped to GENCC:100001-100009
- **PMIDs**: Comma-separated list

### Classification Mappings
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

### SOP Version to URL Mappings
```
GeneValidityCriteria4-11      → Specific documentation URLs
GCI Express (has SOP5 data)   → Version 5 URL
GCI Express (empty SOP5 data) → Version 4 URL
```

## Requirements

- Python 3.x
- PHP 8.1+ (for database export)
- Laravel application with database access
- Internet connection (for data downloads)

## Troubleshooting

### Re-download Data
Delete cached files to force re-download:
```bash
rm data/clingen/gene-validity-jsonld-latest.tar.gz
rm data/clingen/gci_express_data.json
```

### Check Individual Steps
Run scripts individually to isolate issues

### Verify Outputs
```bash
# Check record counts
wc -l data/clingen/*.tsv

# Check for empty classification IDs
awk 'BEGIN{FS="\t"} NR>1 && $6=="" {print}' data/clingen/gci_express_processed.tsv
```

## Notes

- The pipeline can be run multiple times; existing files are skipped
- All log output goes to stderr for easy redirection
- The combined target file is regenerated on each run
- SGC IDs are preserved from database for matching records
- GCI Express uses numeric IDs (legacy system identifiers)
- **Extracted folders are automatically deleted** after processing to save disk space (~400MB)
  - Use `--keep-extracted` flag to retain them for debugging
- TSV files use proper CSV escaping to handle special characters in notes field

## Data Sources

- **ClinGen Gene Validity**: https://storage.googleapis.com/genegraph-public/gene-validity-jsonld-latest.tar.gz
- **GCI Express**: https://github.com/clingen-data-model/data-exchange-shared-json/blob/master/json-from-gene-express/gci-express-with-entrez-ids.json
