<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repair submitted-as JSON written by the portal's placeholder-based form path.
 *
 * Portal controls submit relationship identifiers, not relationship labels. Missing identifiers
 * can therefore be recovered from their authoritative foreign keys, while labels must remain null.
 * Existing non-placeholder API/spreadsheet values are not replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lookups = [
            'genes' => DB::table('genes')->select('id', 'hgnc_id')->get()->keyBy('id'),
            'diseases' => DB::table('diseases')->select('id', 'curie')->get()->keyBy('id'),
            'inheritances' => DB::table('inheritances')->select('id', 'curie')->get()->keyBy('id'),
            'classifications' => DB::table('classifications')->select('id', 'curie')->get()->keyBy('id'),
            'submitters' => DB::table('submitters')->select('id', 'curie')->get()->keyBy('id'),
        ];

        $patchedRows = 0;

        DB::table('submissions')
            ->where('type', 3) // Submission::TYPE_PORTAL_SUBMISSION
            ->select([
                'id', 'sid', 'friendly', 'local_key', 'gene_id', 'original_disease_id',
                'inheritance_id', 'classification_id', 'submitter_id',
                'submission_data', 'original_submission_data',
            ])
            ->chunkById(500, function ($rows) use ($lookups, &$patchedRows) {
                foreach ($rows as $row) {
                    $updates = [];

                    $submissionData = $this->decodeDocument($row->submission_data);
                    if ($submissionData !== null && $this->repairDocument($submissionData, $row, $lookups)) {
                        $updates['submission_data'] = json_encode($submissionData);
                    }

                    $originalData = $this->decodeDocument($row->original_submission_data);
                    if ($originalData !== null
                        && $originalData !== []
                        && $this->repairDocument($originalData, $row, $lookups)) {
                        $updates['original_submission_data'] = json_encode($originalData);
                    }

                    if ($updates === []) {
                        continue;
                    }

                    DB::table('submissions')->where('id', $row->id)->update($updates);
                    $patchedRows++;
                }
            }, 'id');

        Log::info("Portal submitted-as JSON backfill repaired {$patchedRows} submission row(s).");
    }

    private function decodeDocument(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function repairDocument(array &$data, object $row, array $lookups): bool
    {
        $changed = false;
        $portalPlaceholder = $this->identifierIsMissing($data, 'gene')
            && $this->identifierIsMissing($data, 'moi')
            && $this->identifierIsMissing($data, 'classification')
            && $this->additionalInformationIsPlaceholder($data['additional_information'] ?? null);

        $changed = $this->repairIdentifier(
            $data,
            'gene',
            'symbol',
            $lookups['genes']->get($row->gene_id)?->hgnc_id,
        ) || $changed;
        $changed = $this->repairIdentifier(
            $data,
            'moi',
            'name',
            $lookups['inheritances']->get($row->inheritance_id)?->curie,
        ) || $changed;
        $changed = $this->repairIdentifier(
            $data,
            'classification',
            'name',
            $lookups['classifications']->get($row->classification_id)?->curie,
        ) || $changed;

        // The existing disease portal handler stored a lookup-derived name even though the form
        // submitted only a CURIE. The full placeholder fingerprint proves this document came from
        // that form path, so remove the fabricated label while preserving the selected identifier.
        if ($portalPlaceholder) {
            $diseaseCurie = $lookups['diseases']->get($row->original_disease_id)?->curie;
            $disease = ['id' => $diseaseCurie, 'name' => null];
            if (($data['disease'] ?? null) !== $disease) {
                $data['disease'] = $disease;
                $changed = true;
            }
        } else {
            $changed = $this->repairIdentifier(
                $data,
                'disease',
                'name',
                $lookups['diseases']->get($row->original_disease_id)?->curie,
            ) || $changed;
        }

        $additionalInformation = $data['additional_information'] ?? [];
        if (! is_array($additionalInformation) || array_is_list($additionalInformation)) {
            $additionalInformation = [];
        }

        $submitterCurie = $lookups['submitters']->get($row->submitter_id)?->curie;
        if (! array_key_exists('submitter_curie', $additionalInformation)
            || $additionalInformation['submitter_curie'] === '') {
            $additionalInformation['submitter_curie'] = $submitterCurie;
            $additionalInformation['submitter_title'] = null;
            $changed = true;
        }
        if (! array_key_exists('submitter_title', $additionalInformation)) {
            $additionalInformation['submitter_title'] = null;
            $changed = true;
        }
        if (($additionalInformation['submitted_as_submission_id'] ?? null) !== $row->local_key) {
            $additionalInformation['submitted_as_submission_id'] = $row->local_key;
            $changed = true;
        }
        if (($data['additional_information'] ?? null) !== $additionalInformation) {
            $data['additional_information'] = $additionalInformation;
            $changed = true;
        }

        foreach ([
            'submission_id' => $row->sid,
            'local_key' => $row->local_key,
            'submission_label' => $row->friendly,
        ] as $key => $value) {
            if (($data[$key] ?? null) !== $value) {
                $data[$key] = $value;
                $changed = true;
            }
        }

        return $changed;
    }

    private function repairIdentifier(array &$data, string $section, string $labelKey, ?string $identifier): bool
    {
        if (! $this->identifierIsMissing($data, $section)) {
            return false;
        }

        $replacement = ['id' => $identifier, $labelKey => null];
        if (($data[$section] ?? null) === $replacement) {
            return false;
        }

        $data[$section] = $replacement;

        return true;
    }

    private function identifierIsMissing(array $data, string $section): bool
    {
        return ! is_array($data[$section] ?? null)
            || trim((string) ($data[$section]['id'] ?? '')) === '';
    }

    private function additionalInformationIsPlaceholder(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && count($value) === 1
            && ($value[0] ?? null) === ['key' => 'values'];
    }

    public function down(): void
    {
        // The prior values were missing identifiers or lookup-derived labels, not recoverable
        // submitted content. Reverting would intentionally recreate the data defect.
    }
};
