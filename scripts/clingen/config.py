"""
Configuration for ClinGen Pipeline

Centralizes all configuration including paths, URLs, and mappings.
"""

from pathlib import Path
from dataclasses import dataclass, field
from typing import Dict, List
import os


# Base paths
# clingen package is at scripts/clingen/, so parent is scripts/, parent.parent is project root
SCRIPTS_DIR = Path(__file__).parent.parent
PROJECT_ROOT = SCRIPTS_DIR.parent
DATA_DIR = PROJECT_ROOT / "data" / "clingen"
TEMPLATE_DIR = PROJECT_ROOT / "public" / "documents"
COMPARISON_DIR = DATA_DIR / "comparison"


@dataclass
class SourceConfig:
    """Configuration for external data sources"""
    # GeneGraph (Gene Validity)
    genegraph_url: str = "https://storage.googleapis.com/genegraph-public/gene-validity-jsonld-latest.tar.gz"
    genegraph_download: Path = field(default_factory=lambda: DATA_DIR / "gene-validity-jsonld-latest.tar.gz")
    genegraph_extract_dir: Path = field(default_factory=lambda: DATA_DIR / "gene_validity_extracted")
    genegraph_output: Path = field(default_factory=lambda: DATA_DIR / "gene_validity_processed.tsv")

    # GCI Express
    gci_express_url: str = "https://raw.githubusercontent.com/clingen-data-model/data-exchange-shared-json/master/json-from-gene-express/gci-express-with-entrez-ids.json"
    gci_express_download: Path = field(default_factory=lambda: DATA_DIR / "gci_express_data.json")
    gci_express_extract_dir: Path = field(default_factory=lambda: DATA_DIR / "gci_express_extracted")
    gci_express_output: Path = field(default_factory=lambda: DATA_DIR / "gci_express_processed.tsv")


@dataclass
class OutputConfig:
    """Configuration for output files"""
    combined_target: Path = field(default_factory=lambda: DATA_DIR / "gene_validity_with_gci_express.tsv")
    database_export: Path = field(default_factory=lambda: DATA_DIR / "database_submissions_export.tsv")
    unpublished_ids: Path = field(default_factory=lambda: DATA_DIR / "database_unpublished_local_ids.txt")

    # Comparison outputs
    comparison_summary: Path = field(default_factory=lambda: COMPARISON_DIR / "comparison_summary.txt")
    updated_by_id: Path = field(default_factory=lambda: COMPARISON_DIR / "updated_by_id.txt")
    updated_by_gdm: Path = field(default_factory=lambda: COMPARISON_DIR / "updated_by_gdm.txt")
    new_submissions: Path = field(default_factory=lambda: COMPARISON_DIR / "new_submissions.tsv")
    deleted_submissions: Path = field(default_factory=lambda: COMPARISON_DIR / "deleted_submissions.tsv")

    # Final outputs
    all_current_csv: Path = field(default_factory=lambda: COMPARISON_DIR / "all_current_submissions.csv")
    all_current_xlsx: Path = field(default_factory=lambda: COMPARISON_DIR / "all_current_submissions.xlsx")
    changed_csv: Path = field(default_factory=lambda: COMPARISON_DIR / "changed_submissions.csv")
    changed_xlsx: Path = field(default_factory=lambda: COMPARISON_DIR / "changed_submissions.xlsx")
    deleted_csv: Path = field(default_factory=lambda: COMPARISON_DIR / "deleted_submissions_sgc.csv")
    deleted_xlsx: Path = field(default_factory=lambda: COMPARISON_DIR / "deleted_submissions.xlsx")

    # Template
    excel_template: Path = field(default_factory=lambda: TEMPLATE_DIR / "GenCC Submission Spreadsheet.xlsx")


@dataclass
class DatabaseConfig:
    """Database configuration - reads from .env file"""
    host: str = field(default_factory=lambda: os.getenv('DB_HOST', 'localhost'))
    port: int = field(default_factory=lambda: int(os.getenv('DB_PORT', '3306')))
    database: str = field(default_factory=lambda: os.getenv('DB_DATABASE', ''))
    username: str = field(default_factory=lambda: os.getenv('DB_USERNAME', ''))
    password: str = field(default_factory=lambda: os.getenv('DB_PASSWORD', ''))

    # ClinGen submitter
    submitter_curie: str = "GENCC:000102"

    @classmethod
    def from_env_file(cls, env_path: Path = None) -> 'DatabaseConfig':
        """Load configuration from .env file"""
        if env_path is None:
            env_path = PROJECT_ROOT / ".env"

        if env_path.exists():
            # Parse .env file manually (no external dependency)
            with open(env_path, 'r') as f:
                for line in f:
                    line = line.strip()
                    if line and not line.startswith('#') and '=' in line:
                        key, value = line.split('=', 1)
                        key = key.strip()
                        value = value.strip().strip('"').strip("'")
                        os.environ.setdefault(key, value)

        return cls()


# Classification ID mappings
CLASSIFICATION_ID_MAP: Dict[str, str] = {
    'Definitive': 'GENCC:100001',
    'Strong': 'GENCC:100002',
    'Moderate': 'GENCC:100003',
    'Limited': 'GENCC:100004',
    'LIMITED': 'GENCC:100004',
    'Disputed Evidence': 'GENCC:100005',
    'Disputed': 'GENCC:100005',
    'Contradictory (disputed)': 'GENCC:100005',
    'Refuted Evidence': 'GENCC:100006',
    'Refuted': 'GENCC:100006',
    'Contradictory (refuted)': 'GENCC:100006',
    'Animal Model Only': 'GENCC:100007',
    'No Known Disease Relationship': 'GENCC:100008',
    'NoKnownDiseaseRelationship': 'GENCC:100008',
    'No Reported Evidence': 'GENCC:100008',
    'Supportive': 'GENCC:100009',
}


# SOP Version URL mappings
SOP_VERSION_URL_MAP: Dict[str, str] = {
    'GeneValidityCriteria4': 'https://clinicalgenome.org/docs/gene-disease-validity-sop-version-4/',
    'GeneValidityCriteria5': 'https://clinicalgenome.org/docs/gene-disease-validity-sop-version-5/',
    'GeneValidityCriteria6': 'https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedures-version-6/',
    'GeneValidityCriteria7': 'https://clinicalgenome.org/docs/summary-of-updates-to-the-clingen-gene-clinical-validity-curation-sop-version-7/',
    'GeneValidityCriteria8': 'https://clinicalgenome.org/docs/summary-of-updates-to-the-clingen-gene-clinical-validity-curation-sop-version-8/',
    'GeneValidityCriteria9': 'https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedure-version-9/',
    'GeneValidityCriteria10': 'https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedures-version-10/',
    'GeneValidityCriteria11': 'https://clinicalgenome.org/docs/gene-disease-validity-standard-operating-procedures-version-11/',
}

# Default SOP URL for unknown versions
DEFAULT_SOP_URL = 'https://www.clinicalgenome.org/docs/?doc-type=curation-activity-procedures&curation-procedure=gene-disease-validity'

# GCI Express SOP URLs
GCI_EXPRESS_SOP5_URL = 'https://clinicalgenome.org/docs/gene-disease-validity-sop-version-5/'
GCI_EXPRESS_SOP4_URL = 'https://clinicalgenome.org/docs/gene-disease-validity-sop-version-4/'


# TSV field definitions
TARGET_TSV_FIELDS: List[str] = [
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

DATABASE_TSV_FIELDS: List[str] = [
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

# Download format columns for output files
DOWNLOAD_COLUMNS: List[str] = [
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


def get_classification_id(classification: str) -> str:
    """Get the classification ID based on classification value"""
    return CLASSIFICATION_ID_MAP.get(classification, '')


def get_assertion_criteria_url(sop_version: str) -> str:
    """Get the assertion criteria URL based on SOP version"""
    return SOP_VERSION_URL_MAP.get(sop_version, DEFAULT_SOP_URL)


def ensure_directories():
    """Ensure all required directories exist"""
    DATA_DIR.mkdir(parents=True, exist_ok=True)
    COMPARISON_DIR.mkdir(parents=True, exist_ok=True)
