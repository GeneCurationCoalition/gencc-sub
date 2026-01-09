"""
Comparison module for ClinGen Pipeline

Compares target submissions (from GeneGraph/GCI Express) with database
submissions to identify new, updated, and deleted records.
"""

import csv
import re
from pathlib import Path
from typing import Dict, List, Set, Tuple

from .config import (
    OutputConfig, TARGET_TSV_FIELDS, DATABASE_TSV_FIELDS, COMPARISON_DIR
)
from .logging import log_info, log_warn, log_error, log_success, log_section
from .models import Submission, ComparisonResult, GDMMatch


# Fields to compare for changes (excludes identifiers and labels)
COMPARE_FIELDS = [
    'gene_id',
    'disease_id',
    'mode_of_inheritance',
    'submitter_id',
    'classification_id',
    'report_date',
    'public_report_url',
    'notes',
    'pubmed_ids',
    'assertion_criteria_url'
]


def normalize_date(date_str: str) -> str:
    """
    Normalize date string to YYYY/MM/DD format for comparison.

    Handles various formats:
    - 2022/03/29 (target format)
    - 2022-03-29T00:00:00.000000Z (ISO 8601 from database JSON)
    - 2022-03-29 00:00:00 (database datetime without T)
    - 2022-03-29 (simple ISO date)

    Args:
        date_str: Date string in various formats.

    Returns:
        Normalized date string in YYYY/MM/DD format, or original if unparseable.
    """
    if not date_str:
        return ''

    date_str = date_str.strip()

    # Already in target format YYYY/MM/DD
    if re.match(r'^\d{4}/\d{2}/\d{2}$', date_str):
        return date_str

    # ISO 8601 format: 2022-03-29T00:00:00.000000Z or 2022-03-29T00:00:00Z
    iso_match = re.match(r'^(\d{4})-(\d{2})-(\d{2})T', date_str)
    if iso_match:
        return f"{iso_match.group(1)}/{iso_match.group(2)}/{iso_match.group(3)}"

    # Database datetime format: 2022-03-29 00:00:00 (space separator)
    datetime_match = re.match(r'^(\d{4})-(\d{2})-(\d{2}) \d{2}:\d{2}:\d{2}', date_str)
    if datetime_match:
        return f"{datetime_match.group(1)}/{datetime_match.group(2)}/{datetime_match.group(3)}"

    # Simple ISO date: 2022-03-29
    simple_match = re.match(r'^(\d{4})-(\d{2})-(\d{2})$', date_str)
    if simple_match:
        return f"{simple_match.group(1)}/{simple_match.group(2)}/{simple_match.group(3)}"

    # Return original if no pattern matches
    return date_str


def normalize_value(field: str, value: str) -> str:
    """
    Normalize a field value for comparison.

    Args:
        field: Field name.
        value: Field value.

    Returns:
        Normalized value.
    """
    value = (value or '').strip()

    # Normalize dates
    if field == 'report_date':
        value = normalize_date(value)

    return value


def load_target_submissions(filepath: Path) -> Dict[str, Submission]:
    """
    Load target submissions from TSV file.

    Args:
        filepath: Path to target TSV file.

    Returns:
        Dictionary of local_id -> Submission.
    """
    result = {}

    with open(filepath, 'r', encoding='utf-8') as f:
        reader = csv.DictReader(f, delimiter='\t', fieldnames=TARGET_TSV_FIELDS)
        next(reader)  # Skip header

        for row in reader:
            submission = Submission.from_target_dict(row)
            result[submission.local_id] = submission

    return result


def load_database_submissions(filepath: Path) -> Dict[str, Submission]:
    """
    Load database submissions from TSV file.

    Args:
        filepath: Path to database TSV file.

    Returns:
        Dictionary of local_id -> Submission.
    """
    result = {}

    with open(filepath, 'r', encoding='utf-8') as f:
        reader = csv.DictReader(f, delimiter='\t', fieldnames=DATABASE_TSV_FIELDS)
        next(reader)  # Skip header

        for row in reader:
            submission = Submission.from_database_dict(row)
            result[submission.local_id] = submission

    return result


def load_unpublished_local_ids(filepath: Path) -> Set[str]:
    """
    Load unpublished local_ids from text file.

    Args:
        filepath: Path to text file with one local_id per line.

    Returns:
        Set of unpublished local_ids.
    """
    result = set()

    if not filepath.exists():
        return result

    with open(filepath, 'r', encoding='utf-8') as f:
        for line in f:
            local_id = line.strip()
            if local_id:
                result.add(local_id)

    return result


def has_changes(target: Submission, database: Submission) -> bool:
    """
    Compare two submissions and return True if there are differences.

    Normalizes values before comparison (e.g., date formats).

    Args:
        target: Target submission.
        database: Database submission.

    Returns:
        True if submissions differ, False otherwise.
    """
    for field in COMPARE_FIELDS:
        target_val = normalize_value(field, getattr(target, field, ''))
        db_val = normalize_value(field, getattr(database, field, ''))

        if target_val != db_val:
            return True

    return False


def get_field_differences(target: Submission, database: Submission) -> List[Tuple[str, str, str]]:
    """
    Get list of field differences between two submissions.

    Normalizes values before comparison (e.g., date formats).

    Args:
        target: Target submission.
        database: Database submission.

    Returns:
        List of (field_name, target_value, database_value) tuples.
    """
    differences = []

    for field in COMPARE_FIELDS:
        target_val = normalize_value(field, getattr(target, field, ''))
        db_val = normalize_value(field, getattr(database, field, ''))

        if target_val != db_val:
            differences.append((field, target_val, db_val))

    return differences


def compare_submissions(
    target: Dict[str, Submission],
    database: Dict[str, Submission],
    unpublished_ids: Set[str] = None
) -> ComparisonResult:
    """
    Compare target submissions with database submissions.

    Matching phases:
    1. Match by local_id (exact match)
    2. Match remaining by GDM (gene+disease+MOI)
    3. Remaining target = new submissions
    4. Remaining database = deleted submissions

    Args:
        target: Dictionary of target submissions keyed by local_id.
        database: Dictionary of database submissions keyed by local_id.
        unpublished_ids: Set of previously unpublished local_ids.

    Returns:
        ComparisonResult with categorized submissions.
    """
    if unpublished_ids is None:
        unpublished_ids = set()

    result = ComparisonResult()

    # Track unmatched submissions
    unmatched_target = set(target.keys())
    unmatched_database = set(database.keys())

    # Build SGC mapping
    for local_id, sub in database.items():
        result.sgc_mapping[local_id] = sub.sgc_id

    # Phase 1: Match by local_id
    log_info("Phase 1: Matching by local_id...")

    for local_id in list(unmatched_target):
        if local_id in database:
            target_sub = target[local_id]
            db_sub = database[local_id]

            result.matched_by_id[local_id] = target_sub

            # Check if there are changes
            if has_changes(target_sub, db_sub):
                result.matched_by_id_changed.add(local_id)

            unmatched_target.discard(local_id)
            unmatched_database.discard(local_id)

    log_info(f"  Matched by ID: {len(result.matched_by_id)}")
    log_info(f"  With changes: {len(result.matched_by_id_changed)}")

    # Phase 2: Match remaining by GDM
    log_info("Phase 2: Matching by gene+disease+MOI...")

    # Build GDM index for database
    db_gdm_index: Dict[str, Submission] = {}
    for local_id in unmatched_database:
        sub = database[local_id]
        gdm_key = sub.gdm_key()
        if gdm_key not in db_gdm_index:
            db_gdm_index[gdm_key] = sub

    for local_id in list(unmatched_target):
        target_sub = target[local_id]
        gdm_key = target_sub.gdm_key()

        if gdm_key in db_gdm_index:
            db_sub = db_gdm_index[gdm_key]

            match = GDMMatch(
                target_submission=target_sub,
                database_submission=db_sub,
                gdm_key=gdm_key
            )
            result.matched_by_gdm.append(match)
            result.gdm_id_mapping[local_id] = db_sub.local_id
            result.sgc_mapping[local_id] = db_sub.sgc_id

            unmatched_target.discard(local_id)
            unmatched_database.discard(db_sub.local_id)

            # Remove from index to prevent duplicate matches
            del db_gdm_index[gdm_key]

    log_info(f"  Matched by GDM: {len(result.matched_by_gdm)}")

    # Phase 3: Remaining target = new submissions (or republish if previously unpublished)
    log_info("Phase 3: Identifying new submissions...")

    for local_id in unmatched_target:
        target_sub = target[local_id]

        if local_id in unpublished_ids:
            # This is a republish - was previously unpublished
            log_info(f"  Republish detected: {local_id}")

        result.new_submissions[local_id] = target_sub

    log_info(f"  New submissions: {len(result.new_submissions)}")

    # Phase 4: Remaining database = deleted submissions
    log_info("Phase 4: Identifying deleted submissions...")

    for local_id in unmatched_database:
        db_sub = database[local_id]
        result.deleted_submissions[local_id] = db_sub

    log_info(f"  Deleted submissions: {len(result.deleted_submissions)}")

    return result


def write_comparison_reports(
    result: ComparisonResult,
    target: Dict[str, Submission],
    database: Dict[str, Submission],
    output_config: OutputConfig = None
) -> None:
    """
    Write comparison results to report files.

    Args:
        result: ComparisonResult from comparison.
        target: Dictionary of target submissions.
        database: Dictionary of database submissions.
        output_config: Output configuration.
    """
    if output_config is None:
        output_config = OutputConfig()

    # Ensure directory exists
    COMPARISON_DIR.mkdir(parents=True, exist_ok=True)

    # Write summary
    summary = result.summary()

    with open(output_config.comparison_summary, 'w', encoding='utf-8') as f:
        f.write("ClinGen Submission Comparison Summary\n")
        f.write("=" * 50 + "\n\n")
        f.write(f"Target records: {summary['total_target']}\n")
        f.write(f"Database records: {summary['total_database']}\n\n")
        f.write("Matching Results:\n")
        f.write(f"  Matched by local_id: {summary['matched_by_id']}\n")
        f.write(f"    - With changes: {summary['matched_by_id_changed']}\n")
        f.write(f"    - Unchanged: {summary['matched_by_id_unchanged']}\n")
        f.write(f"  Matched by GDM: {summary['matched_by_gdm']}\n")
        f.write(f"  New submissions: {summary['new_submissions']}\n")
        f.write(f"  Deleted submissions: {summary['deleted_submissions']}\n")

    log_success(f"Summary written: {output_config.comparison_summary}")

    # Write updated_by_id.txt (with changes)
    with open(output_config.updated_by_id, 'w', encoding='utf-8') as f:
        for local_id in sorted(result.matched_by_id_changed):
            f.write(f"Local ID: {local_id}\n")

            if local_id in target and local_id in database:
                diffs = get_field_differences(target[local_id], database[local_id])
                for field, target_val, db_val in diffs:
                    f.write(f"  {field}:\n")
                    f.write(f"    Target: {target_val[:100]}{'...' if len(target_val) > 100 else ''}\n")
                    f.write(f"    Database: {db_val[:100]}{'...' if len(db_val) > 100 else ''}\n")

            f.write("\n")

    log_success(f"Updated by ID written: {output_config.updated_by_id}")

    # Write updated_by_gdm.txt
    with open(output_config.updated_by_gdm, 'w', encoding='utf-8') as f:
        for match in result.matched_by_gdm:
            f.write(f"GDM Key: {match.gdm_key}\n")
            f.write(f"Target Local ID: {match.target_submission.local_id}\n")
            f.write(f"Database Local ID: {match.database_submission.local_id}\n")
            f.write(f"Database SGC ID: {match.database_submission.sgc_id}\n")
            f.write("\n")

    log_success(f"Updated by GDM written: {output_config.updated_by_gdm}")

    # Write new_submissions.tsv
    with open(output_config.new_submissions, 'w', encoding='utf-8', newline='') as f:
        writer = csv.writer(f, delimiter='\t', quoting=csv.QUOTE_MINIMAL)
        writer.writerow(TARGET_TSV_FIELDS)

        for local_id in sorted(result.new_submissions.keys()):
            sub = result.new_submissions[local_id]
            writer.writerow(sub.to_target_row())

    log_success(f"New submissions written: {output_config.new_submissions}")

    # Write deleted_submissions.tsv
    with open(output_config.deleted_submissions, 'w', encoding='utf-8', newline='') as f:
        writer = csv.writer(f, delimiter='\t', quoting=csv.QUOTE_MINIMAL)
        writer.writerow(DATABASE_TSV_FIELDS)

        for local_id in sorted(result.deleted_submissions.keys()):
            sub = result.deleted_submissions[local_id]
            writer.writerow(sub.to_database_row())

    log_success(f"Deleted submissions written: {output_config.deleted_submissions}")


def run_comparison(output_config: OutputConfig = None) -> ComparisonResult:
    """
    Run the complete comparison workflow.

    Args:
        output_config: Output configuration.

    Returns:
        ComparisonResult with categorized submissions.
    """
    if output_config is None:
        output_config = OutputConfig()

    log_section("Comparing Target vs Database")

    # Load data
    log_info(f"Loading target file: {output_config.combined_target}")
    target = load_target_submissions(output_config.combined_target)
    log_info(f"  Loaded {len(target)} target records")

    log_info(f"Loading database file: {output_config.database_export}")
    database = load_database_submissions(output_config.database_export)
    log_info(f"  Loaded {len(database)} database records")

    log_info(f"Loading unpublished IDs: {output_config.unpublished_ids}")
    unpublished_ids = load_unpublished_local_ids(output_config.unpublished_ids)
    log_info(f"  Loaded {len(unpublished_ids)} unpublished IDs")

    # Compare
    result = compare_submissions(target, database, unpublished_ids)

    # Write reports
    write_comparison_reports(result, target, database, output_config)

    # Print summary
    summary = result.summary()
    log_section("Comparison Summary")
    log_info(f"Matched by local_id: {summary['matched_by_id']} ({summary['matched_by_id_changed']} with changes)")
    log_info(f"Matched by GDM: {summary['matched_by_gdm']}")
    log_info(f"New submissions: {summary['new_submissions']}")
    log_info(f"Deleted submissions: {summary['deleted_submissions']}")

    return result
