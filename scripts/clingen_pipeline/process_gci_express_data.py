#!/usr/bin/env python3
"""
GCI Express Data Processor

Downloads and processes GCI Express gene validity data from GitHub JSON file.
Each record is keyed by a numeric ID at the root level.
Extracts individual records for further processing.
"""

import json
import urllib.request
from pathlib import Path
from typing import Dict, Any
import sys

# Configuration
GITHUB_RAW_URL = "https://raw.githubusercontent.com/clingen-data-model/data-exchange-shared-json/master/json-from-gene-express/gci-express-with-entrez-ids.json"
SCRIPTS_DIR = Path(__file__).parent
DATA_DIR = SCRIPTS_DIR.parent.parent / "data" / "clingen"
DOWNLOAD_FILE = DATA_DIR / "gci_express_data.json"
EXTRACT_DIR = DATA_DIR / "gci_express_extracted"


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
    EXTRACT_DIR.mkdir(parents=True, exist_ok=True)


def download_data() -> Path:
    """Download the GCI Express data file from GitHub"""
    log_info(f"Downloading GCI Express data from GitHub...")
    log_info(f"URL: {GITHUB_RAW_URL}")

    if DOWNLOAD_FILE.exists():
        log_warn("File already exists. Skipping download.")
        log_warn(f"Delete {DOWNLOAD_FILE} to re-download.")
        return DOWNLOAD_FILE

    try:
        # Set a user agent to avoid GitHub blocking
        req = urllib.request.Request(
            GITHUB_RAW_URL,
            headers={'User-Agent': 'Mozilla/5.0'}
        )

        with urllib.request.urlopen(req) as response:
            data = response.read()

        with open(DOWNLOAD_FILE, 'wb') as f:
            f.write(data)

        log_info(f"Download complete: {DOWNLOAD_FILE}")
        log_info(f"File size: {len(data) / 1024 / 1024:.2f} MB")
        return DOWNLOAD_FILE

    except Exception as e:
        log_error(f"Failed to download file: {e}")
        sys.exit(1)


def parse_and_extract_records(json_file: Path) -> None:
    """
    Parse the JSON file and extract individual records

    The file structure is expected to be:
    {
        "1": { record data },
        "2": { record data },
        ...
    }
    """
    log_info("Parsing JSON file...")

    try:
        with open(json_file, 'r', encoding='utf-8') as f:
            data = json.load(f)

        # Check if data is a dictionary
        if not isinstance(data, dict):
            log_error(f"Expected root to be a dictionary, got {type(data)}")
            sys.exit(1)

        log_info(f"Found {len(data)} root-level keys")

        # Sample the first few keys to understand structure
        sample_keys = list(data.keys())[:5]
        log_info(f"Sample keys: {sample_keys}")

        # Extract and save individual records
        log_info("Extracting individual records...")

        extracted_count = 0
        for key, record in data.items():
            # Save each record as a separate JSON file
            output_file = EXTRACT_DIR / f"gci_express_{key}.json"

            with open(output_file, 'w', encoding='utf-8') as f:
                json.dump(record, f, indent=2)

            extracted_count += 1

            # Log progress every 100 records
            if extracted_count % 100 == 0:
                log_info(f"Extracted {extracted_count} records...")

        log_info(f"Extraction complete: {extracted_count} records saved to {EXTRACT_DIR}")

        # Display structure of first record for analysis
        if sample_keys:
            first_key = sample_keys[0]
            first_record = data[first_key]

            log_info("\n" + "=" * 80)
            log_info(f"Sample Record Structure (Key: {first_key}):")
            log_info("=" * 80)

            # Show top-level keys
            if isinstance(first_record, dict):
                log_info(f"Top-level keys: {list(first_record.keys())}")

                # Show some sample values
                for i, (k, v) in enumerate(first_record.items()):
                    if i >= 10:  # Show first 10 fields
                        log_info("  ...")
                        break

                    # Format value display
                    if isinstance(v, dict):
                        value_display = f"<dict with {len(v)} keys>"
                    elif isinstance(v, list):
                        value_display = f"<list with {len(v)} items>"
                    elif isinstance(v, str) and len(v) > 50:
                        value_display = f"{v[:50]}..."
                    else:
                        value_display = str(v)

                    log_info(f"  {k}: {value_display}")
            else:
                log_info(f"Record type: {type(first_record)}")

            log_info("=" * 80 + "\n")

        return extracted_count

    except json.JSONDecodeError as e:
        log_error(f"JSON decode error: {e}")
        sys.exit(1)
    except Exception as e:
        log_error(f"Error processing file: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)


def analyze_structure(json_file: Path) -> None:
    """Analyze the structure of the JSON file to understand the data model"""
    log_info("Analyzing JSON structure...")

    try:
        with open(json_file, 'r', encoding='utf-8') as f:
            data = json.load(f)

        # Collect all unique top-level keys across all records
        all_keys = set()
        sample_records = []

        for key, record in list(data.items())[:10]:  # Sample first 10
            if isinstance(record, dict):
                all_keys.update(record.keys())
                sample_records.append((key, record))

        log_info(f"\nUnique top-level keys across sampled records:")
        for key in sorted(all_keys):
            log_info(f"  - {key}")

        # Look for gene, disease, MOI fields
        log_info("\nLooking for gene-disease-MOI related fields...")
        gene_related = [k for k in all_keys if 'gene' in k.lower() or 'hgnc' in k.lower()]
        disease_related = [k for k in all_keys if 'disease' in k.lower() or 'mondo' in k.lower() or 'omim' in k.lower()]
        moi_related = [k for k in all_keys if 'moi' in k.lower() or 'inheritance' in k.lower() or 'mode' in k.lower()]

        if gene_related:
            log_info(f"Gene-related fields: {gene_related}")
        if disease_related:
            log_info(f"Disease-related fields: {disease_related}")
        if moi_related:
            log_info(f"MOI-related fields: {moi_related}")

    except Exception as e:
        log_error(f"Error analyzing structure: {e}")


def main():
    """Main execution"""
    try:
        log_info("Starting GCI Express data processing...")

        setup_directories()
        json_file = download_data()
        analyze_structure(json_file)
        record_count = parse_and_extract_records(json_file)

        log_info(f"\nAll done!")
        log_info(f"Downloaded file: {DOWNLOAD_FILE}")
        log_info(f"Extracted {record_count} records to: {EXTRACT_DIR}")

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
