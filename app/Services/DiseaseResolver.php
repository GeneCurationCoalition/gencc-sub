<?php

namespace App\Services;

use App\Models\Disease;
use Illuminate\Support\Facades\DB;

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
 * Strategy order, for an OMIM or Orphanet identifier:
 *   1. the record for the submitted CURIE carries a mondo_id FK (strongest:
 *      MONDO asserts the equivalence directly)
 *   2. some MONDO record lists the submitted identifier in its xrefs
 *   3. Orphanet only — the term stands on its own, because MONDO has not
 *      ingested it yet.  Reported as a warning at upload, not an error.
 *
 * Lookups are memoized per instance, so an upload that builds one resolver
 * and resolves a disease per row pays for each distinct identifier once.
 * Instances are cheap and must not be held between uploads: the nightly
 * update:diseases run can change the table under a long-lived worker.
 */
class DiseaseResolver
{
    /**
     * The xrefs field each supported ontology prefix is recorded under on a
     * MONDO record.  Prefixes are as Disease::normalizeCurie() spells them.
     *
     * @var array<string, string>
     */
    private const XREF_FIELD = [
        'OMIM' => 'omim_id',
        'OMIMPS' => 'omim_id',
        'Orphanet' => 'orpha_id',
        'DOID' => 'do_id',
        'GARD' => 'gard_id',
        'MEDGEN' => 'medgen_id',
        'UMLS' => 'umls_id',
    ];

    /** @var array<string, ?Disease> canonical CURIE => record, memoized */
    private array $byCurie = [];

    /** @var array<int, ?Disease> primary key => record, memoized */
    private array $byId = [];

    /**
     * xrefs field => xref value => id of the MONDO record that lists it.
     * Built on first use from one query, because the xrefs column is JSON and
     * cannot be indexed, so per-lookup queries against it are the one slow
     * step in resolution.
     *
     * @var array<string, array<string, int>>|null
     */
    private ?array $xrefIndex = null;

    /**
     * Resolve a submitted disease identifier.
     *
     * @param  string|null  $submitted  The identifier as submitted, in CURIE form
     * @param  bool  $forSubmission  Apply the stricter OMIM rule described below
     * @return DiseaseResolution|null null when the identifier does not resolve
     */
    public function resolve(?string $submitted, bool $forSubmission = false): ?DiseaseResolution
    {
        $curie = Disease::normalizeCurie($submitted);

        if ($curie === null) {
            return null;
        }

        [$prefix, $number] = explode(':', $curie, 2);

        $resolution = match ($prefix) {
            'MONDO' => $this->mondo($curie),
            'OMIM', 'OMIMPS' => $this->equivalence($curie, self::XREF_FIELD[$prefix], $number, false),
            'Orphanet' => $this->equivalence($curie, self::XREF_FIELD[$prefix], $number, true),
            'DOID', 'GARD', 'MEDGEN', 'UMLS' => $this->xrefOnly(self::XREF_FIELD[$prefix], $number),
            default => null,
        };

        if ($resolution === null) {
            return null;
        }

        // A submission additionally requires that MONDO itself list the OMIM id,
        // not just that an OMIM record point at a MONDO term.  Only strategy 1
        // can produce the latter, and where it does the mapping was inferred
        // transitively (see assignMondoIdToOmimViaOrphanet) rather than asserted
        // by MONDO, and those inferences are unreliable enough to reject.
        if ($forSubmission && in_array($prefix, ['OMIM', 'OMIMPS'], true)
            && ! $this->assertsXref($resolution->mondo, 'omim_id', $number)) {
            return null;
        }

        return $resolution;
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
     * Resolve an ontology that has its own records in the diseases table and an
     * equivalence to MONDO — OMIM and Orphanet.
     *
     * @param  string  $curie  The canonical CURIE
     * @param  string  $xrefField  Where a MONDO record records this ontology
     * @param  string  $number  The bare identifier, without its prefix
     * @param  bool  $selfFallback  Whether an unmapped term may stand on its own
     */
    private function equivalence(string $curie, string $xrefField, string $number, bool $selfFallback): ?DiseaseResolution
    {
        $original = $this->byCurie($curie);

        // Strategy 1: the equivalence MONDO asserted, recorded as an FK by
        // UpdateDiseases
        if ($original !== null && $original->mondo_id) {
            $mondo = $this->byId($original->mondo_id);

            if ($mondo !== null) {
                return new DiseaseResolution($original, $mondo, DiseaseResolution::VIA_EQUIVALENCE_FK);
            }
        }

        // Strategy 2: a MONDO record naming this identifier in its xrefs
        $mondo = $this->mondoByXref($xrefField, $number);

        if ($mondo !== null) {
            return new DiseaseResolution($original, $mondo, DiseaseResolution::VIA_EQUIVALENCE_XREF);
        }

        // Strategy 3: an active Orphanet term MONDO has no equivalent for is
        // still usable, as itself
        if ($selfFallback && $original !== null && $original->status === Disease::STATUS_ACTIVE) {
            return new DiseaseResolution($original, $original, DiseaseResolution::VIA_ORPHANET_SELF);
        }

        return null;
    }

    /**
     * Resolve an ontology that has no records of its own in the diseases table
     * and is only reachable through a MONDO record's xrefs — DOID, GARD,
     * MEDGEN, UMLS.  There is no original record to report for these.
     */
    private function xrefOnly(string $xrefField, string $number): ?DiseaseResolution
    {
        $mondo = $this->mondoByXref($xrefField, $number);

        return $mondo === null
            ? null
            : new DiseaseResolution(null, $mondo, DiseaseResolution::VIA_EQUIVALENCE_XREF);
    }

    /**
     * Whether $disease's xrefs record $number under $field.
     */
    private function assertsXref(?Disease $disease, string $field, string $number): bool
    {
        return $disease !== null && in_array($number, self::xrefValues($disease->xrefs, $field), true);
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
     * The MONDO record that lists $value in its xrefs under $field.
     *
     * @param  string  $field  An xrefs key, e.g. 'omim_id' or 'orpha_id'
     * @param  string  $value  The bare identifier, without its prefix
     */
    private function mondoByXref(string $field, string $value): ?Disease
    {
        $this->xrefIndex ??= $this->buildXrefIndex();

        $id = $this->xrefIndex[$field][$value] ?? null;

        return $id === null ? null : $this->byId($id);
    }

    /**
     * Index every xref on every eligible MONDO record, once.
     *
     * Rows are read as plain arrays rather than models — this is tens of
     * thousands of rows and only two columns matter.  The put-if-absent build
     * over the eligible() ordering means the ACTIVE record wins over a
     * DEPRECATED one listing the same xref, and the lowest id breaks any tie.
     *
     * @return array<string, array<string, int>>
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

            foreach (self::XREF_FIELD as $field) {
                foreach (self::xrefValues($xrefs, $field) as $value) {
                    $index[$field][$value] ??= (int) $row->id;
                }
            }
        }

        return $index;
    }

    /**
     * The values of one xrefs field, whether stored as a scalar (`orpha_id`) or
     * as an array (`omim_id`).
     *
     * @param  mixed  $xrefs  A decoded xrefs column; an array (not object) for
     *                        the `[]` UpdateDiseases writes when a term has none
     * @return string[]
     */
    private static function xrefValues(mixed $xrefs, string $field): array
    {
        if (! is_object($xrefs) || ! isset($xrefs->{$field})) {
            return [];
        }

        return array_map('strval', (array) $xrefs->{$field});
    }

    /**
     * Restrict any diseases query to the records resolution may return, ordered
     * so that ACTIVE wins over DEPRECATED and the lowest id breaks a tie.
     * REMOVED and soft-deleted diseases are never eligible.  The deleted_at
     * filter is stated here, not left to the SoftDeletes scope, because the
     * xref index reads the table through the query builder, which has no
     * scopes — the two lookup paths must agree by construction.
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
