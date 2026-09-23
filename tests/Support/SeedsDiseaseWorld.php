<?php

namespace Tests\Support;

use App\Models\Disease;

/**
 * One fixture world covering every step of the disease resolution policy and
 * every way an identifier can fail it, shared by the tests that assert what
 * resolution returns and the test that asserts a resolver's memoized answers
 * match fresh ones.
 *
 * Storage here mirrors what UpdateDiseases writes: a row's xrefs record only
 * what that row's own ontology asserts, exactly, in arrays of bare identifiers —
 * except an Orphanet row's `mondo_id`, which holds CURIEs, since a MONDO
 * identifier is zero-padded.  A MONDO row lists the OMIM (`omim_id`) and
 * Orphanet (`orpha_id`) terms it skos:exactMatch-es and always carries
 * `replaced_by`; an Orphanet row lists the MONDO (`mondo_id`) and OMIM
 * (`omim_id`) terms Orphadata marks exact and validated; an OMIM row asserts
 * nothing.
 */
trait SeedsDiseaseWorld
{
    /**
     * Real exact-match assertions from the audited upstream snapshots.
     * Omitting MONDO's own Orphanet assertion models the regression trigger.
     *
     * @return array<string, Disease>
     */
    public static function seedJuvenileAbsenceMappings(bool $withMondoAssertion = true): array
    {
        return [
            'obsolete' => Disease::factory()->mondo()->deprecated()->create([
                'curie' => 'MONDO:0011876',
                'name' => 'juvenile absence epilepsy',
                'deprecated_name' => 'obsolete juvenile absence epilepsy',
            ]),
            'current' => Disease::factory()->mondo()->withXrefs([
                'orpha_id' => $withMondoAssertion ? ['1941'] : [],
            ])->create(['curie' => 'MONDO:0800453', 'name' => 'juvenile absence epilepsy']),
            'bridge' => Disease::factory()->mondo()->withXrefs([
                'omim_id' => ['607631'],
            ])->create(['curie' => 'MONDO:0020772']),
            'orphanet' => Disease::factory()->orphanet()->withXrefs([
                'mondo_id' => ['MONDO:0800453', 'MONDO:0011876'],
                'omim_id' => ['607631'],
            ])->create(['curie' => 'Orphanet:1941', 'name' => 'Juvenile absence epilepsy']),
        ];
    }

    /**
     * @return array<string, Disease> keyed by a short handle
     */
    public static function seedDiseaseWorld(): array
    {
        $w = [];

        // A MONDO term with no cross-references at all
        $w['mondo_plain'] = Disease::factory()->mondo()->create(['curie' => 'MONDO:0000001']);

        // A soft-deleted MONDO row claiming the same identifiers as mondo_exact.
        // Created first so it takes the lower id and, without the deleted_at
        // filter, would win the index build or make it ambiguous.
        $w['mondo_trashed'] = Disease::factory()->mondo()->withXrefs([
            'omim_id' => ['600001'],
            'orpha_id' => ['700001'],
            'replaced_by' => null,
        ])->create(['curie' => 'MONDO:0000006']);
        $w['mondo_trashed']->delete();

        // Step 1 target: the terms MONDO itself exact-matches
        $w['mondo_exact'] = Disease::factory()->mondo()->withXrefs([
            'omim_id' => ['600001', '600004'],
            'orpha_id' => ['700001', '700002', '700500'],
            'replaced_by' => null,
        ])->create(['curie' => 'MONDO:0000002']);

        // Step 2 target: reachable only through an Orphanet row's own assertion
        $w['mondo_orphanet_asserted'] = Disease::factory()->mondo()->create(['curie' => 'MONDO:0000003']);

        // Step 3 target: reachable only by chaining an Orphanet row's OMIM
        // reference through MONDO's exact match to that OMIM id
        $w['mondo_bridge'] = Disease::factory()->mondo()->withXrefs([
            'omim_id' => ['600700'],
            'orpha_id' => [],
            'replaced_by' => null,
        ])->create(['curie' => 'MONDO:0000007']);

        // An obsolete MONDO term is still a valid target, and names its successor
        $w['mondo_deprecated'] = Disease::factory()->mondo()->deprecated()->withXrefs([
            'omim_id' => ['600044'],
            'orpha_id' => ['700044'],
            'replaced_by' => 'MONDO:0000002',
        ])->create(['curie' => 'MONDO:0000004']);

        $w['mondo_removed'] = Disease::factory()->mondo()->removed()->create(['curie' => 'MONDO:0000005']);

        // Two live terms claiming the same Orphanet code: no choice between them
        // is defensible, so resolution fails closed
        $w['mondo_rival_a'] = Disease::factory()->mondo()->withXrefs([
            'omim_id' => [],
            'orpha_id' => ['700888'],
            'replaced_by' => null,
        ])->create(['curie' => 'MONDO:0000008']);
        $w['mondo_rival_b'] = Disease::factory()->mondo()->withXrefs([
            'omim_id' => [],
            'orpha_id' => ['700888'],
            'replaced_by' => null,
        ])->create(['curie' => 'MONDO:0000009']);

        // OMIM asserts nothing, so an OMIM row exists only to be named
        $w['omim_exact'] = Disease::factory()->omim()->create(['curie' => 'OMIM:600001']);

        // OMIM:600004 deliberately has no record of its own: reachable only
        // through mondo_exact's omim_id array

        // An OMIM id no MONDO term exact-matches: reciprocity fails, so it does
        // not resolve however many other rows mention it
        $w['omim_unmapped'] = Disease::factory()->omim()->create(['curie' => 'OMIM:600100']);

        $w['omim_removed'] = Disease::factory()->omim()->removed()->create(['curie' => 'OMIM:600900']);

        $w['omim_bridge'] = Disease::factory()->omim()->create(['curie' => 'OMIM:600700']);

        // Step 1: MONDO exact-matches this Orphanet code
        $w['orpha_exact'] = Disease::factory()->orphanet()->create(['curie' => 'Orphanet:700001']);

        // Orphanet:700044 has no record of its own, and mondo_deprecated
        // exact-matches it

        // Step 1 wins over step 2: MONDO exact-matches this code, and the row
        // also asserts a different MONDO equivalent of its own
        $w['orpha_both'] = Disease::factory()->orphanet()->withXrefs([
            'mondo_id' => ['MONDO:0000003'],
            'omim_id' => [],
        ])->create(['curie' => 'Orphanet:700002']);

        // A deprecated Orphanet term MONDO still exact-matches
        $w['orpha_deprecated'] = Disease::factory()->orphanet()->deprecated()
            ->create(['curie' => 'Orphanet:700500']);

        // Step 2: Orphadata's own exact, validated MONDO equivalent
        $w['orpha_asserts_mondo'] = Disease::factory()->orphanet()->withXrefs([
            'mondo_id' => ['MONDO:0000003'],
            'omim_id' => [],
        ])->create(['curie' => 'Orphanet:700300']);

        // Step 3: an exact OMIM reference MONDO exact-matches
        $w['orpha_bridge'] = Disease::factory()->orphanet()->withXrefs([
            'mondo_id' => [],
            'omim_id' => ['600700'],
        ])->create(['curie' => 'Orphanet:700700']);

        // Step 4: nothing maps it, so the submission is rejected.  This is the
        // case that used to resolve to itself.
        $w['orpha_unmapped'] = Disease::factory()->orphanet()->create(['curie' => 'Orphanet:700200']);

        // Asserts a MONDO term that is not in the table
        $w['orpha_stale'] = Disease::factory()->orphanet()->withXrefs([
            'mondo_id' => ['MONDO:0009999'],
            'omim_id' => [],
        ])->create(['curie' => 'Orphanet:700600']);

        // References an OMIM id no MONDO term exact-matches
        $w['orpha_dead_bridge'] = Disease::factory()->orphanet()->withXrefs([
            'mondo_id' => [],
            'omim_id' => ['600100'],
        ])->create(['curie' => 'Orphanet:700800']);

        $w['orpha_removed'] = Disease::factory()->orphanet()->removed()->create(['curie' => 'Orphanet:700400']);

        // Ambiguity at each step
        $w['orpha_rival_targets'] = Disease::factory()->orphanet()->create(['curie' => 'Orphanet:700888']);

        $w['orpha_two_mondo'] = Disease::factory()->orphanet()->withXrefs([
            'mondo_id' => ['MONDO:0000001', 'MONDO:0000003'],
            'omim_id' => [],
        ])->create(['curie' => 'Orphanet:700999']);

        $w['orpha_two_bridges'] = Disease::factory()->orphanet()->withXrefs([
            'mondo_id' => [],
            'omim_id' => ['600001', '600700'],
        ])->create(['curie' => 'Orphanet:700777']);

        return $w;
    }

    /**
     * Every identifier DiseaseResolverTest resolves, covering each fixture
     * above in each accepted spelling.
     *
     * @return string[]
     */
    public static function diseaseWorldInputs(): array
    {
        $inputs = [];

        foreach (['MONDO:0000001', 'MONDO:0000004', 'MONDO:0000005', 'MONDO:0000006', 'MONDO:0009999'] as $curie) {
            $inputs[] = $curie;
            $inputs[] = strtolower($curie);
        }

        foreach (['600001', '600004', '600044', '600100', '600700', '600900', '609999'] as $number) {
            $inputs[] = 'OMIM:'.$number;
            $inputs[] = 'omim:'.$number;
        }

        foreach (['700001', '700002', '700044', '700200', '700300', '700400', '700500', '700600',
            '700700', '700777', '700800', '700888', '700999', '709999'] as $number) {
            foreach (['Orphanet', 'ORPHANET', 'orphanet', 'ORPHA', 'orpha', 'oRpHa'] as $prefix) {
                $inputs[] = $prefix.':'.$number;
            }
        }

        // Namespaces this policy no longer accepts, and malformed input
        return array_merge($inputs, [
            'DOID:800001',
            'GARD:810001',
            'MEDGEN:820001',
            'UMLS:C830001',
            '',
            '600001',
            'MONDO',
            'garbage',
            'FOO:123',
            'MONDO:',
            'OMIM:600001:extra',
            'http://purl.obolibrary.org/obo/MONDO_0000001',
        ]);
    }
}
