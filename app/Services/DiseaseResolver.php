<?php

namespace App\Services;

use App\Console\Commands\UpdateDiseases;
use App\Models\Disease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a submitted disease identifier to the pair of disease records a
 * submission stores — the Rosetta stone for disease identifiers, succeeding
 * the Disease::rosetta() family of lookups.
 *
 * This is the *only* place disease resolution lives.  Every path that accepts
 * a disease identifier — per-column file validation, duplicate detection, row
 * processing, the manual change form, the lookup endpoints — goes through
 * here, so the answer cannot depend on which path asked.
 *
 * Three policy rules govern it:
 *
 *   1. Exact only.  Only an exact upstream mapping may relate terms across
 *      ontologies.  UpdateDiseases stores nothing else, so presence in `xrefs`
 *      already means exactness and nothing here re-checks it.
 *   2. OMIM reciprocity.  An OMIM id maps to a MONDO term only when that MONDO
 *      term maps back.  This is automatic: OMIM's source file asserts nothing,
 *      so the only OMIM↔MONDO data is MONDO's own exactMatch list.
 *   3. MONDO preferred, Orphanet fallback, else reject.
 *
 * For an Orphanet code, first match wins:
 *
 *   1. a MONDO term skos:exactMatch-es it;
 *   2. Orphadata asserts an exact, validated MONDO equivalent;
 *   3. Orphadata asserts an exact OMIM reference and a MONDO term
 *      exactMatch-es that OMIM id;
 *   4. otherwise the identifier does not resolve and the submission record is
 *      rejected with a per-record error.
 *
 * For an OMIM id, step 1 only.  A transitive bridge through Orphanet — an
 * Orphanet term that references the OMIM id and has a MONDO equivalent — is a
 * possible future step; it would map 9 further identifiers in current data, and
 * is deliberately not taken, because the OMIM side asserts nothing to reciprocate.
 *
 * Lookups are memoized per instance, so an upload that builds one resolver
 * and resolves a disease per row pays for each distinct identifier once.
 * Instances are cheap and must not be held between uploads: the nightly
 * update:diseases run can change the table under a long-lived worker.
 */
class DiseaseResolver
{
    /**
     * The MONDO xrefs field that records an exact match to each submitted
     * ontology.  Prefixes are as Disease::normalizeCurie() spells them; field
     * names must agree with UpdateDiseases::FIELD_*.
     *
     * OMIMPS shares OMIM's field: MONDO records phenotypic series under a URL
     * form the importer does not store, so nothing ever lands there for it.
     *
     * @var array<string, string>
     */
    private const EXACT_FIELD = [
        'OMIM' => UpdateDiseases::FIELD_EXACT_OMIM,
        'OMIMPS' => UpdateDiseases::FIELD_EXACT_OMIM,
        'Orphanet' => UpdateDiseases::FIELD_EXACT_ORPHANET,
    ];

    /** @var array<string, ?Disease> canonical CURIE => record, memoized */
    private array $byCurie = [];

    /** @var array<int, ?Disease> primary key => record, memoized */
    private array $byId = [];

    /**
     * xrefs field => xref value => ids of the MONDO records that list it.
     * Built on first use from one query, because the xrefs column is JSON and
     * cannot be indexed, so per-lookup queries against it are the one slow
     * step in resolution.
     *
     * @var array<string, array<string, int[]>>|null
     */
    private ?array $xrefIndex = null;

    /** @var array<string, ?Disease> "field:value" => exact-matching MONDO record, memoized */
    private array $exactMatch = [];

    /**
     * Resolve a submitted disease identifier.
     *
     * There is one policy: exactness and reciprocity are properties of what is
     * stored, so every caller gets the same answer.
     *
     * @param  string|null  $submitted  The identifier as submitted, in CURIE form
     * @return DiseaseResolution|null null when the identifier does not resolve
     */
    public function resolve(?string $submitted): ?DiseaseResolution
    {
        $curie = Disease::normalizeCurie($submitted);

        if ($curie === null) {
            return null;
        }

        [$prefix, $number] = explode(':', $curie, 2);

        return match ($prefix) {
            'MONDO' => $this->mondo($curie),
            'OMIM', 'OMIMPS' => $this->omim($curie, $number),
            'Orphanet' => $this->orphanet($curie, $number),
            default => null,
        };
    }

    /**
     * A MONDO identifier resolves to itself, so both disease references on the
     * submission point at the same record.
     */
    private function mondo(string $curie): ?DiseaseResolution
    {
        $mondo = $this->byCurie($curie);

        return $mondo === null
            ? null
            : new DiseaseResolution($mondo, $mondo, DiseaseResolution::VIA_MONDO_SELF);
    }

    /**
     * An OMIM identifier resolves only through MONDO's own exact match to it.
     *
     * OMIM asserts nothing about MONDO, so this single step is what makes the
     * mapping reciprocal by construction.
     */
    private function omim(string $curie, string $number): ?DiseaseResolution
    {
        $mondo = $this->mondoByExactMatch(UpdateDiseases::FIELD_EXACT_OMIM, $number);

        return $mondo === null
            ? null
            : new DiseaseResolution($this->byCurie($curie), $mondo, DiseaseResolution::VIA_MONDO_EXACT_MATCH);
    }

    /**
     * An Orphanet identifier resolves through the first of the three steps that
     * yields a MONDO term.
     */
    private function orphanet(string $curie, string $number): ?DiseaseResolution
    {
        $original = $this->byCurie($curie);

        // Step 1: a MONDO term exact-matches this Orphanet code
        $mondo = $this->mondoByExactMatch(UpdateDiseases::FIELD_EXACT_ORPHANET, $number);

        if ($mondo !== null) {
            return new DiseaseResolution($original, $mondo, DiseaseResolution::VIA_MONDO_EXACT_MATCH);
        }

        if ($original === null) {
            return null;
        }

        // Step 2: Orphadata asserts an exact, validated MONDO equivalent
        $mondo = $this->only(
            array_map(
                fn ($c) => $this->mondoByCurie($c),
                self::xrefValues($original->xrefs, UpdateDiseases::FIELD_EXACT_MONDO)
            ),
            "Orphanet {$curie} asserts more than one MONDO equivalent"
        );

        if ($mondo !== null) {
            return new DiseaseResolution($original, $mondo, DiseaseResolution::VIA_ORPHANET_EXACT_MATCH);
        }

        // Step 3: Orphadata asserts an exact OMIM reference MONDO exact-matches
        $mondo = $this->only(
            array_map(
                fn ($n) => $this->mondoByExactMatch(UpdateDiseases::FIELD_EXACT_OMIM, $n),
                self::xrefValues($original->xrefs, UpdateDiseases::FIELD_EXACT_OMIM)
            ),
            "Orphanet {$curie} reaches more than one MONDO term through its OMIM references"
        );

        if ($mondo !== null) {
            return new DiseaseResolution($original, $mondo, DiseaseResolution::VIA_OMIM_BRIDGE);
        }

        return null;
    }

    /**
     * The one distinct record among $candidates, or null when there is none.
     *
     * A step that reaches two different MONDO terms fails closed: the mapping is
     * ambiguous and no choice between them is defensible.  Current upstream data
     * produces no such case, but nothing guarantees that across releases, so the
     * ambiguity is logged when it happens.
     *
     * @param  array<?Disease>  $candidates
     */
    private function only(array $candidates, string $ambiguity): ?Disease
    {
        $found = [];

        foreach ($candidates as $candidate) {
            if ($candidate !== null) {
                $found[$candidate->id] = $candidate;
            }
        }

        if (count($found) > 1) {
            Log::warning('DiseaseResolver: '.$ambiguity, ['mondo' => array_column($found, 'curie')]);

            return null;
        }

        return reset($found) ?: null;
    }

    /**
     * The disease record with this exact canonical CURIE.
     */
    private function byCurie(string $curie): ?Disease
    {
        if (! array_key_exists($curie, $this->byCurie)) {
            $this->byCurie[$curie] = self::eligible(Disease::query())->where('curie', $curie)->first();
        }

        return $this->byCurie[$curie];
    }

    /**
     * The MONDO record with this CURIE.
     *
     * `submissions.disease_id` must be a MONDO term, so the type is checked
     * rather than assumed of a CURIE that came out of a JSON column.
     */
    private function mondoByCurie(string $curie): ?Disease
    {
        $disease = $this->byCurie($curie);

        return $disease?->type === Disease::TYPE_MONDO ? $disease : null;
    }

    /**
     * The disease record with this primary key.
     */
    private function byId(int $id): ?Disease
    {
        if (! array_key_exists($id, $this->byId)) {
            $this->byId[$id] = self::eligible(Disease::query())->where('id', $id)->first();
        }

        return $this->byId[$id];
    }

    /**
     * The MONDO record that exact-matches $value under $field, or null when
     * none does or more than one does.
     *
     * @param  string  $field  An equivalence key, e.g. UpdateDiseases::FIELD_EXACT_OMIM
     * @param  string  $value  The bare identifier, as the field records it
     */
    private function mondoByExactMatch(string $field, string $value): ?Disease
    {
        $key = $field.'/'.$value;

        if (! array_key_exists($key, $this->exactMatch)) {
            $this->xrefIndex ??= $this->buildXrefIndex();

            $this->exactMatch[$key] = $this->only(
                array_map(fn ($id) => $this->byId($id), $this->xrefIndex[$field][$value] ?? []),
                "more than one MONDO term records {$field} {$value}"
            );
        }

        return $this->exactMatch[$key];
    }

    /**
     * Index every exact match on every eligible MONDO record, once.
     *
     * Rows are read as plain arrays rather than models — this is tens of
     * thousands of rows and only two columns matter.  Every id that lists a
     * value is kept, so an ambiguous mapping can be detected rather than
     * silently decided by row order.
     *
     * @return array<string, array<string, int[]>>
     */
    private function buildXrefIndex(): array
    {
        $index = [];

        $rows = self::eligible(DB::table('diseases'))
            ->select('id', 'xrefs')
            ->where('type', Disease::TYPE_MONDO)
            ->whereNotNull('xrefs')
            ->get();

        foreach ($rows as $row) {
            $xrefs = json_decode($row->xrefs);

            foreach (array_unique(self::EXACT_FIELD) as $field) {
                foreach (self::xrefValues($xrefs, $field) as $value) {
                    $index[$field][$value][] = (int) $row->id;
                }
            }
        }

        return $index;
    }

    /**
     * The identifiers recorded under one equivalence field.
     *
     * Every field this reads is written as an array — bare identifiers, except
     * an Orphanet row's `mondo_id`, which holds CURIEs.  The scalar case is
     * tolerated only so that malformed or hand-seeded data cannot raise.
     *
     * @param  mixed  $xrefs  A decoded xrefs column, as an object or model cast
     * @return string[]
     */
    private static function xrefValues(mixed $xrefs, string $field): array
    {
        if (! is_object($xrefs) || ! isset($xrefs->{$field})) {
            return [];
        }

        return array_values(array_filter(
            array_map('strval', (array) $xrefs->{$field}),
            fn ($value) => $value !== ''
        ));
    }

    /**
     * Restrict any diseases query to the records resolution may return, ordered
     * so that ACTIVE wins over DEPRECATED and the lowest id breaks a tie.
     * REMOVED and soft-deleted diseases are never eligible.  The deleted_at
     * filter is stated here, not left to the SoftDeletes scope, because the
     * xref index reads the table through the query builder, which has no
     * scopes — the two lookup paths must agree by construction.
     *
     * Deprecated MONDO terms stay eligible: 1,130 active Orphanet disorders map
     * to one, and the portal surfaces a non-blocking warning instead.
     *
     * @template T of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  T  $query
     * @return T
     */
    private static function eligible($query)
    {
        // STATUS_ACTIVE (1) sorts ahead of STATUS_DEPRECATED (8)
        return $query
            ->whereIn('status', [Disease::STATUS_ACTIVE, Disease::STATUS_DEPRECATED])
            ->whereNull('deleted_at')
            ->orderBy('status')
            ->orderBy('id');
    }
}
