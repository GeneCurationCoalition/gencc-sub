"""
Main pipeline orchestration for ClinGen data processing.

This module provides the high-level pipeline that coordinates all steps:
1. Download and process GeneGraph data
2. Download and process GCI Express data
3. Merge source files
4. Export database submissions
5. Compare target vs database
6. Generate output files (CSV and Excel)
"""

import shutil
from datetime import datetime
from pathlib import Path
from typing import Optional

from .config import (
    SourceConfig, OutputConfig, DatabaseConfig,
    DATA_DIR, COMPARISON_DIR, ensure_directories
)
from .logging import (
    Logger, get_logger, set_logger,
    log_info, log_warn, log_error, log_success, log_failure,
    log_section, log_step
)
from .sources import GeneGraphProcessor, GCIExpressProcessor, merge_source_files
from .database import export_database_submissions, HAS_MYSQL
from .comparison import run_comparison, ComparisonResult
from .output import generate_csv_outputs, generate_excel_outputs, run_output_generation


class ClinGenPipeline:
    """
    Main pipeline for ClinGen data processing.

    Orchestrates the complete workflow from downloading source data
    to generating final output files.
    """

    def __init__(
        self,
        source_config: SourceConfig = None,
        output_config: OutputConfig = None,
        db_config: DatabaseConfig = None,
        keep_extracted: bool = False,
        force_download: bool = False
    ):
        """
        Initialize the pipeline.

        Args:
            source_config: Configuration for data sources.
            output_config: Configuration for output files.
            db_config: Database configuration.
            keep_extracted: If True, keep extracted folders after processing.
            force_download: If True, force re-download of source files.
        """
        self.source_config = source_config or SourceConfig()
        self.output_config = output_config or OutputConfig()
        self.db_config = db_config
        self.keep_extracted = keep_extracted
        self.force_download = force_download

        self.total_steps = 8
        self.current_step = 0

    def run(self) -> bool:
        """
        Run the complete pipeline.

        Returns:
            True if all steps completed successfully.
        """
        start_time = datetime.now()

        log_section("ClinGen Full Data Processing Pipeline")
        log_info(f"Started at: {start_time.strftime('%Y-%m-%d %H:%M:%S')}")

        if self.keep_extracted:
            log_info("Mode: Keeping extracted folders after completion")

        # Ensure directories exist
        ensure_directories()

        try:
            # Step 1: Process GeneGraph data
            self.current_step += 1
            log_step(self.current_step, self.total_steps, "Processing GeneGraph gene validity data")
            genegraph = GeneGraphProcessor(self.source_config)
            genegraph_count = genegraph.run(force_download=self.force_download)
            if genegraph_count == 0:
                log_failure("No GeneGraph records processed")
                return False
            log_success(f"GeneGraph: {genegraph_count} records")

            # Step 2: Download GCI Express data
            self.current_step += 1
            log_step(self.current_step, self.total_steps, "Processing GCI Express data")
            gci_express = GCIExpressProcessor(self.source_config)
            gci_express_count = gci_express.run(force_download=self.force_download)
            if gci_express_count == 0:
                log_failure("No GCI Express records processed")
                return False
            log_success(f"GCI Express: {gci_express_count} records")

            # Step 3: Merge source files
            self.current_step += 1
            log_step(self.current_step, self.total_steps, "Merging source files")
            total_target = merge_source_files(self.source_config, self.output_config)
            log_success(f"Combined target: {total_target} records")

            # Step 4: Export database submissions
            self.current_step += 1
            log_step(self.current_step, self.total_steps, "Exporting database submissions")

            if not HAS_MYSQL:
                log_error("mysql-connector-python not installed. Install with: pip install mysql-connector-python")
                return False

            exported, unpublished = export_database_submissions(self.output_config)
            log_success(f"Database export: {exported} most recent, {unpublished} unpublished")

            # Step 5: Compare target vs database
            self.current_step += 1
            log_step(self.current_step, self.total_steps, "Comparing target vs database")
            comparison_result = run_comparison(self.output_config)

            # Step 6: Generate CSV outputs
            self.current_step += 1
            log_step(self.current_step, self.total_steps, "Generating CSV outputs")

            # Load data for output generation
            from .comparison import load_target_submissions, load_database_submissions
            target = load_target_submissions(self.output_config.combined_target)
            database = load_database_submissions(self.output_config.database_export)

            generate_csv_outputs(comparison_result, target, database, self.output_config)

            # Step 7: Generate Excel outputs
            self.current_step += 1
            log_step(self.current_step, self.total_steps, "Generating Excel outputs")
            if not generate_excel_outputs(self.output_config):
                log_warn("Excel generation had some issues")

            # Step 8: Cleanup
            self.current_step += 1
            log_step(self.current_step, self.total_steps, "Cleanup")
            self._cleanup()

            # Complete
            end_time = datetime.now()
            duration = end_time - start_time

            log_section("Pipeline Completed Successfully!")

            print(f"\n✓ All steps completed successfully!\n")
            print(f"Started:  {start_time.strftime('%Y-%m-%d %H:%M:%S')}")
            print(f"Finished: {end_time.strftime('%Y-%m-%d %H:%M:%S')}")
            print(f"Duration: {duration}\n")

            self._print_output_summary()

            return True

        except Exception as e:
            log_failure(f"Pipeline failed: {e}")
            import traceback
            traceback.print_exc()
            return False

    def _cleanup(self) -> None:
        """Clean up extracted folders."""
        if self.keep_extracted:
            log_info("Keeping extracted folders as requested")
            return

        folders = [
            self.source_config.genegraph_extract_dir,
            self.source_config.gci_express_extract_dir
        ]

        for folder in folders:
            if folder.exists():
                try:
                    shutil.rmtree(folder)
                    log_success(f"Removed: {folder.name}")
                except Exception as e:
                    log_warn(f"Failed to remove {folder.name}: {e}")

    def _print_output_summary(self) -> None:
        """Print summary of output files."""
        print("Output Files:")
        print(f"  1. Combined Target:     {self.output_config.combined_target}")
        print(f"  2. Database Export:     {self.output_config.database_export}")
        print(f"  3. Comparison Reports:  {COMPARISON_DIR}/")
        print(f"  4. All Current (CSV):   {self.output_config.all_current_csv}")
        print(f"  5. All Current (XLSX):  {self.output_config.all_current_xlsx}")
        print(f"  6. Changed (CSV):       {self.output_config.changed_csv}")
        print(f"  7. Changed (XLSX):      {self.output_config.changed_xlsx}")
        print(f"  8. Deleted (CSV):       {self.output_config.deleted_csv}")
        print(f"  9. Deleted (XLSX):      {self.output_config.deleted_xlsx}")


def run_pipeline(
    keep_extracted: bool = False,
    force_download: bool = False
) -> bool:
    """
    Convenience function to run the pipeline with default configuration.

    Args:
        keep_extracted: Keep extracted folders after processing.
        force_download: Force re-download of source files.

    Returns:
        True if pipeline completed successfully.
    """
    # Load database config from .env
    db_config = DatabaseConfig.from_env_file()

    pipeline = ClinGenPipeline(
        keep_extracted=keep_extracted,
        force_download=force_download,
        db_config=db_config
    )

    return pipeline.run()
