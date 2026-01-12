"""
Database exporter for ClinGen Pipeline

Exports ClinGen submissions from the database to TSV format.
Replaces the PHP export_clingen_submissions.php script.
"""

import csv
import json
from pathlib import Path
from typing import Dict, List, Optional, Tuple
from datetime import datetime

try:
    import mysql.connector
    from mysql.connector import Error as MySQLError
    HAS_MYSQL = True
except ImportError:
    HAS_MYSQL = False
    MySQLError = Exception

from .config import (
    DatabaseConfig, OutputConfig, DATABASE_TSV_FIELDS,
    PROJECT_ROOT, DATA_DIR
)
from .logging import log_info, log_warn, log_error, log_success, log_failure
from .models import Submission


class DatabaseExporter:
    """
    Exports ClinGen submissions from MySQL database.

    Filters by:
    - submitter_id matching ClinGen (GENCC:000102)
    - is_most_recent = true (ensures local_key uniqueness)
    """

    def __init__(self, config: DatabaseConfig = None):
        """
        Initialize database exporter.

        Args:
            config: Database configuration. If None, loads from .env file.
        """
        if not HAS_MYSQL:
            raise ImportError(
                "mysql-connector-python is required for database export. "
                "Install with: pip install mysql-connector-python"
            )

        if config is None:
            config = DatabaseConfig.from_env_file()

        self.config = config
        self.connection = None

    def connect(self) -> bool:
        """
        Establish database connection.

        Returns:
            True if connection successful, False otherwise.
        """
        try:
            self.connection = mysql.connector.connect(
                host=self.config.host,
                port=self.config.port,
                database=self.config.database,
                user=self.config.username,
                password=self.config.password,
                charset='utf8mb4',
                use_unicode=True
            )
            log_success(f"Connected to database: {self.config.database}@{self.config.host}")
            return True
        except MySQLError as e:
            log_error(f"Failed to connect to database: {e}")
            return False

    def disconnect(self) -> None:
        """Close database connection."""
        if self.connection and self.connection.is_connected():
            self.connection.close()
            log_info("Database connection closed")

    def get_submitter_id(self) -> Optional[int]:
        """
        Get the internal ID for the ClinGen submitter.

        Returns:
            Internal submitter ID or None if not found.
        """
        cursor = self.connection.cursor(dictionary=True)
        cursor.execute(
            "SELECT id, name FROM submitters WHERE curie = %s",
            (self.config.submitter_curie,)
        )
        result = cursor.fetchone()
        cursor.close()

        if result:
            log_info(f"Found submitter: {result['name']} (ID: {result['id']})")
            return result['id']
        else:
            log_error(f"Submitter {self.config.submitter_curie} not found")
            return None

    def export_most_recent_submissions(self, output_path: Path) -> int:
        """
        Export most recent submissions to TSV file.

        Filters by:
        - submitter_id = ClinGen
        - is_most_recent = true (includes all statuses)

        Args:
            output_path: Path to output TSV file.

        Returns:
            Number of records exported.
        """
        submitter_id = self.get_submitter_id()
        if submitter_id is None:
            return 0

        # Query submissions with all related data
        # Only filter by is_most_recent, not by status
        query = """
            SELECT
                s.sid as sgc_id,
                s.local_key as local_id,
                g.hgnc_id as gene_id,
                d.curie as disease_id,
                i.curie as mode_of_inheritance,
                sub.curie as submitter_id,
                c.curie as classification_id,
                c.name as classification,
                s.submission_data,
                s.report_date as report_date_col
            FROM submissions s
            LEFT JOIN genes g ON s.gene_id = g.id
            LEFT JOIN diseases d ON s.disease_id = d.id
            LEFT JOIN inheritances i ON s.inheritance_id = i.id
            LEFT JOIN submitters sub ON s.submitter_id = sub.id
            LEFT JOIN classifications c ON s.classification_id = c.id
            WHERE s.submitter_id = %s
              AND s.is_most_recent = 1
              AND s.deleted_at IS NULL
            ORDER BY s.sid
        """

        cursor = self.connection.cursor(dictionary=True)
        cursor.execute(query, (submitter_id,))
        rows = cursor.fetchall()
        cursor.close()

        log_info(f"Found {len(rows)} most recent submissions")

        # Write to TSV
        with open(output_path, 'w', encoding='utf-8', newline='') as f:
            writer = csv.writer(f, delimiter='\t', quoting=csv.QUOTE_MINIMAL)

            # Write header
            writer.writerow(DATABASE_TSV_FIELDS)

            # Process each row
            for row in rows:
                record = self._process_submission_row(row)
                writer.writerow([
                    record['sgc_id'],
                    record['local_id'],
                    record['gene_id'],
                    record['disease_id'],
                    record['mode_of_inheritance'],
                    record['submitter_id'],
                    record['classification_id'],
                    record['classification'],
                    record['report_date'],
                    record['public_report_url'],
                    record['notes'],
                    record['pubmed_ids'],
                    record['assertion_criteria_url']
                ])

        log_success(f"Export complete: {output_path}")
        log_info(f"Total records exported: {len(rows)}")

        return len(rows)

    def export_unpublished_local_ids(self, output_path: Path) -> int:
        """
        Export local_ids of unpublished submissions for republish detection.

        Args:
            output_path: Path to output text file.

        Returns:
            Number of local_ids exported.
        """
        submitter_id = self.get_submitter_id()
        if submitter_id is None:
            return 0

        query = """
            SELECT local_key
            FROM submissions
            WHERE submitter_id = %s
              AND status = 'unpublished'
              AND is_most_recent = 1
              AND deleted_at IS NULL
        """

        cursor = self.connection.cursor()
        cursor.execute(query, (submitter_id,))
        rows = cursor.fetchall()
        cursor.close()

        with open(output_path, 'w', encoding='utf-8') as f:
            for row in rows:
                local_key = row[0] or ''
                f.write(f"{local_key}\n")

        log_info(f"Unpublished local IDs exported: {output_path}")
        log_info(f"Total unpublished: {len(rows)}")

        return len(rows)

    def _process_submission_row(self, row: Dict) -> Dict[str, str]:
        """
        Process a database row into the export format.

        Extracts data from submission_data JSON where needed.
        """
        # Parse submission_data JSON
        submission_data = {}
        if row.get('submission_data'):
            try:
                if isinstance(row['submission_data'], str):
                    submission_data = json.loads(row['submission_data'])
                else:
                    submission_data = row['submission_data']
            except (json.JSONDecodeError, TypeError):
                pass

        # Extract report date - prefer JSON field, fall back to column
        report_date = ''
        if submission_data.get('report', {}).get('display_date'):
            report_date = submission_data['report']['display_date']
        elif row.get('report_date_col'):
            # Format database date
            date_val = row['report_date_col']
            if hasattr(date_val, 'strftime'):
                report_date = date_val.strftime('%Y/%m/%d')
            else:
                report_date = str(date_val)

        # Extract public report URL from JSON
        public_report_url = submission_data.get('report', {}).get('ext_url', '')

        # Extract notes from JSON
        notes = submission_data.get('notes', {}).get('display', '')

        # Extract PMIDs from JSON evidence
        pmids = []
        evidence = submission_data.get('evidence', [])
        if isinstance(evidence, list):
            for item in evidence:
                if isinstance(item, dict) and 'pmid' in item:
                    pmids.append(str(item['pmid']))
        pubmed_ids = ', '.join(sorted(pmids, key=lambda x: int(x) if x.isdigit() else 0))

        # Extract assertion criteria URL from JSON
        assertion_criteria_url = submission_data.get('criteria', {}).get('url', '')

        return {
            'sgc_id': row.get('sgc_id', ''),
            'local_id': row.get('local_id', ''),
            'gene_id': (row.get('gene_id', '') or '').upper(),
            'disease_id': (row.get('disease_id', '') or '').upper(),
            'mode_of_inheritance': (row.get('mode_of_inheritance', '') or '').upper(),
            'submitter_id': row.get('submitter_id', ''),
            'classification_id': (row.get('classification_id', '') or '').upper(),
            'classification': row.get('classification', ''),
            'report_date': report_date,
            'public_report_url': public_report_url,
            'notes': notes,
            'pubmed_ids': pubmed_ids,
            'assertion_criteria_url': assertion_criteria_url
        }


def export_database_submissions(output_config: OutputConfig = None) -> Tuple[int, int]:
    """
    Export ClinGen submissions from database.

    Convenience function that handles connection management.
    Exports all most_recent submissions regardless of status.

    Args:
        output_config: Output configuration. If None, uses defaults.

    Returns:
        Tuple of (exported_count, unpublished_count)
    """
    if output_config is None:
        output_config = OutputConfig()

    # Ensure output directory exists
    output_config.database_export.parent.mkdir(parents=True, exist_ok=True)

    exporter = DatabaseExporter()

    if not exporter.connect():
        return (0, 0)

    try:
        exported_count = exporter.export_most_recent_submissions(output_config.database_export)
        unpublished_count = exporter.export_unpublished_local_ids(output_config.unpublished_ids)
        return (exported_count, unpublished_count)
    finally:
        exporter.disconnect()


if __name__ == "__main__":
    # Allow running as standalone script for testing
    from .config import ensure_directories

    ensure_directories()
    exported, unpublished = export_database_submissions()
    print(f"\nExported {exported} most recent, {unpublished} unpublished submissions")
