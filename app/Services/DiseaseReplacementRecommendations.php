<?php

namespace App\Services;

use App\Models\Disease;

/** Request/scan-scoped advice; never changes disease resolution or stored submissions. */
class DiseaseReplacementRecommendations
{
    private array $terms = [];

    private array $advice = [];

    public function __construct(private DiseaseResolver $resolver = new DiseaseResolver())
    {
    }

    public static function identifiers(mixed $xrefs): array
    {
        $values = data_get($xrefs, 'replaced_by', []);

        return array_values(array_unique(array_filter(array_map(
            fn ($value) => is_string($value) && preg_match('/^[A-Za-z][A-Za-z0-9]*:[0-9]+$/D', $value)
                ? Disease::normalizeCurie($value) : null,
            is_array($values) ? $values : [$values]
        ))));
    }

    /** Load the page/chunk's source terms and their immediate successors in two queries. */
    public function preload(iterable $curies): void
    {
        $curies = is_array($curies) ? $curies : iterator_to_array($curies);
        $this->load($curies);
        $targets = [];
        foreach ($curies as $curie) {
            $term = $this->term($curie);
            if ($term) {
                array_push($targets, ...self::identifiers($term->xrefs));
            }
        }
        $this->load($targets);
        $this->resolver->preload(array_merge($curies, $targets));
    }

    private function load(iterable $curies): void
    {
        $missing = [];
        foreach ($curies as $curie) {
            if (is_string($curie) && ($normalized = Disease::normalizeCurie($curie)) && ! array_key_exists($normalized, $this->terms)) {
                $missing[$normalized] = null;
            }
        }
        if (! $missing) {
            return;
        }
        $this->terms += $missing;
        foreach (Disease::withTrashed()->whereIn('curie', array_keys($missing))->get() as $term) {
            $this->terms[$term->curie] = $term;
        }
    }

    public function term(?string $curie): ?Disease
    {
        return $this->terms[Disease::normalizeCurie($curie) ?? ''] ?? null;
    }

    /** The caller preloads its contexts together; equal terms share one recommendation. */
    public function forContexts(array $contexts): array
    {
        $result = [];
        foreach ($contexts as $context => $curie) {
            if ($recommendation = $this->forTerm($this->term($curie))) {
                $key = $recommendation['curie'];
                $result[$key] ??= $recommendation + ['contexts' => []];
                $result[$key]['contexts'][] = $context;
            }
        }

        return array_values($result);
    }

    public function forTerm(?Disease $term): ?array
    {
        if (! $term || (int) $term->status !== Disease::STATUS_DEPRECATED) {
            return null;
        }
        if (isset($this->advice[$term->curie])) {
            return $this->advice[$term->curie];
        }
        $ids = self::identifiers($term->xrefs);
        $recorded = data_get($term->xrefs, 'replaced_by');
        $unparsed = data_get($term->xrefs, 'replacement_parse_status') === 'unrecognized'
            || (bool) array_filter((array) $recorded, fn ($value) => ! is_string($value)
                || ! preg_match('/^[A-Za-z][A-Za-z0-9]*:[0-9]+$/D', $value));
        $source = explode(':', $term->curie)[0];
        $targets = [];
        foreach ($ids as $curie) {
            $target = $this->term($curie);
            $details = (array) data_get($term->xrefs, 'replacement_details.'.$curie, []);
            $prefix = explode(':', $curie)[0];
            $kind = match (true) {
                $prefix === 'HP' => 'phenotype',
                ($details['prefix'] ?? null) === 'Asterisk' => 'gene',
                ! in_array($prefix, ['MONDO', 'OMIM', 'Orphanet'], true) => 'unsupported_namespace',
                $prefix === 'OMIM' && ! $target && ! isset($details['prefix']) => 'unknown',
                default => 'disease',
            };
            $availability = ! $target ? 'missing' : ($target->trashed() ? 'deleted'
                : ((int) $target->status === Disease::STATUS_DEPRECATED ? 'deprecated'
                : ((int) $target->status === Disease::STATUS_ACTIVE ? 'active' : 'removed')));
            $validation = $kind === 'disease' && in_array($availability, ['active', 'deprecated'], true)
                ? SubmissionValueValidation::disease($curie, $this->resolver) : null;
            $error = $validation ? ($validation['original_error'] ?? $validation['error']) : null;
            $usable = ! $unparsed && count($ids) === 1 && $curie !== $term->curie && $availability === 'active'
                && $validation && ! $error && (int) $validation['mondo']->status === Disease::STATUS_ACTIVE;
            $targets[] = [
                'curie' => $curie, 'name' => $target?->name ?? ($details['title'] ?? null),
                'kind' => $kind, 'availability' => $availability, 'source_details' => $details,
                'mondo' => $validation['mondo']->curie ?? null,
                'mondo_deprecated' => isset($validation['mondo']) && (int) $validation['mondo']->status === Disease::STATUS_DEPRECATED,
                'validation_error' => $error, 'usable' => (bool) $usable,
            ];
        }
        $message = "{$term->curie} ({$term->name}) is marked deprecated in our current ontology data. Deprecation alone does not block submission.";
        if ($ids) {
            $message .= ' '.$source.($source === 'OMIM' ? ' marks this entry as moved to ' : ' names as replacement: ').implode('; ', $ids).'.';
        } else {
            $message .= $unparsed ? ' Replacement information could not be interpreted; review the source entry.'
                : ' No replacement is recorded in our current ontology data.';
        }

        return $this->advice[$term->curie] = [
            'type' => 'deprecated_disease', 'curie' => $term->curie, 'name' => $term->name,
            'source' => $source, 'replaced_by' => count($ids) === 1 ? $ids[0] : ($ids ?: null),
            'targets' => $targets, 'unparsed' => $unparsed, 'message' => $message,
            'replacement_available' => collect($targets)->contains('usable', true),
        ];
    }
}
