<?php

namespace App\Services;

use App\Models\Disease;
use App\Models\DiseaseAuditFinding;
use App\Models\DiseaseAuditRun;
use App\Models\Submission;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DiseaseOntologyAudit
{
    public const CASES = [
        'submitted_deprecated' => 'Submitted term deprecated',
        'stored_deprecated' => 'Stored MONDO deprecated',
        'candidate_deprecated' => 'Current candidate deprecated',
        'different_target' => 'Different MONDO target',
        'no_longer_resolves' => 'Stored mapping no longer resolves',
        'ambiguous' => 'Ambiguous mapping',
        'original_missing' => 'Submitted term missing; MONDO available',
        'now_valid' => 'Previously unmapped, now valid',
        'still_unresolved' => 'Still unresolved',
        'invalid_identifier' => 'Missing or malformed identifier',
        'inconsistent_references' => 'Inconsistent stored references',
        'existing_disease_error' => 'Existing disease validation error',
        'replacement_available' => 'Usable source-named replacement',
        'replacement_not_recorded' => 'Deprecated—no replacement recorded',
        'replacement_needs_review' => 'Replacement information needs review',
        'shared_stored_mondo_relationship' => 'Shared stored MONDO relationship',
        'shared_current_mondo_relationship' => 'Shared current MONDO relationship',
    ];

    /** Successful scans kept, with any attempts between them; older ones are deleted. */
    public const KEEP_SUCCEEDED_RUNS = 8;

    /** Refuses to start while update:diseases or another audit is running. */
    public function run(): DiseaseAuditRun
    {
        $lock = DiseaseOntologyLock::forAudit();
        if ($lock === null) {
            throw new RuntimeException('A disease update or another disease audit is running. Try again when it finishes.');
        }

        $run = null;
        try {
            // A previous process may have exited without recording its failure.
            DiseaseAuditRun::where('status', DiseaseAuditRun::RUNNING)->update([
                'status' => DiseaseAuditRun::FAILED,
                'finished_at' => now(),
                'failure' => 'Audit process ended before completion.',
            ]);
            $run = DiseaseAuditRun::create([
                'status' => DiseaseAuditRun::RUNNING,
                'started_at' => now(),
                'scope' => 'Non-deleted live or most recent submission versions',
                'app_version' => config('app.version'),
            ]);

            DB::transaction(function () use ($run) {
                $resolver = new DiseaseResolver();
                $recommendations = new DiseaseReplacementRecommendations($resolver);
                $outcomes = [];
                $counts = array_fill_keys(array_keys(self::CASES), 0);
                $examined = $affected = 0;
                $run->source_metadata = DiseaseOntologySources::metadata();
                $collisionGroups = $this->collisionGroups($resolver);

                Submission::query()
                    ->where(fn ($q) => $q->where('is_live', true)->orWhere('is_most_recent', true))
                    ->with(['disease' => fn ($q) => $q->withTrashed(),
                        'originalDisease' => fn ($q) => $q->withTrashed(), 'job', 'submitter'])
                    ->chunkById(500, function ($submissions) use ($run, $resolver, $recommendations, $collisionGroups, &$outcomes, &$counts, &$examined, &$affected) {
                        // Load the chunk's submitted terms in one query before resolving them one by one.
                        $resolver->preload($submissions->map(fn ($s) => data_get($s->submission_data, 'disease.id'))->all());
                        $curies = [];
                        foreach ($submissions as $submission) {
                            $raw = data_get($submission->submission_data, 'disease.id');
                            if (is_string($raw)) {
                                $curies[] = $raw;
                                $outcome = $resolver->resolveDetailed($raw);
                                foreach ($outcome instanceof DiseaseMappingAmbiguity ? $outcome->candidates : [$outcome?->mondo] as $candidate) {
                                    $curies[] = $candidate?->curie;
                                }
                            }
                            $curies[] = $submission->disease?->curie;
                        }
                        $recommendations->preload($curies);
                        foreach ($submissions as $submission) {
                            $examined++;
                            $finding = $this->inspect($submission, $resolver, $outcomes, $recommendations, $collisionGroups);
                            if ($finding['cases'] === []) {
                                continue;
                            }
                            DiseaseAuditFinding::create(['run_id' => $run->id] + $finding);
                            $affected++;
                            foreach ($finding['cases'] as $case) {
                                $counts[$case]++;
                            }
                        }
                    });

                $run->fill([
                    'status' => DiseaseAuditRun::SUCCEEDED,
                    'finished_at' => now(),
                    'examined_count' => $examined,
                    'affected_count' => $affected,
                    'case_counts' => $counts,
                ])->save();
            });
            $this->pruneOldRuns();

            return $run->fresh();
        } catch (Throwable $e) {
            $run?->update([
                'status' => DiseaseAuditRun::FAILED,
                'finished_at' => now(),
                'failure' => 'Audit failed. See application logs for details.',
            ]);
            throw $e;
        } finally {
            DiseaseOntologyLock::release($lock);
        }
    }

    /** Scheduled weekly scans would otherwise keep every run's findings forever. */
    private function pruneOldRuns(): void
    {
        $oldestKept = DiseaseAuditRun::where('status', DiseaseAuditRun::SUCCEEDED)
            ->orderByDesc('id')->skip(self::KEEP_SUCCEEDED_RUNS - 1)->value('id');
        if ($oldestKept === null) {
            return;
        }
        $stale = DiseaseAuditRun::where('id', '<', $oldestKept)->pluck('id');
        DiseaseAuditFinding::whereIn('run_id', $stale)->delete();
        DiseaseAuditRun::whereIn('id', $stale)->delete();
    }

    private function inspect(Submission $submission, DiseaseResolver $resolver, array &$outcomes, DiseaseReplacementRecommendations $recommendations, array $collisionGroups): array
    {
        $value = data_get($submission->submission_data, 'disease.id');
        $raw = is_scalar($value) ? (string) $value : ($value === null ? '' : json_encode($value));
        $normalized = Disease::normalizeCurie($raw);
        $namespace = $normalized ? explode(':', $normalized, 2)[0] : null;
        if ($namespace !== null && ! in_array($namespace, ['MONDO', 'OMIM', 'OMIMPS', 'Orphanet'], true)) {
            $namespace = 'Other';
        }
        $key = $normalized ?? $raw;
        if (! array_key_exists($key, $outcomes)) {
            $validation = SubmissionValueValidation::disease($normalized ?? $raw, $resolver);
            $outcomes[$key] = [
                'validation' => $validation,
                'resolution' => $resolver->resolveDetailed($normalized ?? $raw),
                'submitted' => $recommendations->term($normalized),
            ];
        }
        ['validation' => $validation, 'resolution' => $resolution, 'submitted' => $submitted] = $outcomes[$key];
        $current = $validation['mondo'];
        $ambiguity = $validation['ambiguity'];
        $stored = $submission->disease;
        $original = $submission->originalDisease;
        $accepted = $validation['error'] === null && $validation['original_error'] === null;
        $existingError = data_get($submission->submission_errors, 'disease_curie_id');
        $cases = [];

        if (! $normalized || ! preg_match('/^(MONDO|OMIM|OMIMPS|Orphanet):[0-9]+$/D', $normalized)) {
            $cases[] = 'invalid_identifier';
        }
        if ($this->deprecated($submitted)) {
            $cases[] = 'submitted_deprecated';
        }
        if ($stored?->type === Disease::TYPE_MONDO && $this->deprecated($stored)) {
            $cases[] = 'stored_deprecated';
        }
        $candidates = $ambiguity?->candidates ?? ($current ? [$current] : []);
        if (collect($candidates)->contains(fn ($disease) => $this->deprecated($disease))) {
            $cases[] = 'candidate_deprecated';
        }
        if ($current && $stored?->type === Disease::TYPE_MONDO && $stored->curie !== $current->curie) {
            $cases[] = 'different_target';
        }
        if ($ambiguity) {
            $cases[] = 'ambiguous';
        } elseif (! $current && $stored?->type === Disease::TYPE_MONDO) {
            $cases[] = 'no_longer_resolves';
        }
        if ($current && ! $validation['original']) {
            $cases[] = 'original_missing';
        }
        if (! $submission->disease_id) {
            $cases[] = $accepted ? 'now_valid' : 'still_unresolved';
        }
        if (($submission->disease_id && (! $stored || $stored->trashed() || $stored->type !== Disease::TYPE_MONDO))
            || ($submission->original_disease_id && (! $original || $original->trashed() || $original->curie !== $normalized))
            || ($submission->disease_id && ! $submission->original_disease_id)) {
            $cases[] = 'inconsistent_references';
        }
        if ($existingError) {
            $cases[] = 'existing_disease_error';
        }

        $contexts = ['submitted term' => $submitted?->curie, 'stored MONDO' => $stored?->curie];
        foreach ($candidates as $candidate) {
            $contexts['current candidate '.$candidate->curie] = $candidate->curie;
        }
        $replacements = $recommendations->forContexts($contexts);
        foreach ($replacements as $advice) {
            $cases[] = match (true) {
                $advice['replacement_available'] => 'replacement_available',
                $advice['targets'] === [] && ! $advice['unparsed'] => 'replacement_not_recorded',
                default => 'replacement_needs_review',
            };
        }
        $collisions = [];
        foreach (['stored' => $stored, 'current' => $accepted ? $current : null] as $basis => $target) {
            if ($target && ($warning = MondoRelationshipWarnings::compare(
                MondoRelationshipWarnings::candidate($submission, $target), $collisionGroups[$basis]
            ))) {
                $collisions[$basis] = $warning;
                $cases[] = 'shared_'.$basis.'_mondo_relationship';
            }
        }

        return [
            'submission_id' => $submission->id,
            'sid' => $submission->sid,
            'version_number' => $submission->version_number,
            'job_slug' => $submission->job?->slug,
            'submitter_id' => $submission->submitter_id,
            'submitter_name' => $submission->submitter?->name,
            'submission_status' => $submission->status,
            'job_status' => $submission->job?->status,
            'namespace' => $namespace,
            'submitted_id' => $raw,
            'normalized_id' => $normalized,
            'stored_curie' => $stored?->curie,
            'current_curie' => $current?->curie,
            'cases' => array_values(array_unique($cases)),
            'evidence' => [
                'submitted_term' => $this->term($submitted),
                'replacements' => $replacements,
                'relationship_collisions' => $collisions,
                'stored_original' => $this->term($original),
                'stored_target' => $this->term($stored),
                'current_target' => $this->term($current),
                'candidates' => array_map(fn ($disease) => $this->term($disease), $candidates),
                'route' => $resolution instanceof DiseaseResolution ? $resolution->via : $ambiguity?->step,
                'accepted' => $accepted,
                'validation_error' => $validation['original_error'] ?? $validation['error'],
                'existing_disease_error' => $existingError,
                'original_upload_id' => data_get($submission->original_submission_data, 'disease.id'),
                'is_live' => $submission->is_live,
                'is_most_recent' => $submission->is_most_recent,
                'submission_updated_at' => $submission->updated_at?->toIso8601String(),
            ],
        ];
    }

    private function deprecated(?Disease $disease): bool
    {
        return $disease && (int) $disease->status === Disease::STATUS_DEPRECATED;
    }

    /** Two passes let a finding include peers that occur later in the scan. */
    private function collisionGroups(DiseaseResolver $resolver): array
    {
        $groups = ['stored' => [], 'current' => []];
        $currentByIdentifier = [];
        Submission::query()->where(fn ($q) => $q->where('is_live', true)->orWhere('is_most_recent', true))
            ->with(['disease', 'job:id,slug'])
            ->chunkById(500, function ($submissions) use (&$groups, &$currentByIdentifier, $resolver) {
                foreach ($submissions as $submission) {
                    $stored = MondoRelationshipWarnings::candidate($submission);
                    if ($key = MondoRelationshipWarnings::key($stored)) {
                        $groups['stored'][$key][] = $stored;
                    }
                    $raw = data_get($submission->submission_data, 'disease.id');
                    if (! is_string($raw)) {
                        continue;
                    }
                    if (! array_key_exists($raw, $currentByIdentifier)) {
                        $v = SubmissionValueValidation::disease($raw, $resolver);
                        $currentByIdentifier[$raw] = ! $v['error'] && ! $v['original_error'] ? $v['mondo'] : null;
                    }
                    if ($target = $currentByIdentifier[$raw]) {
                        $current = MondoRelationshipWarnings::candidate($submission, $target);
                        if ($key = MondoRelationshipWarnings::key($current)) {
                            $groups['current'][$key][] = $current;
                        }
                    }
                }
            });

        return $groups;
    }

    private function term(?Disease $disease): ?array
    {
        return $disease ? [
            'curie' => $disease->curie,
            'name' => $disease->name,
            'deprecated' => $this->deprecated($disease),
            'deleted' => $disease->trashed(),
        ] : null;
    }
}
