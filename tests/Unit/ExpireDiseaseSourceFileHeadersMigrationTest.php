<?php

namespace Tests\Unit;

use App\Models\Disease;
use App\Models\StaticFileHeader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The migration that makes the next disease import re-read MONDO and Orphanet.
 */
class ExpireDiseaseSourceFileHeadersMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_clears_legacy_mappings_and_forgets_only_the_mondo_and_orphanet_headers(): void
    {
        $mondo = Disease::create([
            'curie' => 'MONDO:0000001',
            'name' => 'MONDO disease',
            'type' => Disease::TYPE_MONDO,
            'xrefs' => ['omim_id' => ['123456'], 'orpha_id' => '42', 'medgen_id' => ['C1']],
        ]);
        $orphanet = Disease::create([
            'curie' => 'Orphanet:42',
            'name' => 'Orphanet disease',
            'type' => Disease::TYPE_ORPHANET,
            'xrefs' => ['mondo_id' => 'MONDO:0000001', 'omim_id' => '123456', 'umls_id' => 'C1'],
        ]);
        $omim = Disease::create([
            'curie' => 'OMIM:123456',
            'name' => 'OMIM disease',
            'type' => Disease::TYPE_OMIM,
            'xrefs' => ['include_titles' => 'Example'],
        ]);

        foreach (['mondo_with_equivalents', 'mondo_with_equivalents', 'orphanet_product1', 'omim_mimTitles', 'hgnc_complete_set'] as $identifier) {
            StaticFileHeader::create(['file_identifier' => $identifier, 'etag' => 'x']);
        }

        (require database_path('migrations/2026_09_15_120000_expire_disease_source_file_headers.php'))->up();

        $this->assertNull(StaticFileHeader::latest('mondo_with_equivalents'));
        $this->assertNull(StaticFileHeader::latest('orphanet_product1'));
        $this->assertNotNull(StaticFileHeader::latest('omim_mimTitles'));
        $this->assertNotNull(StaticFileHeader::latest('hgnc_complete_set'));
        $this->assertSame([
            'omim_id' => [],
            'orpha_id' => [],
            'replaced_by' => null,
        ], (array) $mondo->fresh()->xrefs);
        $this->assertSame([
            'mondo_id' => [],
            'omim_id' => [],
        ], (array) $orphanet->fresh()->xrefs);
        $this->assertSame(['include_titles' => 'Example'], (array) $omim->fresh()->xrefs);
    }
}
