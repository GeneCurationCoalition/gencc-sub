#!/usr/bin/env python3
"""
Generate Merged Submissions File

Creates a merged submission file in the Download format with:
- Updated records (by ID and GDM): Include SGC ID from database
- New submissions: Blank SGC ID
- Deleted submissions: Separate file with SGC IDs

Uses values from gene_validity_processed.tsv (target) for all data fields.
Name/label fields are left blank.
"""

import csv
from pathlib import Path
from typing import Dict, List
import sys

# Configuration
SCRIPTS_DIR = Path(__file__).parent
DATA_DIR = SCRIPTS_DIR.parent.parent / "data" / "clingen"
TARGET_FILE = DATA_DIR / "gene_validity_with_gci_express.tsv"
DATABASE_FILE = DATA_DIR / "database_submissions_export.tsv"
COMPARISON_DIR = DATA_DIR / "comparison"
OUTPUT_FILE = COMPARISON_DIR / "all_current_submissions.csv"
DELETED_FILE = COMPARISON_DIR / "deleted_submissions_sgc.csv"
CHANGED_FILE = COMPARISON_DIR / "changed_submissions.csv"

# Download format columns (with Action as 2nd column)
DOWNLOAD_COLUMNS = [
    'SGC ID',
    'Action',
    'Submission ID',
    'HGNC ID',
    'Gene Symbol',
    'Disease ID (MONDO)',
    'Disease Name',
    'Mode of Inheritance ID',
    'Mode of Inheritance Name',
    'Submitter ID',
    'Submitter Name',
    'Classification ID',
    'Classification Name',
    'Report Date',
    'Public Report URL',
    'Notes',
    'PubMed IDs',
    'Assertion Criteria URL'
]

# Source TSV columns for target file
TARGET_TSV_FIELDS = [
    'local_id',
    'gene_id',
    'disease_id',
    'mode_of_inheritance',
    'submitter_id',
    'classification_id',
    'classification',
    'report_date',
    'public_report_url',
    'notes',
    'pubmed_ids',
    'assertion_criteria_url'
]

# Source TSV columns for database file (includes sgc_id)
DATABASE_TSV_FIELDS = [
    'sgc_id',
    'local_id',
    'gene_id',
    'disease_id',
    'mode_of_inheritance',
    'submitter_id',
    'classification_id',
    'classification',
    'report_date',
    'public_report_url',
    'notes',
    'pubmed_ids',
    'assertion_criteria_url'
]


class Colors:
    """Terminal colors for output"""
    GREEN = '\033[0;32m'
    YELLOW = '\033[1;33m'
    RED = '\033[0;31m'
    BLUE = '\033[0;34m'
    NC = '\033[0m'  # No Color


def log_info(message: str) -> None:
    """Log an info message"""
    print(f"{Colors.GREEN}[INFO]{Colors.NC} {message}")


def log_section(message: str) -> None:
    """Log a section header"""
    print(f"\n{Colors.BLUE}{'=' * 80}")
    print(f"{message}")
    print(f"{'=' * 80}{Colors.NC}\n")


def has_changes(target: Dict, database: Dict) -> bool:
    """
    Compare two records and return True if there are differences (excluding sgc_id)

    Args:
        target: Target record dictionary
        database: Database record dictionary

    Returns:
        True if records differ, False otherwise
    """
    # Fields to compare (excluding local_id and sgc_id which are identifiers)
    # Note: Excludes 'classification' (label) and 'private_note' (can have private notes)
    # Includes 'public_note' to detect changes in public curation notes
    compare_fields = [
        'gene_id',
        'disease_id',  # Full CURIE format comparison
        'mode_of_inheritance',
        'submitter_id',
        'classification_id',  # Compare ID only, not label
        'report_date',
        'public_report_url',
        'public_note',  # Compare public notes for changes
        'pubmed_ids',
        'assertion_criteria_url'
    ]

    for field in compare_fields:
        target_val = target.get(field, '').strip()
        db_val = database.get(field, '').strip()

        # Normalize empty values
        if not target_val:
            target_val = ''
        if not db_val:
            db_val = ''

        if target_val != db_val:
            return True

    return False


def load_tsv_as_dict(filepath: Path, key_field: str, fields: List[str]) -> Dict[str, Dict]:
    """Load TSV file and return as dictionary keyed by specified field"""
    result = {}

    with open(filepath, 'r', encoding='utf-8') as f:
        reader = csv.DictReader(f, delimiter='\t', fieldnames=fields)
        next(reader)  # Skip header

        for row in reader:
            key = row[key_field]
            result[key] = row

    return result


def load_database_sgc_mapping(filepath: Path) -> Dict[str, str]:
    """
    Load database file and create mapping of local_id -> SGC ID

    Database file format: sgc_id, local_id, ...
    """
    mapping = {}

    with open(filepath, 'r', encoding='utf-8') as f:
        reader = csv.DictReader(f, delimiter='\t', fieldnames=DATABASE_TSV_FIELDS)
        next(reader)  # Skip header

        for row in reader:
            local_id = row['local_id']
            sgc_id = row['sgc_id']
            mapping[local_id] = sgc_id

    return mapping


def map_to_download_format(target_record: Dict, sgc_id: str = '', action: str = '') -> List[str]:
    """
    Map a target record to Download format

    Args:
        target_record: Record from gene_validity_processed.tsv or database
        sgc_id: SGC ID from database (empty for new submissions)
        action: Action code - 'U' (unpublish), 'N' (new), 'R' (republish)

    Returns:
        List of values in Download format
    """
    # For unpublish action, only return SGC ID and Action, rest are empty
    if action == 'U':
        return [
            sgc_id,  # SGC ID
            action,  # Action
            '',  # Submission ID (empty for unpublish)
            '',  # HGNC ID (empty)
            '',  # Gene Symbol (empty)
            '',  # Disease ID (empty)
            '',  # Disease Name (empty)
            '',  # MOI ID (empty)
            '',  # MOI Name (empty)
            '',  # Submitter ID (empty)
            '',  # Submitter Name (empty)
            '',  # Classification ID (empty)
            '',  # Classification Name (empty)
            '',  # Report Date (empty)
            '',  # Public Report URL (empty)
            '',  # Notes (empty)
            '',  # PubMed IDs (empty)
            ''   # Assertion Criteria URL (empty)
        ]

    # For new and republish actions, include all data
    # Note: Try 'public_note' first (from comparison output), fall back to 'notes' (from target file)
    notes_value = target_record.get('public_note', target_record.get('notes', ''))

    return [
        sgc_id,  # SGC ID (blank for new, from DB for republish)
        action,  # Action ('N' for new, 'R' for republish)
        target_record.get('local_id', ''),  # Submission ID
        target_record.get('gene_id', ''),  # HGNC ID
        '',  # Gene Symbol (blank)
        target_record.get('disease_id', ''),  # Disease ID
        '',  # Disease Name (blank)
        target_record.get('mode_of_inheritance', ''),  # MOI ID
        '',  # MOI Name (blank)
        target_record.get('submitter_id', ''),  # Submitter ID
        '',  # Submitter Name (blank)
        target_record.get('classification_id', ''),  # Classification ID
        '',  # Classification Name (blank)
        target_record.get('report_date', ''),  # Report Date
        target_record.get('public_report_url', ''),  # Public Report URL
        notes_value,  # Public Note (try 'public_note' first, fall back to 'notes')
        target_record.get('pubmed_ids', ''),  # PubMed IDs
        target_record.get('assertion_criteria_url', '')  # Assertion Criteria URL
    ]


def escape_csv_value(value: str) -> str:
    """Escape CSV values that contain commas, quotes, or newlines"""
    value_str = str(value)
    if ',' in value_str or '"' in value_str or '\n' in value_str:
        return '"' + value_str.replace('"', '""') + '"'
    return value_str


def main():
    """Main execution"""
    log_section("Generate Merged Submissions File")

    # Load source files
    log_info(f"Loading target file: {TARGET_FILE}")
    target_by_id = load_tsv_as_dict(TARGET_FILE, 'local_id', TARGET_TSV_FIELDS)
    log_info(f"  Loaded {len(target_by_id)} target records")

    log_info(f"Loading database file: {DATABASE_FILE}")
    database_by_id = load_tsv_as_dict(DATABASE_FILE, 'local_id', DATABASE_TSV_FIELDS)
    database_sgc_map = load_database_sgc_mapping(DATABASE_FILE)
    log_info(f"  Loaded {len(database_by_id)} database records")

    # Load comparison results
    log_info("Loading comparison results...")

    # Read comparison files to understand categorization
    updated_by_id = []
    updated_by_gdm = []
    new_submissions = []
    deleted_submissions = []

    # Parse updated_by_id.txt to get the list
    updated_id_file = COMPARISON_DIR / "updated_by_id.txt"
    if updated_id_file.exists():
        with open(updated_id_file, 'r') as f:
            for line in f:
                if line.startswith('Local ID: '):
                    local_id = line.replace('Local ID: ', '').strip()
                    updated_by_id.append(local_id)

    # Parse updated_by_gdm.txt to get the mappings
    updated_gdm_file = COMPARISON_DIR / "updated_by_gdm.txt"
    gdm_mappings = {}  # target_local_id -> db_local_id
    if updated_gdm_file.exists():
        with open(updated_gdm_file, 'r') as f:
            target_id = None
            db_id = None
            for line in f:
                if line.startswith('Target Local ID: '):
                    target_id = line.replace('Target Local ID: ', '').strip()
                elif line.startswith('Database Local ID: '):
                    db_id = line.replace('Database Local ID: ', '').strip()
                    if target_id and db_id:
                        gdm_mappings[target_id] = db_id
                        updated_by_gdm.append(target_id)
                        target_id = None
                        db_id = None

    # Load new submissions
    new_submissions_file = COMPARISON_DIR / "new_submissions.tsv"
    if new_submissions_file.exists():
        with open(new_submissions_file, 'r') as f:
            reader = csv.DictReader(f, delimiter='\t', fieldnames=TARGET_TSV_FIELDS)
            next(reader)  # Skip header
            for row in reader:
                new_submissions.append(row['local_id'])

    # Load deleted submissions (includes sgc_id)
    deleted_submissions_file = COMPARISON_DIR / "deleted_submissions.tsv"
    if deleted_submissions_file.exists():
        with open(deleted_submissions_file, 'r') as f:
            reader = csv.DictReader(f, delimiter='\t', fieldnames=DATABASE_TSV_FIELDS)
            next(reader)  # Skip header
            for row in reader:
                deleted_submissions.append(row)

    log_info(f"  Updated by ID: {len(updated_by_id)}")
    log_info(f"  Updated by GDM: {len(updated_by_gdm)}")
    log_info(f"  New submissions: {len(new_submissions)}")
    log_info(f"  Deleted submissions: {len(deleted_submissions)}")

    # Generate all current submissions file
    log_section("Generating All Current Submissions File")

    all_current_records = []
    unchanged_count = 0

    # Add ALL target records (from GeneGraph/GCI Express)
    # This ensures all_current_submissions contains everything regardless of changes
    for local_id, target_record in target_by_id.items():
        # Determine action based on categorization
        if local_id in new_submissions:
            # New submission - blank SGC ID, Action='N'
            all_current_records.append(map_to_download_format(target_record, '', 'N'))
        elif local_id in updated_by_id:
            # Updated by ID - use DB SGC ID, Action='R'
            sgc_id = database_sgc_map.get(local_id, '')
            all_current_records.append(map_to_download_format(target_record, sgc_id, 'R'))
        elif local_id in updated_by_gdm:
            # Updated by GDM - use mapped DB SGC ID, Action='R'
            db_local_id = gdm_mappings.get(local_id, '')
            sgc_id = database_sgc_map.get(db_local_id, '')
            all_current_records.append(map_to_download_format(target_record, sgc_id, 'R'))
        else:
            # Unchanged record - use DB SGC ID, default Action='R' (Republish)
            sgc_id = database_sgc_map.get(local_id, '')
            all_current_records.append(map_to_download_format(target_record, sgc_id, 'R'))
            unchanged_count += 1

    # Write all current submissions file with UTF-8 BOM for Excel compatibility
    with open(OUTPUT_FILE, 'w', encoding='utf-8-sig', newline='') as f:
        writer = csv.writer(f)
        writer.writerow(DOWNLOAD_COLUMNS)
        writer.writerows(all_current_records)

    log_info(f"All current submissions file created: {OUTPUT_FILE}")
    log_info(f"  Total records: {len(all_current_records)}")
    log_info(f"    - Unchanged: {unchanged_count}")
    log_info(f"    - Updated by ID: {len(updated_by_id)}")
    log_info(f"    - Updated by GDM: {len(updated_by_gdm)}")
    log_info(f"    - New submissions: {len(new_submissions)}")

    # Generate deleted submissions file with SGC IDs - Action='U'
    log_section("Generating Deleted Submissions File")

    deleted_records = []
    for db_record in deleted_submissions:
        sgc_id = db_record.get('sgc_id', '')
        deleted_records.append(map_to_download_format(db_record, sgc_id, 'U'))

    with open(DELETED_FILE, 'w', encoding='utf-8-sig', newline='') as f:
        writer = csv.writer(f)
        writer.writerow(DOWNLOAD_COLUMNS)
        writer.writerows(deleted_records)

    log_info(f"Deleted submissions file created: {DELETED_FILE}")
    log_info(f"  Total records: {len(deleted_records)}")

    # Generate changed submissions file (updates with actual differences)
    log_section("Generating Changed Submissions File")

    changed_records = []
    changed_count = 0

    # Check updated by ID for changes - Action='R'
    for local_id in updated_by_id:
        if local_id in target_by_id and local_id in database_by_id:
            target_record = target_by_id[local_id]
            db_record = database_by_id[local_id]

            if has_changes(target_record, db_record):
                sgc_id = db_record.get('sgc_id', '')
                changed_records.append(map_to_download_format(target_record, sgc_id, 'R'))
                changed_count += 1

    # Check updated by GDM for changes - Action='R'
    for target_local_id in updated_by_gdm:
        if target_local_id in target_by_id:
            target_record = target_by_id[target_local_id]
            db_local_id = gdm_mappings.get(target_local_id, '')

            if db_local_id and db_local_id in database_by_id:
                db_record = database_by_id[db_local_id]

                if has_changes(target_record, db_record):
                    sgc_id = db_record.get('sgc_id', '')
                    changed_records.append(map_to_download_format(target_record, sgc_id, 'R'))
                    changed_count += 1

    # Add all new submissions (with blank SGC ID) - Action='N'
    for local_id in new_submissions:
        if local_id in target_by_id:
            target_record = target_by_id[local_id]
            changed_records.append(map_to_download_format(target_record, '', 'N'))

    with open(CHANGED_FILE, 'w', encoding='utf-8-sig', newline='') as f:
        writer = csv.writer(f)
        writer.writerow(DOWNLOAD_COLUMNS)
        writer.writerows(changed_records)

    log_info(f"Changed submissions file created: {CHANGED_FILE}")
    log_info(f"  Total records: {len(changed_records)}")
    log_info(f"    - Modified records with changes: {changed_count}")
    log_info(f"    - New submissions: {len(new_submissions)}")

    # Summary
    log_section("SUMMARY")

    print(f"{Colors.GREEN}All current submissions file:{Colors.NC} {OUTPUT_FILE}")
    print(f"  Records with SGC ID (updates): {len(updated_by_id) + len(updated_by_gdm)}")
    print(f"  Records without SGC ID (new): {len(new_submissions)}")
    print(f"  Total records: {len(all_current_records)}")
    print()
    print(f"{Colors.YELLOW}Changed submissions file:{Colors.NC} {CHANGED_FILE}")
    print(f"  Total records: {len(changed_records)}")
    print()
    print(f"{Colors.RED}Deleted submissions file:{Colors.NC} {DELETED_FILE}")
    print(f"  Total records: {len(deleted_records)}")
    print()

    # Show sample records
    print(f"\n{Colors.YELLOW}Sample record (updated with SGC ID):{Colors.NC}")
    if len(all_current_records) > 0:
        sample = all_current_records[0]
        print(f"  SGC ID: {sample[0]}")
        print(f"  Submission ID: {sample[1]}")
        print(f"  Gene: {sample[2]}, Disease: {sample[4]}, MOI: {sample[6]}")

    print(f"\n{Colors.YELLOW}Sample new submission (no SGC ID):{Colors.NC}")
    new_start_idx = len(updated_by_id) + len(updated_by_gdm)
    if len(all_current_records) > new_start_idx:
        sample = all_current_records[new_start_idx]
        print(f"  SGC ID: {sample[0]} (empty)")
        print(f"  Submission ID: {sample[1]}")
        print(f"  Gene: {sample[2]}, Disease: {sample[4]}, MOI: {sample[6]}")


if __name__ == "__main__":
    main()
