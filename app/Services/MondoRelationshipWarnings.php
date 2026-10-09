<?php

namespace App\Services;

use App\Models\Disease;
use App\Models\Submission;
use Illuminate\Support\Collection;

/** Advisory comparison, deliberately separate from submitted-ID duplicate errors. */
class MondoRelationshipWarnings
{
    public const MESSAGE = 'Multiple assertions resolve to the same MONDO relationship';

    public const PEER_LIMIT = 20;

    public static function candidate(Submission $submission, ?Disease $mondo = null): array
    {
        return [
            'id' => $submission->id, 'sid' => $submission->sid, 'version' => $submission->version_number,
            'submitter_id' => $submission->submitter_id, 'gene_id' => $submission->gene_id,
            'mondo' => self::mondoCurie($mondo ?? $submission->disease), 'inheritance_id' => $submission->inheritance_id,
            'original_disease_id' => $submission->original_disease_id,
            'submitted_id' => data_get($submission->submission_data, 'disease.id'),
            'job' => $submission->job?->slug, 'status' => $submission->status,
        ];
    }

    /** The term's CURIE when it is a usable MONDO term, otherwise null. */
    private static function mondoCurie(?Disease $mondo): ?string
    {
        $usable = $mondo && ! $mondo->trashed() && (int) $mondo->type === Disease::TYPE_MONDO
            && in_array((int) $mondo->status, [Disease::STATUS_ACTIVE, Disease::STATUS_DEPRECATED], true);

        return $usable ? $mondo->curie : null;
    }

    public static function key(array $row): ?string
    {
        if (empty($row['submitter_id']) || empty($row['gene_id']) || empty($row['inheritance_id'])
            || ! preg_match('/^MONDO:[0-9]+$/D', $row['mondo'] ?? '')) {
            return null;
        }

        return implode('|', [$row['submitter_id'], $row['gene_id'], $row['mondo'], $row['inheritance_id']]);
    }

    public static function groups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if ($key = self::key($row)) {
                $groups[$key][] = $row;
            }
        }

        return $groups;
    }

    /** One entry per distinct peer assertion, retaining version context. */
    public static function compare(array $candidate, array $groups, bool $suppressSubmittedIdDuplicates = false): ?array
    {
        $key = self::key($candidate);
        if (! $key) {
            return null;
        }
        $peers = [];
        foreach ($groups[$key] ?? [] as $peer) {
            if ((! empty($candidate['sid']) && $candidate['sid'] === ($peer['sid'] ?? null))
                || (isset($candidate['id'], $peer['id']) && $candidate['id'] === $peer['id'])
                || (isset($candidate['row_index'], $peer['row_index']) && $candidate['row_index'] === $peer['row_index'])) {
                continue;
            }
            // The existing policy already explains same-submitted-ID peers as errors/warnings.
            if ($suppressSubmittedIdDuplicates && ! empty($candidate['original_disease_id'])
                && $candidate['original_disease_id'] === ($peer['original_disease_id'] ?? null)) {
                continue;
            }
            $identity = $peer['sid'] ?? ('row:'.($peer['row_index'] ?? $peer['id']));
            $peers[$identity] ??= ['sid' => $peer['sid'] ?? null, 'versions' => []];
            $peers[$identity]['versions'][] = $peer;
        }
        if (! $peers) {
            return null;
        }
        $total = count($peers);
        $peers = array_values(array_slice($peers, 0, self::PEER_LIMIT, true));
        $labels = [];
        foreach ($peers as $peer) {
            $v = $peer['versions'][0];
            $labels[] = ($peer['sid'] ?? 'Workbook row '.$v['row_index'])
                .(! empty($v['job']) ? ' ('.$v['job'].')' : '')
                .(($v['status'] ?? null) === Submission::STATUS_UNPUBLISHED ? ' [already unpublished]' : ' ['.($v['status'] ?? 'incoming').']');
        }

        return ['type' => 'shared_mondo_relationship', 'mondo' => $candidate['mondo'], 'peer_count' => $total,
            'peers' => $peers, 'message' => self::MESSAGE.' ('.$candidate['mondo'].'). '.implode('; ', $labels)
                .($total > count($peers) ? '; additional assertions omitted' : '').'. This is a non-blocking warning; review the assertions separately.'];
    }

    /**
     * Incoming accepted mappings versus stored peers, plus other incoming rows.
     * Stored peers are queried unless the caller already holds them as candidate rows.
     */
    public static function check(array $candidates, bool $includeIncoming = true, ?array $storedPeers = null): array
    {
        $candidates = array_filter($candidates, fn ($row) => self::key($row) !== null);
        if (! $candidates) {
            return [];
        }
        $storedPeers ??= Submission::query()->whereIn('submitter_id', array_unique(array_column($candidates, 'submitter_id')))
            ->whereIn('gene_id', array_unique(array_column($candidates, 'gene_id')))
            ->where(fn ($q) => $q->where('is_live', true)->orWhere('is_most_recent', true))
            ->with(['disease', 'job:id,slug'])
            ->get(['id', 'sid', 'version_number', 'submitter_id', 'gene_id', 'disease_id',
                'original_disease_id', 'inheritance_id', 'submission_data', 'job_id', 'status'])
            ->map(fn ($s) => self::candidate($s))->all();
        $groups = self::groups(array_merge($storedPeers, $includeIncoming ? array_values($candidates) : []));
        $result = [];
        foreach ($candidates as $index => $candidate) {
            if ($warning = self::compare($candidate, $groups, true)) {
                $warning['basis'] = 'Incoming mapping compared with stored peer mappings and other incoming rows';
                $result[$index] = $warning;
            }
        }

        return $result;
    }

    /**
     * Pass $allOfSubmitter when the collection holds every submission of its submitter,
     * so stored peers come from it instead of a second query.
     */
    public static function attach(Collection $submissions, ?DiseaseResolver $resolver = null, bool $allOfSubmitter = false): void
    {
        $resolver ??= new DiseaseResolver();
        $resolver->preload($submissions->map(fn ($s) => data_get($s->submission_data, 'disease.id'))->all());
        $candidates = $storedPeers = [];
        foreach ($submissions as $submission) {
            if (! $submission->is_live && ! $submission->is_most_recent) {
                continue;
            }
            // One row per submission: as stored for the peer groups, and with the
            // current mapping of its submitted identifier as the candidate.
            $stored = self::candidate($submission);
            $storedPeers[] = $stored;
            if (! is_string($stored['submitted_id'])) {
                continue;
            }
            $validation = SubmissionValueValidation::disease($stored['submitted_id'], $resolver);
            if ($validation['error'] || $validation['original_error']) {
                continue;
            }
            $candidates[$submission->id] = array_replace($stored, ['mondo' => self::mondoCurie($validation['mondo'] ?? $submission->disease)]);
        }
        $warnings = self::check($candidates, false, $allOfSubmitter ? $storedPeers : null);
        foreach ($submissions as $submission) {
            $submission->setAttribute('mondo_relationship_warning', $warnings[$submission->id] ?? null);
        }
    }

    /** Public API batches use the same accepted incoming keys as spreadsheet rows. */
    public static function incoming(int $submitterId, array $records): array
    {
        return self::check(self::incomingCandidates($submitterId, $records));
    }

    public static function incomingCandidates(int $submitterId, array $records): array
    {
        $records = array_values($records);
        $geneId = fn ($r) => is_scalar(data_get($r, 'gene.id')) ? data_get($r, 'gene.id') : null;
        $genes = \App\Models\Gene::whereIn('hgnc_id', array_map(fn ($r) => SubmissionValueValidation::normalizeGeneId($geneId($r)), $records))->get()->keyBy('hgnc_id');
        // Trimmed like SubmissionValueValidation::inheritance(), which looks these up by trimmed ID.
        $inheritances = \App\Models\Inheritance::whereIn('curie', array_map('trim', array_filter(array_map(fn ($r) => data_get($r, 'moi.id'), $records), 'is_string')))->get()->keyBy('curie');
        $resolver = new DiseaseResolver();
        $resolver->preload(array_map(fn ($r) => data_get($r, 'disease.id'), $records));
        $candidates = [];
        foreach ($records as $index => $record) {
            $raw = data_get($record, 'disease.id');
            if (! is_string($raw)) {
                continue;
            }
            $v = SubmissionValueValidation::disease($raw, $resolver);
            if ($v['error'] || $v['original_error']) {
                continue;
            }
            $candidates[$index] = [
                'submitter_id' => $submitterId,
                'gene_id' => SubmissionValueValidation::gene($geneId($record), $genes)['record']?->id,
                'inheritance_id' => SubmissionValueValidation::inheritance(is_string(data_get($record, 'moi.id')) ? data_get($record, 'moi.id') : null, $inheritances)['record']?->id,
                'mondo' => $v['mondo']->curie, 'original_disease_id' => $v['original']->id,
                'submitted_id' => $raw, 'row_index' => $index + 1,
                'sid' => data_get($record, 'action') === 'update' ? data_get($record, 'submission_id') : null,
            ];
        }

        return $candidates;
    }
}
