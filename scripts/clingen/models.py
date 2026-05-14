"""
Data models for ClinGen Pipeline

Provides dataclasses for submissions and comparison results.
"""

from dataclasses import dataclass, field
from typing import Dict, List, Optional, Set


@dataclass
class Submission:
    """
    Represents a gene-disease submission record.

    Used for both target (GeneGraph/GCI Express) and database records.
    """
    local_id: str
    gene_id: str
    disease_id: str
    mode_of_inheritance: str
    submitter_id: str
    classification_id: str
    classification: str
    report_date: str
    public_report_url: str
    notes: str
    pubmed_ids: str
    assertion_criteria_url: str

    # Database-only field
    sgc_id: str = ''

    def to_target_row(self) -> List[str]:
        """Convert to target TSV row format"""
        return [
            self.local_id,
            self.gene_id,
            self.disease_id,
            self.mode_of_inheritance,
            self.submitter_id,
            self.classification_id,
            self.classification,
            self.report_date,
            self.public_report_url,
            self.notes,
            self.pubmed_ids,
            self.assertion_criteria_url
        ]

    def to_database_row(self) -> List[str]:
        """Convert to database TSV row format (includes sgc_id)"""
        return [
            self.sgc_id,
            self.local_id,
            self.gene_id,
            self.disease_id,
            self.mode_of_inheritance,
            self.submitter_id,
            self.classification_id,
            self.classification,
            self.report_date,
            self.public_report_url,
            self.notes,
            self.pubmed_ids,
            self.assertion_criteria_url
        ]

    def to_download_row(self, action: str = '') -> List[str]:
        """
        Convert to download format row.

        Args:
            action: Action code - 'U' (unpublish), 'N' (new), 'R' (republish)

        Returns:
            List of values in download format
        """
        if action == 'U':
            # Unpublish: only SGC ID and Action
            return [self.sgc_id, action] + [''] * 16

        return [
            self.sgc_id,
            action,
            self.local_id,
            self.gene_id,
            '',  # Gene Symbol (blank)
            self.disease_id,
            '',  # Disease Name (blank)
            self.mode_of_inheritance,
            '',  # MOI Name (blank)
            self.submitter_id,
            '',  # Submitter Name (blank)
            self.classification_id,
            '',  # Classification Name (blank)
            self.report_date,
            self.public_report_url,
            self.notes,
            self.pubmed_ids,
            self.assertion_criteria_url
        ]

    def gdm_key(self) -> str:
        """Generate gene-disease-MOI key for matching"""
        return f"{self.gene_id}|{self.disease_id}|{self.mode_of_inheritance}"

    @classmethod
    def from_target_dict(cls, data: Dict[str, str]) -> 'Submission':
        """Create Submission from target TSV dictionary"""
        return cls(
            local_id=data.get('local_id', ''),
            gene_id=data.get('gene_id', ''),
            disease_id=data.get('disease_id', ''),
            mode_of_inheritance=data.get('mode_of_inheritance', ''),
            submitter_id=data.get('submitter_id', ''),
            classification_id=data.get('classification_id', ''),
            classification=data.get('classification', ''),
            report_date=data.get('report_date', ''),
            public_report_url=data.get('public_report_url', ''),
            notes=data.get('notes', data.get('public_note', '')),
            pubmed_ids=data.get('pubmed_ids', ''),
            assertion_criteria_url=data.get('assertion_criteria_url', '')
        )

    @classmethod
    def from_database_dict(cls, data: Dict[str, str]) -> 'Submission':
        """Create Submission from database TSV dictionary"""
        return cls(
            sgc_id=data.get('sgc_id', ''),
            local_id=data.get('local_id', ''),
            gene_id=data.get('gene_id', ''),
            disease_id=data.get('disease_id', ''),
            mode_of_inheritance=data.get('mode_of_inheritance', ''),
            submitter_id=data.get('submitter_id', ''),
            classification_id=data.get('classification_id', ''),
            classification=data.get('classification', ''),
            report_date=data.get('report_date', ''),
            public_report_url=data.get('public_report_url', ''),
            notes=data.get('notes', data.get('public_note', '')),
            pubmed_ids=data.get('pubmed_ids', ''),
            assertion_criteria_url=data.get('assertion_criteria_url', '')
        )


@dataclass
class GDMMatch:
    """Represents a match by gene-disease-MOI (different local_ids)"""
    target_submission: Submission
    database_submission: Submission
    gdm_key: str


@dataclass
class ComparisonResult:
    """
    Results of comparing target submissions vs database.

    Categorizes submissions into:
    - matched_by_id: Same local_id in both (with or without changes)
    - matched_by_gdm: Same gene+disease+MOI but different local_id
    - new_submissions: In target but not in database
    - deleted_submissions: In database but not in target
    """
    # Matched by local_id
    matched_by_id: Dict[str, Submission] = field(default_factory=dict)
    matched_by_id_changed: Set[str] = field(default_factory=set)

    # Matched by GDM
    matched_by_gdm: List[GDMMatch] = field(default_factory=list)

    # Republished (previously unpublished, now back in target data)
    republished: Set[str] = field(default_factory=set)

    # New and deleted
    new_submissions: Dict[str, Submission] = field(default_factory=dict)
    deleted_submissions: Dict[str, Submission] = field(default_factory=dict)

    # Already unpublished (in DB as unpublished, not in target — no action needed)
    already_unpublished: Dict[str, Submission] = field(default_factory=dict)

    # SGC ID mapping (local_id -> sgc_id)
    sgc_mapping: Dict[str, str] = field(default_factory=dict)

    # GDM mapping (target_local_id -> database_local_id)
    gdm_id_mapping: Dict[str, str] = field(default_factory=dict)

    def total_matched(self) -> int:
        """Total number of matched records"""
        return len(self.matched_by_id) + len(self.matched_by_gdm)

    def total_changed(self) -> int:
        """Total number of changed records (matched with differences)"""
        return len(self.matched_by_id_changed) + len(self.matched_by_gdm)

    def summary(self) -> Dict[str, int]:
        """Return summary statistics"""
        return {
            'matched_by_id': len(self.matched_by_id),
            'matched_by_id_changed': len(self.matched_by_id_changed),
            'matched_by_id_unchanged': len(self.matched_by_id) - len(self.matched_by_id_changed),
            'matched_by_gdm': len(self.matched_by_gdm),
            'republished': len(self.republished),
            'new_submissions': len(self.new_submissions),
            'deleted_submissions': len(self.deleted_submissions),
            'already_unpublished': len(self.already_unpublished),
            'total_target': len(self.matched_by_id) + len(self.matched_by_gdm) + len(self.new_submissions),
            'total_database': len(self.matched_by_id) + len(self.matched_by_gdm) + len(self.deleted_submissions) + len(self.already_unpublished)
        }
