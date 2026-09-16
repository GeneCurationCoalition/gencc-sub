<?php

namespace Tests\Unit;

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
    public function it_forgets_only_the_mondo_and_orphanet_headers(): void
    {
        foreach (['mondo_with_equivalents', 'mondo_with_equivalents', 'orphanet_product1', 'omim_mimTitles', 'hgnc_complete_set'] as $identifier) {
            StaticFileHeader::create(['file_identifier' => $identifier, 'etag' => 'x']);
        }

        (require database_path('migrations/2026_09_15_120000_expire_disease_source_file_headers.php'))->up();

        $this->assertNull(StaticFileHeader::latest('mondo_with_equivalents'));
        $this->assertNull(StaticFileHeader::latest('orphanet_product1'));
        $this->assertNotNull(StaticFileHeader::latest('omim_mimTitles'));
        $this->assertNotNull(StaticFileHeader::latest('hgnc_complete_set'));
    }
}
