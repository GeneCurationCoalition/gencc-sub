#!/usr/bin/env python3
"""
ClinGen Gene Validity Data Processor

Downloads and processes ClinGen gene validity data from newline-delimited JSON files,
extracting key fields and outputting to a TSV file.
"""

import json
import tarfile
import urllib.request
import re
import csv
from pathlib import Path
from typing import Any, Dict, List, Optional, Set
import sys

# Configuration
URL = "https://storage.googleapis.com/genegraph-public/gene-validity-jsonld-latest.tar.gz"
SCRIPTS_DIR = Path(__file__).parent
DATA_DIR = SCRIPTS_DIR.parent.parent / "data" / "clingen"
OUTPUT_FILE = DATA_DIR / "gene_validity_processed.tsv"
DOWNLOAD_FILE = DATA_DIR / "gene-validity-jsonld-latest.tar.gz"
EXTRACT_DIR = DATA_DIR / "gene_validity_extracted"


class Colors:
    """Terminal colors for output"""
    GREEN = '\033[0;32m'
    YELLOW = '\033[1;33m'
    RED = '\033[0;31m'
    NC = '\033[0m'  # No Color


def log_info(message: str) -> None:
    """Log an info message"""
    print(f"{Colors.GREEN}[INFO]{Colors.NC} {message}", file=sys.stderr)


def log_warn(message: str) -> None:
    """Log a warning message"""
    print(f"{Colors.YELLOW}[WARN]{Colors.NC} {message}", file=sys.stderr)


def log_error(message: str) -> None:
    """Log an error message"""
    print(f"{Colors.RED}[ERROR]{Colors.NC} {message}", file=sys.stderr)


def setup_directories() -> None:
    """Create necessary directories"""
    log_info("Setting up directories...")
    DATA_DIR.mkdir(parents=True, exist_ok=True)


def download_data() -> Path:
    """Download the ClinGen data file"""
    log_info(f"Downloading data from {URL}...")

    if DOWNLOAD_FILE.exists():
        log_warn("File already exists. Skipping download.")
        log_warn(f"Delete {DOWNLOAD_FILE} to re-download.")
        return DOWNLOAD_FILE

    urllib.request.urlretrieve(URL, DOWNLOAD_FILE)
    log_info(f"Download complete: {DOWNLOAD_FILE}")
    return DOWNLOAD_FILE


def extract_data(tar_path: Path) -> Path:
    """Extract the tar.gz file"""
    log_info("Extracting data...")

    # Remove old extraction if it exists
    if EXTRACT_DIR.exists():
        log_warn("Removing old extraction directory...")
        import shutil
        shutil.rmtree(EXTRACT_DIR)

    EXTRACT_DIR.mkdir(parents=True, exist_ok=True)

    with tarfile.open(tar_path, 'r:gz') as tar:
        tar.extractall(EXTRACT_DIR)

    log_info(f"Extraction complete: {EXTRACT_DIR}")
    return EXTRACT_DIR


def to_list(value: Any) -> List[str]:
    """Convert value to list of strings, handling arrays and single values"""
    if value is None:
        return []
    if isinstance(value, list):
        return [str(v) for v in value]
    return [str(value)]


def get_date_from_contribution(contributions: Any, role: str) -> str:
    """Extract date from contributions for a specific role"""
    if not contributions or not isinstance(contributions, list):
        return ""

    for contrib in contributions:
        if not isinstance(contrib, dict):
            continue
        if contrib.get('role') == role:
            date = contrib.get('date')
            if date:
                # Handle array dates (take the last one)
                if isinstance(date, list):
                    return date[-1] if date else ""
                return str(date)
    return ""


def extract_pmids_recursive(obj: Any, pmids: Set[str]) -> None:
    """
    Extract PubMed URLs only from dc:source attributes that are direct children
    of objects within an 'evidence' array.

    This means: look for objects that have an 'evidence' key, then check the items
    in that evidence array for dc:source attributes.
    """
    if isinstance(obj, dict):
        # Check if this dict has an 'evidence' key
        if 'evidence' in obj:
            evidence_value = obj['evidence']

            # If evidence is a list, check each item for dc:source
            if isinstance(evidence_value, list):
                for evidence_item in evidence_value:
                    if isinstance(evidence_item, dict):
                        source = evidence_item.get('dc:source')
                        if source and isinstance(source, str) and 'pubmed' in source:
                            pmids.add(source)

        # Continue recursing into all values to find more 'evidence' keys
        for value in obj.values():
            extract_pmids_recursive(value, pmids)

    elif isinstance(obj, list):
        for item in obj:
            extract_pmids_recursive(item, pmids)


def transform_disease(disease: str) -> str:
    """Transform disease ID from obo:MONDO_999999 to MONDO:999999"""
    return re.sub(r'obo:MONDO_', 'MONDO:', disease)


def transform_moi(moi: str) -> str:
    """Transform mode of inheritance from obo:HP_999999 to HP:999999"""
    return re.sub(r'obo:HP_', 'HP:', moi)


def clean_notes(notes: Any) -> str:
    """Preserve notes exactly as provided, only normalize line endings and remove problematic whitespace"""
    if not notes:
        return ''

    # Handle list of notes
    if isinstance(notes, list):
        # Join with double newline to preserve paragraph separation
        notes_str = '\n\n'.join(str(item) for item in notes if item)
    else:
        notes_str = str(notes)

    if not notes_str:
        return ''

    # Normalize line endings to \n
    notes_str = notes_str.replace('\r\n', '\n').replace('\r', '\n')

    # Remove problematic control characters that interfere with TSV parsing
    # Replace with spaces (or empty string for invisible chars) to preserve word boundaries
    # Keep: newlines (\n=10), tabs (\t=9) for formatting
    # Remove:
    # - ASCII control chars (0-8, 11-31): \x02 (STX), \x0b (VT), etc.
    # - Latin-1 control chars (128-159): \x96 and others
    # - Non-breaking space (\xa0=160)
    import re
    # Remove ASCII control characters except newline (10) and tab (9)
    notes_str = re.sub(r'[\x00-\x08\x0b-\x1f]', ' ', notes_str)
    # Remove Latin-1 control characters (0x80-0x9f) - these are invisible
    # These include \x96 which appears at start of some notes
    notes_str = re.sub(r'[\x80-\x9f]', '', notes_str)
    # Convert non-breaking spaces to regular spaces
    notes_str = notes_str.replace('\xa0', ' ')

    return notes_str


def format_date_ymd(date_str: str) -> str:
    """Format date string as YYYY/MM/DD"""
    if not date_str:
        return ''
    try:
        # Parse ISO format date
        date = date_str.split('T')[0]  # Get just the date part
        year, month, day = date.split('-')
        return f"{year}/{month}/{day}"
    except Exception:
        return ''


# Mapping of SOP version to assertion criteria URL
SOP_VERSION_URL_MAP = {
    'GeneValidityCriteria4': 'https://clinicalgenome.org/docs/gene-disease-validity-sop-version-4/',
    'GeneValidityCriteria5': 'https://clinicalgenome.org/docs/gene-disease-validity-sop-version-5/',
    'GeneValidityCriteria6': 'https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedures-version-6/',
    'GeneValidityCriteria7': 'https://clinicalgenome.org/docs/summary-of-updates-to-the-clingen-gene-clinical-validity-curation-sop-version-7/',
    'GeneValidityCriteria8': 'https://clinicalgenome.org/docs/summary-of-updates-to-the-clingen-gene-clinical-validity-curation-sop-version-8/',
    'GeneValidityCriteria9': 'https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedure-version-9/',
    'GeneValidityCriteria10': 'https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedures-version-10/',
    'GeneValidityCriteria11': 'https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedures-version-11/',
}

# Mapping of classification to classification_id
CLASSIFICATION_ID_MAP = {
    'Definitive': 'GENCC:100001',
    'Strong': 'GENCC:100002',
    'Moderate': 'GENCC:100003',
    'Limited': 'GENCC:100004',
    'Disputed Evidence': 'GENCC:100005',
    'Disputed': 'GENCC:100005',  # Handle short form
    'Refuted Evidence': 'GENCC:100006',
    'Refuted': 'GENCC:100006',  # Handle short form
    'Animal Model Only': 'GENCC:100007',
    'No Known Disease Relationship': 'GENCC:100008',
    'NoKnownDiseaseRelationship': 'GENCC:100008',  # Handle camelCase form
    'Supportive': 'GENCC:100009',
}


def get_assertion_criteria_url(sop_version: str) -> str:
    """Get the assertion criteria URL based on SOP version"""
    return SOP_VERSION_URL_MAP.get(sop_version, 'https://www.clinicalgenome.org/docs/?doc-type=curation-activity-procedures&curation-procedure=gene-disease-validity')


def get_classification_id(classification: str) -> str:
    """Get the classification ID based on classification value"""
    return CLASSIFICATION_ID_MAP.get(classification, '')


def process_json_file(json_path: Path) -> Optional[Dict[str, str]]:
    """Process a single JSON file and extract required fields"""
    try:
        with open(json_path, 'r', encoding='utf-8') as f:
            data = json.load(f)

        # Check if GCISnapshot exists - skip if not present
        if 'GCISnapshot' not in data or not data['GCISnapshot']:
            log_warn(f"Skipping {json_path.name}: Missing GCISnapshot attribute")
            return None

        # Extract GCI_snapshot_id (keep only last part of URL) -> local_id
        gci_snapshot = data.get('GCISnapshot', '')
        local_id = gci_snapshot.split('/')[-1] if gci_snapshot else ''

        # Extract websiteLegacyID (keep only last part of URL)
        website_legacy_id_full = data.get('websiteLegacyID', '')
        website_legacy_id = website_legacy_id_full.split('/')[-1] if website_legacy_id_full else ''

        # Extract contribution dates
        contributions = data.get('contributions')
        approval_date = get_date_from_contribution(contributions, 'Approver')

        # Extract PMIDs recursively from evidence (keep only the ID part)
        pmid_urls: Set[str] = set()
        evidence = data.get('evidence')
        if evidence:
            extract_pmids_recursive(evidence, pmid_urls)
        # Extract only the last part of each PMID URL (the actual ID) and sort numerically
        pmids = [url.split('/')[-1] for url in pmid_urls]
        pmids_sorted = sorted(pmids, key=lambda x: int(x) if x.isdigit() else 0)
        pubmed_ids = ', '.join(pmids_sorted)  # Use comma-space separator

        # Extract subject fields
        subject = data.get('subject', {})
        if not isinstance(subject, dict):
            subject = {}

        # Disease (handle array or string)
        disease_list = to_list(subject.get('disease'))
        disease_id = transform_disease('; '.join(disease_list)).upper()

        # Gene (handle array or string)
        gene_list = to_list(subject.get('gene'))
        gene_id = '; '.join(gene_list).upper()

        # Mode of Inheritance (handle array or string)
        moi_list = to_list(subject.get('modeOfInheritance'))
        mode_of_inheritance = transform_moi('; '.join(moi_list)).upper()

        # Get sop_version for assertion_criteria_url
        sop_version = data.get('specifiedBy', '')

        # Get classification and map to classification_id
        classification = data.get('evidenceStrength', '')
        classification_id = get_classification_id(classification).upper() if get_classification_id(classification) else ''

        # Extract notes from dc:description
        notes = clean_notes(data.get('dc:description', ''))

        # Build the new record structure
        record = {
            'local_id': local_id,
            'gene_id': gene_id,
            'disease_id': disease_id,
            'mode_of_inheritance': mode_of_inheritance,
            'submitter_id': 'GENCC:000102',  # Literal value
            'classification_id': classification_id,
            'classification': classification,
            'report_date': format_date_ymd(approval_date),
            'public_report_url': f"https://search.clinicalgenome.org/kb/gene-validity/CGGV:{website_legacy_id}{approval_date}",
            'notes': notes,
            'pubmed_ids': pubmed_ids,
            'assertion_criteria_url': get_assertion_criteria_url(sop_version)
        }

        return record

    except json.JSONDecodeError as e:
        log_error(f"JSON decode error in {json_path.name}: {e}")
        return None
    except Exception as e:
        log_error(f"Error processing {json_path.name}: {e}")
        return None


def process_json_files(extract_dir: Path) -> None:
    """Process all JSON files in the extraction directory"""
    log_info("Processing JSON files...")

    # Column headers - reordered per requirements
    headers = [
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

    # Find all JSON files
    json_files = sorted(extract_dir.glob('*.json'))

    if not json_files:
        log_error(f"No JSON files found in {extract_dir}")
        return

    log_info(f"Found {len(json_files)} JSON files")

    # Open output file
    with open(OUTPUT_FILE, 'w', encoding='utf-8', newline='') as out:
        # Create CSV writer with tab delimiter
        writer = csv.writer(out, delimiter='\t', quoting=csv.QUOTE_MINIMAL)

        # Write header
        writer.writerow(headers)

        # Process each file
        record_count = 0
        skipped_count = 0
        error_count = 0

        for i, json_file in enumerate(json_files, 1):
            if i % 100 == 0:
                log_info(f"Processing file {i}: {json_file.name}")

            record = process_json_file(json_file)

            if record:
                # Write record as TSV with proper escaping
                row = [record.get(h, '') for h in headers]
                writer.writerow(row)
                record_count += 1

        log_info(f"Processing complete: {OUTPUT_FILE}")
        log_info(f"Total records processed: {record_count}")

        # Calculate skipped files
        skipped_count = len(json_files) - record_count
        if skipped_count > 0:
            log_warn(f"Files skipped (missing GCISnapshot): {skipped_count}")


def main():
    """Main execution"""
    try:
        log_info("Starting ClinGen Gene Validity data processing...")

        setup_directories()
        tar_file = download_data()
        extract_dir = extract_data(tar_file)
        process_json_files(extract_dir)

        log_info(f"All done! Output file: {OUTPUT_FILE}")

        # Show first few lines
        log_info("First few records:")
        with open(OUTPUT_FILE, 'r') as f:
            for i, line in enumerate(f):
                if i >= 3:
                    break
                # Truncate long lines for display
                display_line = line.strip()
                if len(display_line) > 150:
                    display_line = display_line[:150] + "..."
                print(display_line)

    except KeyboardInterrupt:
        log_warn("Process interrupted by user")
        sys.exit(1)
    except Exception as e:
        log_error(f"Fatal error: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)


if __name__ == "__main__":
    main()
