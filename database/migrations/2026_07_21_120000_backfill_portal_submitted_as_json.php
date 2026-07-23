<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repair submitted-as JSON written by the portal's placeholder-based form path.
 *
 * Portal controls present relationship identifiers together with their lookup labels. Missing
 * values can therefore be recovered from authoritative relationships. Existing nonblank values
 * are preserved, including literal API/spreadsheet values on records outside this portal repair.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lookups = [
            'genes' => DB::table('genes')->select('id', 'hgnc_id', 'symbol')->get()->keyBy('id'),
            'diseases' => DB::table('diseases')->select('id', 'curie', 'name')->get()->keyBy('id'),
            'inheritances' => DB::table('inheritances')->select('id', 'curie', 'name')->get()->keyBy('id'),
            'classifications' => DB::table('classifications')->select('id', 'curie', 'name')->get()->keyBy('id'),
            'submitters' => DB::table('submitters')->select('id', 'curie', 'name')->get()->keyBy('id'),
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
                    $originalData = $this->decodeDocument($row->original_submission_data);
                    $portalPlaceholder = ($submissionData !== null && $this->isPortalPlaceholder($submissionData))
                        || ($originalData !== null && $this->isPortalPlaceholder($originalData));

                    if (! $portalPlaceholder) {
                        continue;
                    }

                    if ($submissionData !== null
                        && $this->repairDocument($submissionData, $row, $lookups, $portalPlaceholder)) {
                        $updates['submission_data'] = json_encode($submissionData);
                    }

                    if ($originalData !== null
                        && $originalData !== []
                        && $this->repairDocument($originalData, $row, $lookups, $portalPlaceholder)) {
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

    private function repairDocument(array &$data, object $row, array $lookups, bool $portalPlaceholder): bool
    {
        $changed = false;

        $gene = $lookups['genes']->get($row->gene_id);
        $changed = $this->repairRelationship(
            $data,
            'gene',
            'symbol',
            $gene?->hgnc_id,
            $gene?->symbol,
            $portalPlaceholder,
        ) || $changed;

        $inheritance = $lookups['inheritances']->get($row->inheritance_id);
        $changed = $this->repairRelationship(
            $data,
            'moi',
            'name',
            $inheritance?->curie,
            $inheritance?->name,
            $portalPlaceholder,
        ) || $changed;

        $classification = $lookups['classifications']->get($row->classification_id);
        $changed = $this->repairRelationship(
            $data,
            'classification',
            'name',
            $classification?->curie,
            $classification?->name,
            $portalPlaceholder,
        ) || $changed;

        $disease = $lookups['diseases']->get($row->original_disease_id);
        $changed = $this->repairRelationship(
            $data,
            'disease',
            'name',
            $disease?->curie,
            $disease?->name,
            $portalPlaceholder,
        ) || $changed;

        $additionalInformation = $data['additional_information'] ?? [];
        if (! is_array($additionalInformation) || array_is_list($additionalInformation)) {
            $additionalInformation = [];
        }

        $submitter = $lookups['submitters']->get($row->submitter_id);
        $submitterCurie = $submitter?->curie;
        if (! array_key_exists('submitter_curie', $additionalInformation)
            || $this->valueIsMissing($additionalInformation['submitter_curie'])) {
            $additionalInformation['submitter_curie'] = $submitterCurie;
            $changed = true;
        }
        if (! array_key_exists('submitter_title', $additionalInformation)
            || $this->valueIsMissing($additionalInformation['submitter_title'])) {
            $additionalInformation['submitter_title'] = $submitter?->name;
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

    private function repairRelationship(
        array &$data,
        string $section,
        string $labelKey,
        ?string $identifier,
        ?string $label,
        bool $portalPlaceholder,
    ): bool {
        if (! $portalPlaceholder) {
            return false;
        }

        $relationship = $data[$section] ?? [];
        if (! is_array($relationship) || array_is_list($relationship)) {
            $relationship = [];
        }

        if (! array_key_exists('id', $relationship) || $this->valueIsMissing($relationship['id'])) {
            $relationship['id'] = $identifier;
        }
        if (! array_key_exists($labelKey, $relationship) || $this->valueIsMissing($relationship[$labelKey])) {
            $relationship[$labelKey] = $label;
        }

        if (($data[$section] ?? null) === $relationship) {
            return false;
        }

        $data[$section] = $relationship;

        return true;
    }

    private function isPortalPlaceholder(array $data): bool
    {
        return $this->relationshipIdentifierIsMissing($data, 'gene')
            && $this->relationshipIdentifierIsMissing($data, 'moi')
            && $this->relationshipIdentifierIsMissing($data, 'classification')
            && $this->additionalInformationIsPlaceholder($data['additional_information'] ?? null);
    }

    private function relationshipIdentifierIsMissing(array $data, string $section): bool
    {
        return ! is_array($data[$section] ?? null)
            || $this->valueIsMissing($data[$section]['id'] ?? null);
    }

    private function valueIsMissing(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
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
        // The prior relationship values were missing and cannot be recovered after repair.
    }
};
