<?php

namespace App\Exports;

use App\Models\Submission;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

/**
 * CSV/XLSX Export for release submissions - uses FromQuery for efficient chunked processing.
 * This significantly reduces memory usage by not loading all 25K+ records at once.
 *
 * Matches the gencc-search SubmissionExport format exactly.
 *
 * Note: Excel::TSV is just an alias for 'Csv' in Maatwebsite Excel — it does NOT
 * automatically use tab delimiters. Pass delimiter: "\t" to the constructor for TSV.
 */
class ReleaseSubmissionExport implements FromQuery, WithHeadings, WithMapping, WithCustomCsvSettings
{
    use Exportable;

    private bool $useLegacyFormat;

    private string $delimiter;

    public function __construct(bool $useLegacyFormat = false, string $delimiter = ',')
    {
        $this->useLegacyFormat = $useLegacyFormat;
        $this->delimiter = $delimiter;
    }

    /**
     * Build the legacy UUID string for a submission.
     * Format: {SUBMITTER}-{GENE_HGNC}-{DISEASE}-{MOI}-{CLASSIFICATION}
     * Colons are replaced with underscores.
     */
    private function buildLegacyUuid($submission): string
    {
        $diseaseCurie = $submission->originalDisease?->curie
            ?? $submission->disease?->curie
            ?? '';

        return sprintf('%s-%s-%s-%s-%s',
            str_replace(':', '_', $submission->submitter?->curie ?? ''),
            str_replace(':', '_', $submission->gene?->hgnc_id ?? ''),
            str_replace(':', '_', $diseaseCurie),
            str_replace(':', '_', $submission->inheritance?->curie ?? ''),
            str_replace(':', '_', $submission->classification?->curie ?? '')
        );
    }

    /**
     * Return a query builder for chunked processing.
     * Eager loads relationships to prevent N+1 queries.
     */
    public function query()
    {
        return Submission::query()
            ->where('is_live', true)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->with([
                'gene',
                'disease',
                'originalDisease',
                'classification',
                'inheritance',
                'submitter',
            ])
            ->orderBy('sid');
    }

    /**
     * Map a submission to a row array.
     *
     * @param Submission $submission
     */
    public function map($submission): array
    {
        // Extract submitted_as fields from original_submission_data JSON
        $originalData = $submission->original_submission_data;

        $submittedAsHgncId = $this->getNestedValue($originalData, ['gene', 'id']);
        $submittedAsHgncSymbol = $this->getNestedValue($originalData, ['gene', 'symbol']);
        $submittedAsDiseaseId = $this->getNestedValue($originalData, ['disease', 'id']);
        $submittedAsDiseaseName = $this->getNestedValue($originalData, ['disease', 'name']);
        $submittedAsMoiId = $this->getNestedValue($originalData, ['moi', 'id']);
        $submittedAsMoiName = $this->getNestedValue($originalData, ['moi', 'name']);
        $submittedAsSubmitterId = $this->getNestedValue($originalData, ['additional_information', 'submitter_curie']);
        $submittedAsSubmitterName = $this->getNestedValue($originalData, ['additional_information', 'submitter_title']);
        $submittedAsClassificationId = $this->getNestedValue($originalData, ['classification', 'id']);
        $submittedAsClassificationName = $this->getNestedValue($originalData, ['classification', 'name']);
        $submittedAsDate = $this->getNestedValue($originalData, ['report', 'display_date']);
        $submittedAsReportUrl = $this->getNestedValue($originalData, ['report', 'ext_url']);
        // Notes come from submission_data (current/editable) rather than original_submission_data
        $submittedAsNotes = $this->getNestedValue($submission->submission_data, ['notes', 'display']);
        $submittedAsCriteriaUrl = $this->getNestedValue($originalData, ['criteria', 'url']);
        $submittedAsSubmissionId = $this->getNestedValue($originalData, ['additional_information', 'submitted_as_submission_id']);

        // PMIDs - add space after commas for readability
        $pmids = $submission->normalized_pmids
            ? str_replace(',', ', ', $submission->normalized_pmids)
            : '';

        // submitted_run_date is the release date (date portion only)
        $submittedRunDate = $submission->released_at?->format('Y-m-d') ?? '';

        // Common fields after the ID column(s)
        $commonFields = [
            $submission->gene?->hgnc_id ?? '',
            $submission->gene?->symbol ?? '',
            $submission->disease?->curie ?? '',
            $submission->disease?->name ?? '',
            $submission->originalDisease?->curie ?? '',
            $submission->originalDisease?->name ?? '',
            $submission->classification?->curie ?? '',
            $submission->classification?->name ?? '',
            $submission->inheritance?->curie ?? '',
            $submission->inheritance?->name ?? '',
            $submission->submitter?->curie ?? '',
            $submission->submitter?->name ?? '',
            $submittedAsHgncId,
            $submittedAsHgncSymbol,
            $submittedAsDiseaseId,
            $submittedAsDiseaseName,
            $submittedAsMoiId,
            $submittedAsMoiName,
            $submittedAsSubmitterId,
            $submittedAsSubmitterName,
            $submittedAsClassificationId,
            $submittedAsClassificationName,
            $submittedAsDate,
            $submittedAsReportUrl,
            $submittedAsNotes,
            $pmids,
            $submittedAsCriteriaUrl,
            $submittedAsSubmissionId,
            $submittedRunDate,
        ];

        if ($this->useLegacyFormat) {
            return array_merge([$this->buildLegacyUuid($submission)], $commonFields);
        }

        return array_merge([$submission->sid, $submission->version_number ?? 1], $commonFields);
    }

    /**
     * Define the CSV/Excel headings.
     */
    public function headings(): array
    {
        $commonHeadings = [
            'gene_curie',
            'gene_symbol',
            'disease_curie',
            'disease_title',
            'disease_original_curie',
            'disease_original_title',
            'classification_curie',
            'classification_title',
            'moi_curie',
            'moi_title',
            'submitter_curie',
            'submitter_title',
            'submitted_as_hgnc_id',
            'submitted_as_hgnc_symbol',
            'submitted_as_disease_id',
            'submitted_as_disease_name',
            'submitted_as_moi_id',
            'submitted_as_moi_name',
            'submitted_as_submitter_id',
            'submitted_as_submitter_name',
            'submitted_as_classification_id',
            'submitted_as_classification_name',
            'submitted_as_date',
            'submitted_as_public_report_url',
            'submitted_as_notes',
            'submitted_as_pmids',
            'submitted_as_assertion_criteria_url',
            'submitted_as_submission_id',
            'submitted_run_date',
        ];

        if ($this->useLegacyFormat) {
            return array_merge(['uuid'], $commonHeadings);
        }

        return array_merge(['sgc_id', 'version_number'], $commonHeadings);
    }

    /**
     * CSV settings — controls the delimiter for CSV/TSV exports.
     */
    public function getCsvSettings(): array
    {
        return [
            'delimiter' => $this->delimiter,
        ];
    }

    /**
     * Helper function to extract nested values from objects/arrays.
     */
    private function getNestedValue($data, array $keys, string $default = ''): string
    {
        if ($data === null) {
            return $default;
        }

        foreach ($keys as $key) {
            if (is_object($data)) {
                $data = $data->$key ?? null;
            } elseif (is_array($data)) {
                $data = $data[$key] ?? null;
            } else {
                return $default;
            }

            if ($data === null) {
                return $default;
            }
        }

        return (string) $data;
    }
}
