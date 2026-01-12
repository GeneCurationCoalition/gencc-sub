#!/usr/bin/env python3
"""
ClinGen Full Data Processing Pipeline (v2.0)

This master script runs the entire ClinGen data processing workflow:
1. Download and process ClinGen gene validity data from GeneGraph
2. Download and process GCI Express data from GitHub
3. Merge both datasets into a single target file
4. Export database submissions (Python - replaces PHP script)
5. Compare target vs database
6. Generate merged submission files for upload (CSV and Excel)

Usage:
    python3 run_clingen_pipeline.py [OPTIONS]

Options:
    --keep-extracted    Keep extracted JSON folders after processing
    --force-download    Force re-download of source files
    --help              Show this help message

This script uses the refactored clingen package which provides:
- Centralized configuration
- Shared logging utilities
- Modular processors for each data source
- Python-based database export (no PHP dependency)
- Structured comparison results
"""

import argparse
import sys
from pathlib import Path

# Add package to path
SCRIPTS_DIR = Path(__file__).parent
sys.path.insert(0, str(SCRIPTS_DIR))

from clingen.pipeline import run_pipeline
from clingen.logging import log_error, log_warn


def main():
    """Main entry point."""
    parser = argparse.ArgumentParser(
        description='ClinGen Full Data Processing Pipeline',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python3 run_clingen_pipeline.py                    # Run pipeline with cleanup
  python3 run_clingen_pipeline.py --keep-extracted   # Keep extracted folders
  python3 run_clingen_pipeline.py --force-download   # Force re-download all files
        """
    )

    parser.add_argument(
        '--keep-extracted',
        action='store_true',
        help='Keep extracted JSON folders after processing (default: delete them)'
    )

    parser.add_argument(
        '--force-download',
        action='store_true',
        help='Force re-download of source files even if they exist'
    )

    args = parser.parse_args()

    # Run the pipeline
    success = run_pipeline(
        keep_extracted=args.keep_extracted,
        force_download=args.force_download
    )

    sys.exit(0 if success else 1)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        log_warn("\nPipeline interrupted by user")
        sys.exit(1)
    except Exception as e:
        log_error(f"Pipeline failed with unexpected error: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)
