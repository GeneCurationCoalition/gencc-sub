<?php

namespace Tests\Support;

use App\Models\Disease;

/**
 * One fixture world covering every disease resolution strategy, shared by the
 * tests that assert what resolution returns and the test that asserts a
 * resolver's memoized answers match fresh ones.
 */
trait SeedsDiseaseWorld
{
    /**
     * @return array<string, Disease> keyed by a short handle
     */
    public static function seedDiseaseWorld(): array
    {
        $w = [];

        // A MONDO term with no cross-references at all
        $w['mondo_plain'] = Disease::factory()->mondo()->create(['curie' => 'MONDO:0000001']);

        // A soft-deleted MONDO row that shares xrefs with mondo_xrefs and has one of
        // its own.  Created first so it takes the lower id and, without the
        // deleted_at filter, wins the put-if-absent index build.
        $w['mondo_trashed'] = Disease::factory()->mondo()->withXrefs([
            'omim_id' => ['600001'],
            'orpha_id' => '700001',
            'do_id' => '800002',
        ])->create(['curie' => 'MONDO:0000006']);
        $w['mondo_trashed']->delete();

        // The xref target: every supported ontology points here through xrefs
        $w['mondo_xrefs'] = Disease::factory()->mondo()->withXrefs([
            'omim_id' => ['600001', '600004'],   // stored as an array
            'orpha_id' => '700001',              // stored as a scalar
            'do_id' => '800001',
            'gard_id' => '810001',
            'medgen_id' => '820001',
            'umls_id' => 'C830001',
        ])->create(['curie' => 'MONDO:0000002']);

        // The FK target: reachable only through mondo_id, never through xrefs
        $w['mondo_fk_only'] = Disease::factory()->mondo()->create(['curie' => 'MONDO:0000003']);

        $w['mondo_deprecated'] = Disease::factory()->mondo()->deprecated()->withXrefs([
            'omim_id' => ['600044'],
            'orpha_id' => '700044',
        ])->create(['curie' => 'MONDO:0000004']);

        $w['mondo_removed'] = Disease::factory()->mondo()->removed()->create(['curie' => 'MONDO:0000005']);

        // OMIM mapped by FK only — the mapping MONDO never asserted, which is
        // what the submission guard rejects
        $w['omim_fk'] = Disease::factory()->omim()->create([
            'curie' => 'OMIM:600100',
            'mondo_id' => $w['mondo_fk_only']->id,
        ]);

        // OMIM with a record but no FK, so only the xref can match
        $w['omim_xref'] = Disease::factory()->omim()->create(['curie' => 'OMIM:600001']);

        // OMIM:600004 deliberately has no record of its own: reachable only
        // through mondo_xrefs' omim_id array

        // OMIM removed, so its FK must not be followed
        $w['omim_removed'] = Disease::factory()->omim()->removed()->create([
            'curie' => 'OMIM:600900',
            'mondo_id' => $w['mondo_fk_only']->id,
        ]);

        $w['orpha_fk'] = Disease::factory()->orphanet()->create([
            'curie' => 'Orphanet:700100',
            'mondo_id' => $w['mondo_fk_only']->id,
        ]);

        $w['orpha_xref'] = Disease::factory()->orphanet()->create(['curie' => 'Orphanet:700001']);

        // Orphanet with no MONDO equivalent at all — resolves to itself
        $w['orpha_self'] = Disease::factory()->orphanet()->create(['curie' => 'Orphanet:700200']);

        // Deprecated and unmapped: standing on its own requires an ACTIVE term
        $w['orpha_deprecated'] = Disease::factory()->orphanet()->deprecated()->create(['curie' => 'Orphanet:700300']);

        $w['orpha_removed'] = Disease::factory()->orphanet()->removed()->create(['curie' => 'Orphanet:700400']);

        // An FK pointing at a deprecated MONDO term is still followed
        $w['orpha_fk_deprecated'] = Disease::factory()->orphanet()->create([
            'curie' => 'Orphanet:700404',
            'mondo_id' => $w['mondo_deprecated']->id,
        ]);

        // An FK pointing at a removed MONDO term is not, so this falls through
        // to standing on its own
        $w['orpha_fk_removed'] = Disease::factory()->orphanet()->create([
            'curie' => 'Orphanet:700500',
            'mondo_id' => $w['mondo_removed']->id,
        ]);

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

        foreach (['600001', '600004', '600044', '600100', '600900', '609999'] as $number) {
            $inputs[] = 'OMIM:'.$number;
            $inputs[] = 'omim:'.$number;
        }

        foreach (['700001', '700044', '700100', '700200', '700300', '700400', '700404', '700500', '709999'] as $number) {
            foreach (['Orphanet', 'ORPHANET', 'orphanet', 'ORPHA', 'orpha', 'oRpHa'] as $prefix) {
                $inputs[] = $prefix.':'.$number;
            }
        }

        foreach (['DOID:800001', 'GARD:810001', 'MEDGEN:820001', 'UMLS:C830001',
            'DOID:800002', 'DOID:899999', 'GARD:819999', 'MEDGEN:829999', 'UMLS:C839999'] as $curie) {
            $inputs[] = $curie;
        }

        // Malformed and out-of-scope input
        return array_merge($inputs, [
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
