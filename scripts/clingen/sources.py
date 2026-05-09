"""
Data source processors for ClinGen Pipeline

Handles downloading and processing data from:
- GeneGraph (ClinGen Gene Validity)
- GCI Express
"""

import csv
import json
import re
import shutil
import tarfile
import urllib.request
from pathlib import Path
from typing import Any, Dict, Iterator, List, Optional, Set

from .config import (
    SourceConfig, OutputConfig, TARGET_TSV_FIELDS,
    CLASSIFICATION_ID_MAP, SOP_VERSION_URL_MAP, DEFAULT_SOP_URL,
    GCI_EXPRESS_SOP4_URL, GCI_EXPRESS_SOP5_URL,
    get_classification_id, get_assertion_criteria_url
)
from .logging import log_info, log_warn, log_error, log_success, log_failure, get_logger
from .models import Submission


class GeneGraphProcessor:
    """
    Processes ClinGen Gene Validity data from GeneGraph.

    Downloads tar.gz archive, extracts JSON files, and converts to TSV.
    """

    def __init__(self, config: SourceConfig = None):
        """Initialize processor with configuration."""
        self.config = config or SourceConfig()

    def download(self, force: bool = False) -> Path:
        """
        Download the GeneGraph data archive.

        Args:
            force: If True, re-download even if file exists.

        Returns:
            Path to downloaded file.
        """
        log_info(f"Downloading GeneGraph data from {self.config.genegraph_url}...")

        if self.config.genegraph_download.exists():
            if force:
                log_info("Removing cached file to fetch latest version...")
                self.config.genegraph_download.unlink()
            else:
                log_warn("File already exists. Skipping download.")
                log_warn(f"Delete {self.config.genegraph_download} to re-download.")
                return self.config.genegraph_download

        self.config.genegraph_download.parent.mkdir(parents=True, exist_ok=True)
        urllib.request.urlretrieve(self.config.genegraph_url, self.config.genegraph_download)
        log_success(f"Download complete: {self.config.genegraph_download}")

        return self.config.genegraph_download

    def extract(self) -> Path:
        """
        Extract the tar.gz archive.

        Returns:
            Path to extraction directory.
        """
        log_info("Extracting GeneGraph data...")

        # Remove old extraction if it exists
        if self.config.genegraph_extract_dir.exists():
            log_warn("Removing old extraction directory...")
            shutil.rmtree(self.config.genegraph_extract_dir)

        self.config.genegraph_extract_dir.mkdir(parents=True, exist_ok=True)

        with tarfile.open(self.config.genegraph_download, 'r:gz') as tar:
            tar.extractall(self.config.genegraph_extract_dir)

        log_success(f"Extraction complete: {self.config.genegraph_extract_dir}")
        return self.config.genegraph_extract_dir

    def process_json_file(self, json_path: Path) -> Optional[Dict[str, str]]:
        """
        Process a single JSON file and extract submission data.

        Args:
            json_path: Path to JSON file.

        Returns:
            Dictionary with submission fields, or None if invalid.
        """
        try:
            with open(json_path, 'r', encoding='utf-8') as f:
                data = json.load(f)

            # Check if GCISnapshot exists - skip if not present
            if 'GCISnapshot' not in data or not data['GCISnapshot']:
                return None

            # Extract GCI_snapshot_id -> local_id
            gci_snapshot = data.get('GCISnapshot', '')
            local_id = gci_snapshot.split('/')[-1] if gci_snapshot else ''

            # Extract approval date and report URL from Evaluated contribution
            contributions = data.get('contributions')
            eval_contrib = self._get_evaluated_contribution(contributions)
            approval_date = eval_contrib.get('date', '') if eval_contrib else ''
            public_report_url = self._build_report_url(eval_contrib)

            # Extract PMIDs recursively from hasEvidenceLines
            pmid_urls: Set[str] = set()
            evidence_lines = data.get('hasEvidenceLines')
            if evidence_lines:
                self._extract_pmids_recursive(evidence_lines, pmid_urls)
            pmids = [url.split('/')[-1] for url in pmid_urls]
            pmids_sorted = sorted(pmids, key=lambda x: int(x) if x.isdigit() else 0)
            pubmed_ids = ', '.join(pmids_sorted)

            # Extract gene, disease, MOI from proposition
            # The proposition may be inline (full object) or a JSON-LD reference
            # (dict with only an 'id' key). Resolve references before extracting.
            proposition = self._resolve_reference(data, data.get('proposition', {}))
            gene_id = self._transform_gene(proposition.get('subjectGene', ''))
            disease_id = self._transform_disease(proposition.get('objectCondition', ''))
            mode_of_inheritance = self._transform_moi(proposition.get('qualifierModeOfInheritance', ''))

            # SOP version and classification
            sop_version = data.get('specifiedBy', '')
            classification = data.get('classification', '')
            raw_classification_id = get_classification_id(classification)
            classification_id = raw_classification_id.upper() if raw_classification_id else ''

            # Notes
            notes = self._clean_notes(data.get('dc:description', ''))

            return {
                'local_id': local_id,
                'gene_id': gene_id,
                'disease_id': disease_id,
                'mode_of_inheritance': mode_of_inheritance,
                'submitter_id': 'GENCC:000102',
                'classification_id': classification_id,
                'classification': classification,
                'report_date': self._format_date_ymd(approval_date),
                'public_report_url': public_report_url,
                'notes': notes,
                'pubmed_ids': pubmed_ids,
                'assertion_criteria_url': get_assertion_criteria_url(sop_version)
            }

        except json.JSONDecodeError as e:
            log_error(f"JSON decode error in {json_path.name}: {e}")
            return None
        except Exception as e:
            log_error(f"Error processing {json_path.name}: {e}")
            return None

    def process_all(self) -> int:
        """
        Process all JSON files and write to TSV.

        Returns:
            Number of records processed.
        """
        log_info("Processing GeneGraph JSON files...")

        json_files = sorted(self.config.genegraph_extract_dir.glob('*.json'))

        if not json_files:
            log_error(f"No JSON files found in {self.config.genegraph_extract_dir}")
            return 0

        log_info(f"Found {len(json_files)} JSON files")

        with open(self.config.genegraph_output, 'w', encoding='utf-8', newline='') as out:
            writer = csv.writer(out, delimiter='\t', quoting=csv.QUOTE_MINIMAL)
            writer.writerow(TARGET_TSV_FIELDS)

            record_count = 0
            logger = get_logger()

            for i, json_file in enumerate(json_files, 1):
                if i % 500 == 0:
                    logger.progress(i, len(json_files), "GeneGraph")

                record = self.process_json_file(json_file)
                if record:
                    row = [record.get(h, '') for h in TARGET_TSV_FIELDS]
                    writer.writerow(row)
                    record_count += 1

        skipped_count = len(json_files) - record_count
        log_success(f"Processing complete: {self.config.genegraph_output}")
        log_info(f"Total records processed: {record_count}")
        if skipped_count > 0:
            log_warn(f"Files skipped (missing GCISnapshot): {skipped_count}")

        return record_count

    def run(self, force_download: bool = False) -> int:
        """
        Run the complete GeneGraph processing pipeline.

        Args:
            force_download: Force re-download of data.

        Returns:
            Number of records processed.
        """
        self.download(force=force_download)
        self.extract()
        return self.process_all()

    # Helper methods
    def _get_evaluated_contribution(self, contributions: Any) -> Optional[Dict]:
        """Extract the Evaluated contribution dict."""
        if not contributions or not isinstance(contributions, list):
            return None

        for contrib in contributions:
            if not isinstance(contrib, dict):
                continue
            if contrib.get('activityType') == 'Evaluated':
                return contrib
        return None

    def _build_report_url(self, eval_contrib: Optional[Dict]) -> str:
        """
        Build public report URL from Evaluated contribution.

        Extracts UUID from contribution id and appends the date.
        Example: https://search.clinicalgenome.org/kb/gene-validity/CGGV:assertion_<uuid>-<date>
        """
        if not eval_contrib:
            return ''

        contrib_id = eval_contrib.get('id', '')
        date = eval_contrib.get('date', '')

        # Extract and validate UUID from the path after /r/.
        # Accept optional "assertion_" prefix and optional contrib-style suffixes.
        uuid = ''
        if '/r/' in contrib_id:
            path_part = contrib_id.split('/r/')[-1]
            match = re.fullmatch(
                r'(?:assertion_)?'
                r'([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})'
                r'(?:_\w*contrib)?',
                path_part,
            )
            if match:
                uuid = match.group(1)

        if not uuid or not date:
            return ''

        return f"https://search.clinicalgenome.org/kb/gene-validity/CGGV:assertion_{uuid}-{date}"

    def _extract_pmids_recursive(self, obj: Any, pmids: Set[str]) -> None:
        """Extract PubMed URLs from hasEvidenceLines/hasEvidenceItems."""
        if isinstance(obj, dict):
            # Check dc:source for PubMed URLs
            source = obj.get('dc:source')
            if source and isinstance(source, str) and 'pubmed' in source:
                pmids.add(source)
            for value in obj.values():
                self._extract_pmids_recursive(value, pmids)
        elif isinstance(obj, list):
            for item in obj:
                self._extract_pmids_recursive(item, pmids)

    @staticmethod
    def _resolve_reference(document: Dict, obj: Any) -> Dict:
        """Resolve a JSON-LD reference to its full inline object.

        In the GeneGraph JSON-LD data, a nested object (e.g. ``proposition``)
        can appear in two forms:

        * **Inline** — a dict containing the full set of fields.
        * **Reference** — a dict whose only key is ``id``, pointing to an
          object defined elsewhere in the document.

        When ``obj`` is a reference, this method searches ``dc:isVersionOf``
        for an object whose ``id`` matches and returns it.  If no match is
        found (or ``obj`` is already inline), the original ``obj`` is returned
        unchanged.
        """
        if not isinstance(obj, dict):
            return obj if isinstance(obj, dict) else {}

        # Already inline — has more than just 'id'
        if set(obj.keys()) != {'id'}:
            return obj

        ref_id = obj['id']

        # Search dc:isVersionOf for the matching object
        version_of = document.get('dc:isVersionOf')
        if version_of is None:
            return obj

        candidates = version_of if isinstance(version_of, list) else [version_of]
        for candidate in candidates:
            if isinstance(candidate, dict) and candidate.get('id') == ref_id:
                return candidate

        return obj

    def _to_str(self, value: Any) -> str:
        """Coerce a value that may be a list to a semicolon-separated string."""
        if value is None:
            return ''
        if isinstance(value, list):
            return '; '.join(str(v) for v in value)
        return str(value)

    def _transform_gene(self, gene: Any) -> str:
        """Transform gene ID from hgnc:5036 to HGNC:5036."""
        gene = self._to_str(gene)
        if not gene:
            return ''
        return gene.upper()

    def _transform_disease(self, disease: Any) -> str:
        """Transform disease ID from obo:MONDO_999999 to MONDO:999999."""
        disease = self._to_str(disease)
        if not disease:
            return ''
        return re.sub(r'(?i)obo:MONDO_', 'MONDO:', disease).upper()

    def _transform_moi(self, moi: Any) -> str:
        """Transform MOI from obo:HP_999999 to HP:999999."""
        moi = self._to_str(moi)
        if not moi:
            return ''
        return re.sub(r'(?i)obo:HP_', 'HP:', moi).upper()

    def _clean_notes(self, notes: Any) -> str:
        """Clean and normalize notes field."""
        if not notes:
            return ''

        if isinstance(notes, list):
            notes_str = '\n\n'.join(str(item) for item in notes if item)
        else:
            notes_str = str(notes)

        if not notes_str:
            return ''

        # Normalize line endings
        notes_str = notes_str.replace('\r\n', '\n').replace('\r', '\n')

        # Remove problematic control characters
        notes_str = re.sub(r'[\x00-\x08\x0b-\x1f]', ' ', notes_str)
        notes_str = re.sub(r'[\x80-\x9f]', '', notes_str)
        notes_str = notes_str.replace('\xa0', ' ')

        return notes_str

    def _format_date_ymd(self, date_str: str) -> str:
        """Format date string as YYYY/MM/DD."""
        if not date_str:
            return ''
        try:
            date = date_str.split('T')[0]
            year, month, day = date.split('-')
            return f"{year}/{month}/{day}"
        except Exception:
            return ''


class GCIExpressProcessor:
    """
    Processes GCI Express data from GitHub.

    Downloads JSON file, extracts records, and converts to TSV.
    """

    def __init__(self, config: SourceConfig = None):
        """Initialize processor with configuration."""
        self.config = config or SourceConfig()

    def download(self, force: bool = False) -> Path:
        """
        Download the GCI Express data file.

        Args:
            force: If True, re-download even if file exists.

        Returns:
            Path to downloaded file.
        """
        log_info(f"Downloading GCI Express data from GitHub...")

        if self.config.gci_express_download.exists() and not force:
            log_warn("File already exists. Skipping download.")
            log_warn(f"Delete {self.config.gci_express_download} to re-download.")
            return self.config.gci_express_download

        self.config.gci_express_download.parent.mkdir(parents=True, exist_ok=True)

        req = urllib.request.Request(
            self.config.gci_express_url,
            headers={'User-Agent': 'Mozilla/5.0'}
        )

        with urllib.request.urlopen(req) as response:
            data = response.read()

        with open(self.config.gci_express_download, 'wb') as f:
            f.write(data)

        log_success(f"Download complete: {self.config.gci_express_download}")
        log_info(f"File size: {len(data) / 1024 / 1024:.2f} MB")

        return self.config.gci_express_download

    def extract(self) -> int:
        """
        Extract individual records from the JSON file.

        Returns:
            Number of records extracted.
        """
        log_info("Extracting GCI Express records...")

        # Remove old extraction if it exists
        if self.config.gci_express_extract_dir.exists():
            shutil.rmtree(self.config.gci_express_extract_dir)

        self.config.gci_express_extract_dir.mkdir(parents=True, exist_ok=True)

        with open(self.config.gci_express_download, 'r', encoding='utf-8') as f:
            data = json.load(f)

        if not isinstance(data, dict):
            log_error(f"Expected root to be a dictionary, got {type(data)}")
            return 0

        log_info(f"Found {len(data)} root-level keys")

        extracted_count = 0
        for key, record in data.items():
            output_file = self.config.gci_express_extract_dir / f"gci_express_{key}.json"
            with open(output_file, 'w', encoding='utf-8') as f:
                json.dump(record, f, indent=2)
            extracted_count += 1

        log_success(f"Extraction complete: {extracted_count} records")
        return extracted_count

    def process_record(self, record_id: str, data: Dict) -> Optional[Dict[str, str]]:
        """
        Process a single GCI Express record.

        Args:
            record_id: The record ID (from JSON key).
            data: The record data.

        Returns:
            Dictionary with submission fields, or None if invalid.
        """
        try:
            # Extract gene_id
            gene_id = ''
            if 'genes' in data and isinstance(data['genes'], dict):
                for gene_key, gene_data in data['genes'].items():
                    if isinstance(gene_data, dict) and 'curie' in gene_data:
                        gene_id = gene_data['curie'].upper()
                        break

            if not gene_id:
                return None

            # Extract disease_id
            disease_id = self._extract_mondo_id(data.get('conditions', {}))
            if not disease_id:
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

            # Extract from scoreJsonSerializedSop5
            score_json_sop5 = data.get('scoreJsonSerializedSop5', '')
            mode_of_inheritance = self._extract_moi_from_score_json(score_json_sop5).upper()
            pubmed_ids = self._extract_pmids_from_score_json(score_json_sop5)
            notes = self._extract_notes_from_score_json(score_json_sop5)

            # Format date
            report_date = self._format_date_ymd(data.get('dateISO8601', ''))

            # Determine assertion criteria URL
            assertion_criteria_url = GCI_EXPRESS_SOP5_URL if score_json_sop5 and score_json_sop5.strip() else GCI_EXPRESS_SOP4_URL

            return {
                'local_id': record_id,
                'gene_id': gene_id,
                'disease_id': disease_id,
                'mode_of_inheritance': mode_of_inheritance,
                'submitter_id': 'GENCC:000102',
                'classification_id': classification_id,
                'classification': classification,
                'report_date': report_date,
                'public_report_url': f"https://search.clinicalgenome.org/kb/gene-validity/CGGCIEX:assertion_{record_id}",
                'notes': notes,
                'pubmed_ids': pubmed_ids,
                'assertion_criteria_url': assertion_criteria_url
            }

        except Exception as e:
            log_error(f"Error processing GCI Express record {record_id}: {e}")
            return None

    def process_all(self) -> int:
        """
        Process all extracted JSON files and write to TSV.

        Returns:
            Number of records processed.
        """
        log_info("Converting GCI Express records to TSV format...")

        json_files = sorted(self.config.gci_express_extract_dir.glob('gci_express_*.json'))

        if not json_files:
            log_error(f"No GCI Express JSON files found in {self.config.gci_express_extract_dir}")
            return 0

        log_info(f"Found {len(json_files)} GCI Express records")

        with open(self.config.gci_express_output, 'w', encoding='utf-8', newline='') as out:
            writer = csv.writer(out, delimiter='\t', quoting=csv.QUOTE_MINIMAL)
            writer.writerow(TARGET_TSV_FIELDS)

            processed_count = 0
            skipped_count = 0

            for json_file in json_files:
                record_id = json_file.stem.replace('gci_express_', '')

                with open(json_file, 'r', encoding='utf-8') as f:
                    data = json.load(f)

                record = self.process_record(record_id, data)

                if record:
                    row = [record.get(h, '') for h in TARGET_TSV_FIELDS]
                    writer.writerow(row)
                    processed_count += 1
                else:
                    skipped_count += 1

        log_success(f"Processing complete: {self.config.gci_express_output}")
        log_info(f"Records processed: {processed_count}")
        if skipped_count > 0:
            log_warn(f"Records skipped: {skipped_count}")

        return processed_count

    def run(self, force_download: bool = False) -> int:
        """
        Run the complete GCI Express processing pipeline.

        Args:
            force_download: Force re-download of data.

        Returns:
            Number of records processed.
        """
        self.download(force=force_download)
        self.extract()
        return self.process_all()

    # Helper methods
    def _extract_mondo_id(self, conditions: Dict) -> str:
        """Extract MONDO ID from conditions."""
        if not conditions or 'MONDO' not in conditions:
            return ''

        mondo = conditions['MONDO']

        if 'iri' in mondo:
            iri = mondo['iri']
            match = re.search(r'MONDO[_:](\d+)', iri)
            if match:
                return f"MONDO:{match.group(1)}"

        if 'curie' in mondo:
            return mondo['curie']

        return ''

    def _extract_moi_from_score_json(self, score_json_str: str) -> str:
        """Extract mode of inheritance from scoreJsonSerializedSop5."""
        if not score_json_str:
            return 'HP:0000005'

        try:
            score_data = json.loads(score_json_str)
            if 'scoreJson' in score_data and 'ModeOfInheritance' in score_data['scoreJson']:
                moi_text = score_data['scoreJson']['ModeOfInheritance']
                match = re.search(r'HP:\d+', moi_text)
                if match:
                    return match.group(0)
            return 'HP:0000005'
        except json.JSONDecodeError:
            return 'HP:0000005'

    def _extract_pmids_from_score_json(self, score_json_str: str) -> str:
        """Extract PMIDs from scoreJsonSerializedSop5."""
        if not score_json_str:
            return ''

        try:
            score_data = json.loads(score_json_str)
            pmids = set()

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
            pmids_sorted = sorted(pmids, key=lambda x: int(x) if x.isdigit() else 0)
            return ', '.join(pmids_sorted)
        except json.JSONDecodeError:
            return ''

    def _extract_notes_from_score_json(self, score_json_str: str) -> str:
        """Extract notes from scoreJsonSerializedSop5."""
        if not score_json_str:
            return ''

        try:
            score_data = json.loads(score_json_str)
            if isinstance(score_data, dict) and 'notes' in score_data:
                notes_obj = score_data['notes']
                if isinstance(notes_obj, dict) and 'note' in notes_obj:
                    note = notes_obj['note']
                    if note:
                        note = str(note).replace('\r\n', '\n').replace('\r', '\n')
                        return note
            return ''
        except json.JSONDecodeError:
            return ''

    def _format_date_ymd(self, date_str: str) -> str:
        """Format date string as YYYY/MM/DD."""
        if not date_str:
            return ''
        try:
            date = date_str.split('T')[0]
            year, month, day = date.split('-')
            return f"{year}/{month}/{day}"
        except Exception:
            return ''


def merge_source_files(source_config: SourceConfig = None, output_config: OutputConfig = None) -> int:
    """
    Merge GeneGraph and GCI Express TSV files into combined target.

    Args:
        source_config: Source configuration.
        output_config: Output configuration.

    Returns:
        Total number of records in merged file.
    """
    if source_config is None:
        source_config = SourceConfig()
    if output_config is None:
        output_config = OutputConfig()

    log_info("Merging GeneGraph and GCI Express data...")

    # Read gene validity data (includes header)
    with open(source_config.genegraph_output, 'r', encoding='utf-8') as f:
        genegraph_lines = f.readlines()

    # Read GCI Express data (skip header)
    with open(source_config.gci_express_output, 'r', encoding='utf-8') as f:
        gci_express_lines = f.readlines()[1:]

    # Write combined file
    with open(output_config.combined_target, 'w', encoding='utf-8') as f:
        f.writelines(genegraph_lines)
        f.writelines(gci_express_lines)

    genegraph_count = len(genegraph_lines) - 1  # Exclude header
    gci_express_count = len(gci_express_lines)
    total_count = genegraph_count + gci_express_count

    log_success("Merged files successfully:")
    log_info(f"  - GeneGraph: {genegraph_count} records")
    log_info(f"  - GCI Express: {gci_express_count} records")
    log_info(f"  - Combined Total: {total_count} records")
    log_info(f"  - Output: {output_config.combined_target}")

    return total_count
