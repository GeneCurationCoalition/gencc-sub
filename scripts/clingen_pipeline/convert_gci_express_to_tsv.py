#!/usr/bin/env python3
"""
GCI Express to TSV Converter

Converts extracted GCI Express JSON records to the same TSV format as
gene_validity_processed.tsv for merging.
"""

import json
import re
import csv
from pathlib import Path
from typing import Dict, List, Optional, Set
from datetime import datetime
import sys

# Configuration
SCRIPTS_DIR = Path(__file__).parent
DATA_DIR = SCRIPTS_DIR.parent.parent / "data" / "clingen"
EXTRACT_DIR = DATA_DIR / "gci_express_extracted"
OUTPUT_FILE = DATA_DIR / "gci_express_processed.tsv"

# Classification mapping
CLASSIFICATION_ID_MAP = {
    'Definitive': 'GENCC:100001',
    'Strong': 'GENCC:100002',
    'Moderate': 'GENCC:100003',
    'Limited': 'GENCC:100004',
    'LIMITED': 'GENCC:100004',
    'Disputed Evidence': 'GENCC:100005',
    'Disputed': 'GENCC:100005',
    'Contradictory (disputed)': 'GENCC:100005',  # Map to Disputed Evidence
    'Refuted Evidence': 'GENCC:100006',
    'Refuted': 'GENCC:100006',
    'Contradictory (refuted)': 'GENCC:100006',  # Map to Refuted Evidence
    'Animal Model Only': 'GENCC:100007',
    'No Known Disease Relationship': 'GENCC:100008',
    'NoKnownDiseaseRelationship': 'GENCC:100008',
    'No Reported Evidence': 'GENCC:100008',  # Map to No Known Disease Relationship
    'Supportive': 'GENCC:100009',
}

# SOP version URLs
SOP_VERSION_5_URL = 'https://clinicalgenome.org/docs/gene-disease-validity-sop-version-5/'
SOP_VERSION_4_URL = 'https://clinicalgenome.org/docs/gene-disease-validity-sop-version-4/'


class Colors:
    """Terminal colors for output"""
    GREEN = '\033[0;32m'
    YELLOW = '\033[1;33m'
    RED = '\033[0;31m'
    NC = '\033[0m'


def log_info(message: str) -> None:
    """Log an info message"""
    print(f"{Colors.GREEN}[INFO]{Colors.NC} {message}", file=sys.stderr)


def log_warn(message: str) -> None:
    """Log a warning message"""
    print(f"{Colors.YELLOW}[WARN]{Colors.NC} {message}", file=sys.stderr)


def log_error(message: str) -> None:
    """Log an error message"""
    print(f"{Colors.RED}[ERROR]{Colors.NC} {message}", file=sys.stderr)


def get_classification_id(classification: str) -> str:
    """Get the classification ID based on classification value"""
    return CLASSIFICATION_ID_MAP.get(classification, '')


def extract_mondo_id(conditions: Dict) -> str:
    """Extract MONDO ID from conditions"""
    if not conditions or 'MONDO' not in conditions:
        return ''

    mondo = conditions['MONDO']

    # Try to extract from IRI
    if 'iri' in mondo:
        iri = mondo['iri']
        # Extract MONDO ID from IRI like "http://purl.obolibrary.org/obo/MONDO_0019354"
        match = re.search(r'MONDO[_:](\d+)', iri)
        if match:
            return f"MONDO:{match.group(1)}"

    # Try curie field
    if 'curie' in mondo:
        return mondo['curie']

    return ''


def extract_moi_from_score_json(score_json_str: str) -> str:
    """Extract mode of inheritance from scoreJsonSerializedSop5"""
    if not score_json_str:
        return 'HP:0000005'  # Default to Unknown

    try:
        score_data = json.loads(score_json_str)

        # Look for ModeOfInheritance in scoreJson
        if 'scoreJson' in score_data and 'ModeOfInheritance' in score_data['scoreJson']:
            moi_text = score_data['scoreJson']['ModeOfInheritance']

            # Extract HP code from text like "Autosomal recessive inheritance (HP:0000007)"
            match = re.search(r'HP:\d+', moi_text)
            if match:
                return match.group(0)

        return 'HP:0000005'  # Default to Unknown
    except json.JSONDecodeError:
        return 'HP:0000005'


def extract_pmids_from_score_json(score_json_str: str) -> str:
    """Extract PMIDs from scoreJsonSerializedSop5"""
    if not score_json_str:
        return ''

    try:
        score_data = json.loads(score_json_str)
        pmids = set()

        # Recursively search for uid fields (which contain PMIDs)
        def find_pmids(obj):
            if isinstance(obj, dict):
                if 'uid' in obj:
                    pmids.add(str(obj['uid']))
                for value in obj.values():
                    find_pmids(value)
            elif isinstance(obj, list):
                for item in obj:
                    find_pmids(item)

        find_pmids(score_data)

        # Sort PMIDs numerically
        pmids_sorted = sorted(pmids, key=lambda x: int(x) if x.isdigit() else 0)
        return ', '.join(pmids_sorted)
    except json.JSONDecodeError:
        return ''


def extract_notes_from_score_json(score_json_str: str) -> str:
    """Extract notes from scoreJsonSerializedSop5 -> notes.note field"""
    if not score_json_str:
        return ''

    try:
        score_data = json.loads(score_json_str)

        # Look for notes.note in the data
        if isinstance(score_data, dict) and 'notes' in score_data:
            notes_obj = score_data['notes']
            if isinstance(notes_obj, dict) and 'note' in notes_obj:
                note = notes_obj['note']
                if note:
                    # Only normalize line endings (don't modify content)
                    note = str(note).replace('\r\n', '\n').replace('\r', '\n')
                    return note

        return ''
    except json.JSONDecodeError:
        return ''


def format_date_ymd(date_str: str) -> str:
    """Format date string as YYYY/MM/DD"""
    if not date_str:
        return ''

    try:
        # Parse ISO format date like "2018-03-26T00:00:00"
        date = date_str.split('T')[0]
        year, month, day = date.split('-')
        return f"{year}/{month}/{day}"
    except Exception:
        return ''


def get_assertion_criteria_url(score_json_sop5: str) -> str:
    """
    Determine assertion criteria URL based on scoreJsonSerializedSop5 content

    - If scoreJsonSerializedSop5 has content (not empty) → SOP version 5
    - If scoreJsonSerializedSop5 is empty → SOP version 4
    """
    if score_json_sop5 and score_json_sop5.strip():
        return SOP_VERSION_5_URL
    else:
        return SOP_VERSION_4_URL


def process_gci_express_record(record_id: str, data: Dict) -> Optional[Dict[str, str]]:
    """Process a single GCI Express record and convert to TSV format"""
    try:
        # Extract gene_id from genes dict
        gene_id = ''
        if 'genes' in data and isinstance(data['genes'], dict):
            for gene_key, gene_data in data['genes'].items():
                if isinstance(gene_data, dict) and 'curie' in gene_data:
                    gene_id = gene_data['curie'].upper()
                    break

        if not gene_id:
            log_warn(f"Skipping record {record_id}: No gene_id found")
            return None

        # Extract disease_id
        disease_id = extract_mondo_id(data.get('conditions', {}))
        if not disease_id:
            log_warn(f"Skipping record {record_id}: No disease_id found")
            return None

        disease_id = disease_id.upper()

        # Extract classification
        classification = ''
        if 'scores' in data and isinstance(data['scores'], dict):
            for score_key, score_data in data['scores'].items():
                if isinstance(score_data, dict) and 'label' in score_data:
                    classification = score_data['label']
                    break

        classification_id = get_classification_id(classification).upper() if classification else ''

        # Extract mode of inheritance
        score_json_sop5 = data.get('scoreJsonSerializedSop5', '')
        mode_of_inheritance = extract_moi_from_score_json(score_json_sop5).upper()

        # Extract PMIDs
        pubmed_ids = extract_pmids_from_score_json(score_json_sop5)

        # Extract notes
        notes = extract_notes_from_score_json(score_json_sop5)

        # Format date
        report_date = format_date_ymd(data.get('dateISO8601', ''))

        # Determine assertion criteria URL
        assertion_criteria_url = get_assertion_criteria_url(score_json_sop5)

        # Generate public report URL
        public_report_url = f"https://search.clinicalgenome.org/kb/gene-validity/CGGCIEX:assertion_{record_id}"

        # Build record
        record = {
            'local_id': record_id,
            'gene_id': gene_id,
            'disease_id': disease_id,
            'mode_of_inheritance': mode_of_inheritance,
            'submitter_id': 'GENCC:000102',
            'classification_id': classification_id,
            'classification': classification,
            'report_date': report_date,
            'public_report_url': public_report_url,
            'notes': notes,
            'pubmed_ids': pubmed_ids,
            'assertion_criteria_url': assertion_criteria_url
        }

        return record

    except Exception as e:
        log_error(f"Error processing record {record_id}: {e}")
        return None


def main():
    """Main execution"""
    try:
        log_info("Converting GCI Express records to TSV format...")

        # Find all JSON files
        json_files = sorted(EXTRACT_DIR.glob('gci_express_*.json'))

        if not json_files:
            log_error(f"No GCI Express JSON files found in {EXTRACT_DIR}")
            sys.exit(1)

        log_info(f"Found {len(json_files)} GCI Express records")

        # Column headers
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

        # Open output file
        with open(OUTPUT_FILE, 'w', encoding='utf-8', newline='') as out:
            # Create CSV writer with tab delimiter
            writer = csv.writer(out, delimiter='\t', quoting=csv.QUOTE_MINIMAL)

            # Write header
            writer.writerow(headers)

            # Process each file
            processed_count = 0
            skipped_count = 0
            sop5_count = 0
            sop4_count = 0

            for json_file in json_files:
                # Extract record ID from filename
                record_id = json_file.stem.replace('gci_express_', '')

                # Load JSON
                with open(json_file, 'r', encoding='utf-8') as f:
                    data = json.load(f)

                # Process record
                record = process_gci_express_record(record_id, data)

                if record:
                    # Count SOP versions
                    if record['assertion_criteria_url'] == SOP_VERSION_5_URL:
                        sop5_count += 1
                    else:
                        sop4_count += 1

                    # Write to TSV with proper escaping
                    row = [record.get(h, '') for h in headers]
                    writer.writerow(row)
                    processed_count += 1
                else:
                    skipped_count += 1

        log_info(f"\nProcessing complete!")
        log_info(f"Output file: {OUTPUT_FILE}")
        log_info(f"Records processed: {processed_count}")
        log_info(f"Records skipped: {skipped_count}")
        log_info(f"\nAssertion Criteria URL distribution:")
        log_info(f"  SOP Version 5: {sop5_count} records")
        log_info(f"  SOP Version 4: {sop4_count} records")

        # Show sample records
        log_info("\nFirst 3 records:")
        with open(OUTPUT_FILE, 'r') as f:
            for i, line in enumerate(f):
                if i >= 4:
                    break
                if i == 0:
                    print("Header:", file=sys.stderr)
                else:
                    print(f"Record {i}:", file=sys.stderr)
                print("  " + line.strip()[:150] + "...", file=sys.stderr)

    except Exception as e:
        log_error(f"Fatal error: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)


if __name__ == "__main__":
    main()
