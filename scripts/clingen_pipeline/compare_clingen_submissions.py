#!/usr/bin/env python3
"""
ClinGen Submissions Comparison Script

Compares gene_validity_processed.tsv (target state) with database_submissions_export.tsv
(current database state) and categorizes records into:
- Updated submission IDs (matching by local_id)
- Updated GDMs (matching by gene_id + disease_id + mode_of_inheritance)
- New submissions (in target but not in database)
- Deleted submissions (in database but not in target)

Provides detailed field-by-field differences for all updated records.
"""

import csv
from pathlib import Path
from collections import defaultdict
from typing import Dict, List, Tuple, Set
import sys

# Configuration
SCRIPTS_DIR = Path(__file__).parent
DATA_DIR = SCRIPTS_DIR.parent.parent / "data" / "clingen"
TARGET_FILE = DATA_DIR / "gene_validity_with_gci_express.tsv"
DATABASE_FILE = DATA_DIR / "database_submissions_export.tsv"
UNPUBLISHED_IDS_FILE = DATA_DIR / "database_unpublished_local_ids.txt"
OUTPUT_DIR = DATA_DIR / "comparison"

# Field names for target file (gene_validity_with_gci_express.tsv)
TARGET_FIELDS = [
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

# Field names for database file (database_submissions_export.tsv) - includes sgc_id
# Database export has separate public_note and private_note fields
DATABASE_FIELDS = [
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
    'public_note',
    'private_note',
    'pubmed_ids',
    'assertion_criteria_url'
]

# Output field names with action column
# Output uses 'public_note' (not 'notes') for consistency
OUTPUT_TARGET_FIELDS = [
    'local_id',
    'action',
    'gene_id',
    'disease_id',
    'mode_of_inheritance',
    'submitter_id',
    'classification_id',
    'classification',
    'report_date',
    'public_report_url',
    'public_note',
    'pubmed_ids',
    'assertion_criteria_url'
]

OUTPUT_DATABASE_FIELDS = [
    'sgc_id',
    'action',
    'local_id',
    'gene_id',
    'disease_id',
    'mode_of_inheritance',
    'submitter_id',
    'classification_id',
    'classification',
    'report_date',
    'public_report_url',
    'public_note',
    'pubmed_ids',
    'assertion_criteria_url'
]

# Fields to compare (excluding local_id which is the key)
# Note: Excludes 'classification' (label) and 'private_note' (can have private notes)
# Includes 'public_note' to detect changes in public curation notes
COMPARE_FIELDS = [
    'gene_id',
    'disease_id',  # Full CURIE format comparison (e.g., "MONDO:0019587; MONDO:0019497")
    'mode_of_inheritance',
    'submitter_id',
    'classification_id',  # Compare classification ID only, not label
    'report_date',
    'public_report_url',
    'public_note',  # Compare public notes for changes
    'pubmed_ids',
    'assertion_criteria_url'
]

# GDM matching fields
GDM_FIELDS = ['gene_id', 'disease_id', 'mode_of_inheritance']


class Colors:
    """Terminal colors for output"""
    GREEN = '\033[0;32m'
    YELLOW = '\033[1;33m'
    RED = '\033[0;31m'
    BLUE = '\033[0;34m'
    CYAN = '\033[0;36m'
    NC = '\033[0m'  # No Color


def log_info(message: str) -> None:
    """Log an info message"""
    print(f"{Colors.GREEN}[INFO]{Colors.NC} {message}")


def log_section(message: str) -> None:
    """Log a section header"""
    print(f"\n{Colors.BLUE}{'=' * 80}")
    print(f"{message}")
    print(f"{'=' * 80}{Colors.NC}\n")


def load_tsv_file(filepath: Path, fields: List[str]) -> Tuple[List[Dict], Dict[str, Dict], Dict[Tuple, List[Dict]]]:
    """
    Load TSV file and create lookup structures

    Args:
        filepath: Path to TSV file
        fields: List of field names for this file

    Returns:
        - List of all records
        - Dictionary keyed by local_id
        - Dictionary keyed by (gene_id, disease_id, mode_of_inheritance) tuple
    """
    records = []
    by_local_id = {}
    by_gdm = defaultdict(list)

    with open(filepath, 'r', encoding='utf-8') as f:
        reader = csv.DictReader(f, delimiter='\t', fieldnames=fields)
        next(reader)  # Skip header

        for row in reader:
            records.append(row)

            local_id = row['local_id']
            by_local_id[local_id] = row

            gdm_key = (
                row['gene_id'],
                row['disease_id'],
                row['mode_of_inheritance']
            )
            by_gdm[gdm_key].append(row)

    return records, by_local_id, by_gdm


def compare_records(target: Dict, database: Dict) -> Dict[str, Tuple[str, str]]:
    """
    Compare two records field by field

    Returns dictionary of field_name -> (target_value, database_value) for fields that differ
    """
    differences = {}

    for field in COMPARE_FIELDS:
        target_val = target.get(field, '').strip()
        db_val = database.get(field, '').strip()

        # Normalize empty values
        if not target_val:
            target_val = ''
        if not db_val:
            db_val = ''

        if target_val != db_val:
            differences[field] = (target_val, db_val)

    return differences


def format_differences(differences: Dict[str, Tuple[str, str]], indent: str = "  ") -> str:
    """Format differences for display"""
    if not differences:
        return f"{indent}(no differences)"

    lines = []
    for field, (target_val, db_val) in differences.items():
        lines.append(f"{indent}{field}:")
        lines.append(f"{indent}  Target:   {target_val if target_val else '(empty)'}")
        lines.append(f"{indent}  Database: {db_val if db_val else '(empty)'}")

    return '\n'.join(lines)


def main():
    """Main execution"""
    log_section("ClinGen Submissions Comparison")

    # Create output directory
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    # Load files
    log_info(f"Loading target file: {TARGET_FILE}")
    target_records, target_by_id, target_by_gdm = load_tsv_file(TARGET_FILE, TARGET_FIELDS)
    log_info(f"  Loaded {len(target_records)} target records")

    # Rename 'notes' to 'public_note' in target records for comparison
    # Target data has 'notes' field which is the public note from GeneGraph
    for record in target_records:
        if 'notes' in record:
            record['public_note'] = record['notes']
    for local_id, record in target_by_id.items():
        if 'notes' in record:
            record['public_note'] = record['notes']
    for gdm_key, records_list in target_by_gdm.items():
        for record in records_list:
            if 'notes' in record:
                record['public_note'] = record['notes']

    log_info(f"Loading database file: {DATABASE_FILE}")
    db_records, db_by_id, db_by_gdm = load_tsv_file(DATABASE_FILE, DATABASE_FIELDS)
    log_info(f"  Loaded {len(db_records)} database records")

    # Load unpublished local IDs for republish detection
    unpublished_local_ids = set()
    if UNPUBLISHED_IDS_FILE.exists():
        log_info(f"Loading unpublished IDs file: {UNPUBLISHED_IDS_FILE}")
        with open(UNPUBLISHED_IDS_FILE, 'r', encoding='utf-8') as f:
            unpublished_local_ids = set(line.strip() for line in f if line.strip())
        log_info(f"  Loaded {len(unpublished_local_ids)} unpublished local IDs")
    else:
        log_info(f"  No unpublished IDs file found (this is OK for first run)")

    # Category collections
    updated_by_id = []
    updated_by_gdm = []
    new_submissions = []
    republish_submissions = []  # Unpublished submissions that reappeared in source
    deleted_submissions = []

    # Track which database records have been matched
    matched_db_ids = set()

    log_section("Phase 1: Matching by local_id")

    matched_by_id_no_changes = 0
    for target_record in target_records:
        local_id = target_record['local_id']

        if local_id in db_by_id:
            # Found match by local_id
            db_record = db_by_id[local_id]
            differences = compare_records(target_record, db_record)

            # Only include if there are actual field differences (excluding sgc_id)
            if differences:
                updated_by_id.append({
                    'local_id': local_id,
                    'target': target_record,
                    'database': db_record,
                    'differences': differences
                })
            else:
                matched_by_id_no_changes += 1

            matched_db_ids.add(local_id)

    log_info(f"Found {len(updated_by_id)} records matching by local_id WITH changes")
    log_info(f"Found {matched_by_id_no_changes} records matching by local_id with NO changes (excluded)")

    log_section("Phase 2: Matching by GDM (gene + disease + MOI)")

    matched_by_gdm_no_changes = 0
    for target_record in target_records:
        local_id = target_record['local_id']

        # Skip if already matched by local_id
        if local_id in db_by_id:
            continue

        # Try to match by GDM
        gdm_key = (
            target_record['gene_id'],
            target_record['disease_id'],
            target_record['mode_of_inheritance']
        )

        if gdm_key in db_by_gdm:
            # Find an unmatched database record with this GDM
            for db_record in db_by_gdm[gdm_key]:
                db_local_id = db_record['local_id']

                if db_local_id not in matched_db_ids:
                    # Found an unmatched GDM match
                    differences = compare_records(target_record, db_record)

                    # Only include if there are actual field differences
                    # Note: For GDM matches, gene/disease/MOI will be the same by definition,
                    # so differences will be in other fields (classification, dates, URLs, etc.)
                    if differences:
                        updated_by_gdm.append({
                            'target_local_id': local_id,
                            'database_local_id': db_local_id,
                            'gdm_key': gdm_key,
                            'target': target_record,
                            'database': db_record,
                            'differences': differences
                        })
                    else:
                        matched_by_gdm_no_changes += 1

                    matched_db_ids.add(db_local_id)
                    break

    log_info(f"Found {len(updated_by_gdm)} records matching by GDM WITH changes")
    log_info(f"Found {matched_by_gdm_no_changes} records matching by GDM with NO changes (excluded)")

    log_section("Phase 3: Identifying new and republish submissions")

    for target_record in target_records:
        local_id = target_record['local_id']

        # Check if matched by local_id
        if local_id in db_by_id:
            continue

        # Check if matched by GDM
        matched_by_gdm = any(
            item['target_local_id'] == local_id
            for item in updated_by_gdm
        )

        if not matched_by_gdm:
            # Check if this was previously unpublished (republish scenario)
            if local_id in unpublished_local_ids:
                republish_submissions.append(target_record)
            else:
                new_submissions.append(target_record)

    log_info(f"Found {len(new_submissions)} new submissions")
    log_info(f"Found {len(republish_submissions)} republish submissions (previously unpublished)")

    log_section("Phase 4: Identifying deleted submissions")

    for db_record in db_records:
        db_local_id = db_record['local_id']

        if db_local_id not in matched_db_ids:
            deleted_submissions.append(db_record)

    log_info(f"Found {len(deleted_submissions)} deleted submissions")

    # Generate reports
    log_section("Generating Reports")

    # Summary report
    summary_file = OUTPUT_DIR / "comparison_summary.txt"
    with open(summary_file, 'w', encoding='utf-8') as f:
        f.write("=" * 80 + "\n")
        f.write("ClinGen Submissions Comparison Summary\n")
        f.write("=" * 80 + "\n\n")

        f.write(f"Target records (gene_validity_with_gci_express.tsv): {len(target_records)}\n")
        f.write(f"Database records (database_submissions_export.tsv): {len(db_records)}\n\n")

        f.write("=" * 80 + "\n")
        f.write("CATEGORIZATION RESULTS\n")
        f.write("=" * 80 + "\n\n")

        f.write(f"1. Updated by local_id (WITH changes): {len(updated_by_id)}\n")
        f.write(f"   - Matched by local_id but NO changes (excluded): {matched_by_id_no_changes}\n\n")

        f.write(f"2. Updated by GDM (WITH changes): {len(updated_by_gdm)}\n")
        f.write(f"   - Matched by GDM but NO changes (excluded): {matched_by_gdm_no_changes}\n\n")

        f.write(f"3. New Submissions: {len(new_submissions)}\n\n")

        f.write(f"4. Republish Submissions: {len(republish_submissions)}\n")
        f.write(f"   - Previously unpublished, now reappearing in source\n\n")

        f.write(f"5. Deleted Submissions: {len(deleted_submissions)}\n\n")

        f.write("CHANGED SUBMISSIONS TOTAL\n")
        f.write(f"  = Updated by local_id + Updated by GDM + New + Republish\n")
        f.write(f"  = {len(updated_by_id)} + {len(updated_by_gdm)} + {len(new_submissions)} + {len(republish_submissions)}\n")
        f.write(f"  = {len(updated_by_id) + len(updated_by_gdm) + len(new_submissions) + len(republish_submissions)}\n\n")

        f.write("=" * 80 + "\n")
        f.write("VERIFICATION\n")
        f.write("=" * 80 + "\n\n")

        total_target_matched = (len(updated_by_id) + matched_by_id_no_changes +
                               len(updated_by_gdm) + matched_by_gdm_no_changes +
                               len(new_submissions) + len(republish_submissions))
        f.write(f"Target records accounted for: {total_target_matched} / {len(target_records)}\n")
        f.write(f"  - With changes (in output): {len(updated_by_id) + len(updated_by_gdm) + len(new_submissions) + len(republish_submissions)}\n")
        f.write(f"  - Without changes (excluded): {matched_by_id_no_changes + matched_by_gdm_no_changes}\n\n")

        total_db_matched = (len(updated_by_id) + matched_by_id_no_changes +
                           len(updated_by_gdm) + matched_by_gdm_no_changes +
                           len(deleted_submissions))
        f.write(f"Database records accounted for: {total_db_matched} / {len(db_records)}\n")
        f.write(f"  - Matched (with or without changes): {len(updated_by_id) + matched_by_id_no_changes + len(updated_by_gdm) + matched_by_gdm_no_changes}\n")
        f.write(f"  - Deleted: {len(deleted_submissions)}\n")

    log_info(f"Summary report: {summary_file}")

    # Updated by ID - detailed differences
    updated_id_file = OUTPUT_DIR / "updated_by_id.txt"
    with open(updated_id_file, 'w', encoding='utf-8') as f:
        f.write("=" * 80 + "\n")
        f.write(f"Updated Submission IDs (WITH CHANGES ONLY): {len(updated_by_id)}\n")
        f.write("=" * 80 + "\n\n")

        f.write(f"Records with changes: {len(updated_by_id)}\n")
        f.write(f"Records without changes (excluded from list): {matched_by_id_no_changes}\n\n")

        if updated_by_id:
            f.write("=" * 80 + "\n")
            f.write("RECORDS WITH CHANGES\n")
            f.write("=" * 80 + "\n\n")

            for item in updated_by_id:
                f.write(f"Local ID: {item['local_id']}\n")
                f.write(format_differences(item['differences']))
                f.write("\n\n")

    log_info(f"Updated by ID report: {updated_id_file}")

    # Updated by GDM - detailed differences
    updated_gdm_file = OUTPUT_DIR / "updated_by_gdm.txt"
    with open(updated_gdm_file, 'w', encoding='utf-8') as f:
        f.write("=" * 80 + "\n")
        f.write(f"Updated GDMs (WITH CHANGES ONLY): {len(updated_by_gdm)}\n")
        f.write("=" * 80 + "\n\n")

        f.write(f"Records with changes: {len(updated_by_gdm)}\n")
        f.write(f"Records without changes (excluded from list): {matched_by_gdm_no_changes}\n\n")

        if updated_by_gdm:
            f.write("=" * 80 + "\n")
            f.write("RECORDS WITH CHANGES\n")
            f.write("=" * 80 + "\n\n")

        for item in updated_by_gdm:
            f.write(f"Target Local ID: {item['target_local_id']}\n")
            f.write(f"Database Local ID: {item['database_local_id']}\n")
            f.write(f"GDM: {item['gdm_key'][0]} + {item['gdm_key'][1]} + {item['gdm_key'][2]}\n")
            f.write("\nDifferences:\n")
            f.write(format_differences(item['differences']))
            f.write("\n" + "=" * 80 + "\n\n")

    log_info(f"Updated by GDM report: {updated_gdm_file}")

    # Updated submissions (by ID and GDM) + Republish - Add action = 'R' for Republish
    updated_submissions_file = OUTPUT_DIR / "updated_submissions.tsv"
    with open(updated_submissions_file, 'w', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=OUTPUT_DATABASE_FIELDS, delimiter='\t')
        writer.writeheader()

        # Add updated by ID records
        for item in updated_by_id:
            record = item['target'].copy()
            # Get SGC ID from database record
            record['sgc_id'] = item['database']['sgc_id']
            record['action'] = 'R'
            # Remove 'notes' field (we renamed it to 'public_note')
            record.pop('notes', None)
            writer.writerow(record)

        # Add updated by GDM records
        for item in updated_by_gdm:
            record = item['target'].copy()
            # Get SGC ID from database record
            record['sgc_id'] = item['database']['sgc_id']
            record['action'] = 'R'
            # Remove 'notes' field (we renamed it to 'public_note')
            record.pop('notes', None)
            writer.writerow(record)

        # Add republish records (previously unpublished, now reappearing in source)
        for record in republish_submissions:
            # Note: No SGC ID because not in current published exports
            # Will be assigned when uploaded
            record_copy = record.copy()
            record_copy['sgc_id'] = ''  # Will be looked up during merge
            record_copy['action'] = 'R'
            # Remove 'notes' field (we renamed it to 'public_note')
            record_copy.pop('notes', None)
            writer.writerow(record_copy)

    log_info(f"Updated submissions TSV: {updated_submissions_file}")

    # New submissions - Add action = 'N'
    new_submissions_file = OUTPUT_DIR / "new_submissions.tsv"
    with open(new_submissions_file, 'w', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=OUTPUT_TARGET_FIELDS, delimiter='\t')
        writer.writeheader()
        for record in new_submissions:
            record['action'] = 'N'
            # Remove 'notes' field (we renamed it to 'public_note')
            record.pop('notes', None)
            writer.writerow(record)

    log_info(f"New submissions TSV: {new_submissions_file}")

    # Deleted submissions - Add action = 'U', clear all fields except sgc_id
    deleted_submissions_file = OUTPUT_DIR / "deleted_submissions.tsv"
    with open(deleted_submissions_file, 'w', encoding='utf-8') as f:
        writer = csv.DictWriter(f, fieldnames=OUTPUT_DATABASE_FIELDS, delimiter='\t')
        writer.writeheader()
        for record in deleted_submissions:
            # Create new record with only sgc_id and action
            unpublish_record = {field: '' for field in OUTPUT_DATABASE_FIELDS}
            unpublish_record['sgc_id'] = record['sgc_id']
            unpublish_record['action'] = 'U'
            writer.writerow(unpublish_record)

    log_info(f"Deleted submissions TSV: {deleted_submissions_file}")

    # Print summary to console
    log_section("SUMMARY")

    print(f"{Colors.CYAN}Target records:{Colors.NC} {len(target_records)}")
    print(f"{Colors.CYAN}Database records:{Colors.NC} {len(db_records)}")
    print()
    print(f"{Colors.GREEN}Updated by ID:{Colors.NC} {len(updated_by_id)}")
    print(f"  - With changes: {len([x for x in updated_by_id if x['differences']])}")
    print(f"  - Without changes: {len([x for x in updated_by_id if not x['differences']])}")
    print()
    print(f"{Colors.YELLOW}Updated by GDM:{Colors.NC} {len(updated_by_gdm)}")
    print()
    print(f"{Colors.BLUE}New submissions:{Colors.NC} {len(new_submissions)}")
    print()
    print(f"{Colors.RED}Deleted submissions:{Colors.NC} {len(deleted_submissions)}")
    print()
    print(f"\n{Colors.CYAN}Output Files with Action Column:{Colors.NC}")
    print(f"  - updated_submissions.tsv: {len(updated_by_id) + len(updated_by_gdm)} records (Action='R')")
    print(f"  - new_submissions.tsv: {len(new_submissions)} records (Action='N')")
    print(f"  - deleted_submissions.tsv: {len(deleted_submissions)} records (Action='U')")
    print()
    print(f"\nAll reports saved to: {OUTPUT_DIR}")


if __name__ == "__main__":
    main()
