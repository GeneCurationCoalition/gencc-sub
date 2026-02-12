<?php

namespace Tests\Unit;

use App\Console\Commands\UpdateDiseases;
use App\Models\Disease;
use App\Models\Submission;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Tests for UpdateDiseases command to prevent regressions.
 *
 * These tests validate critical logic in the disease update workflow:
 * - Label transformations (obsolete prefix removal)
 * - MONDO mapping extraction from JSON metadata
 * - MONDO ID determination for OMIM/Orphanet diseases
 * - Disease deprecation/removal reconciliation
 */
class UpdateDiseasesTest extends TestCase
{
    use RefreshDatabase;

    protected UpdateDiseases $command;
    protected ReflectionClass $reflection;
    protected BufferedOutput $buffer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new UpdateDiseases();
        $this->reflection = new ReflectionClass($this->command);

        // Set up console output for methods that use $this->info()
        $this->buffer = new BufferedOutput();
        $input = new ArrayInput([]);
        $output = new OutputStyle($input, $this->buffer);
        $this->command->setOutput($output);
    }

    /**
     * Call a protected method on the command.
     */
    protected function callMethod(string $methodName, array $args = [])
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->command, $args);
    }

    /**
     * Set a protected property on the command.
     */
    protected function setProperty(string $propertyName, $value): void
    {
        $property = $this->reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue($this->command, $value);
    }

    /**
     * Get a protected property from the command.
     */
    protected function getProperty(string $propertyName)
    {
        $property = $this->reflection->getProperty($propertyName);
        $property->setAccessible(true);
        return $property->getValue($this->command);
    }

    // =========================================================================
    // Label Transformation Tests
    // =========================================================================

    /**
     * @test
     */
    public function x_mondo_label_removes_obsolete_prefix(): void
    {
        $this->assertEquals(
            'some disease name',
            $this->callMethod('x_mondo_label', ['obsolete some disease name'])
        );
    }

    /**
     * @test
     */
    public function x_mondo_label_preserves_normal_labels(): void
    {
        $this->assertEquals(
            'normal disease name',
            $this->callMethod('x_mondo_label', ['normal disease name'])
        );
    }

    /**
     * @test
     */
    public function x_mondo_label_handles_null(): void
    {
        $this->assertEquals('', $this->callMethod('x_mondo_label', [null]));
    }

    /**
     * @test
     */
    public function x_orphanet_label_removes_obsolete_prefix(): void
    {
        $this->assertEquals(
            'Achondroplasia',
            $this->callMethod('x_orphanet_label', ['OBSOLETE: Achondroplasia'])
        );
    }

    /**
     * @test
     */
    public function x_orphanet_label_case_insensitive(): void
    {
        $this->assertEquals(
            'Some Disease',
            $this->callMethod('x_orphanet_label', ['obsolete: Some Disease'])
        );
    }

    /**
     * @test
     */
    public function x_orphanet_label_preserves_normal_labels(): void
    {
        $this->assertEquals(
            'Cystic fibrosis',
            $this->callMethod('x_orphanet_label', ['Cystic fibrosis'])
        );
    }

    // =========================================================================
    // MONDO Mapping Extraction Tests
    // =========================================================================

    /**
     * @test
     */
    public function extract_mondo_mappings_finds_omim_exact_match(): void
    {
        $meta = [
            'basicPropertyValues' => [
                [
                    'pred' => 'http://www.w3.org/2004/02/skos/core#exactMatch',
                    'val' => 'http://omim.org/entry/615438',
                ],
            ],
            'xrefs' => [],
        ];

        $this->callMethod('extractMondoMappingsArray', ['MONDO:0014651', $meta]);

        $exactMatchOmim = $this->getProperty('mondoExactMatchOmim');
        $this->assertArrayHasKey('OMIM:615438', $exactMatchOmim);
        $this->assertEquals('MONDO:0014651', $exactMatchOmim['OMIM:615438']);
    }

    /**
     * @test
     */
    public function extract_mondo_mappings_finds_orphanet_exact_match(): void
    {
        $meta = [
            'basicPropertyValues' => [
                [
                    'pred' => 'http://www.w3.org/2004/02/skos/core#exactMatch',
                    'val' => 'http://www.orpha.net/ORDO/Orphanet_464724',
                ],
            ],
            'xrefs' => [],
        ];

        $this->callMethod('extractMondoMappingsArray', ['MONDO:0030063', $meta]);

        $exactMatchOrphanet = $this->getProperty('mondoExactMatchOrphanet');
        $this->assertArrayHasKey('Orphanet:464724', $exactMatchOrphanet);
        $this->assertEquals('MONDO:0030063', $exactMatchOrphanet['Orphanet:464724']);
    }

    /**
     * @test
     */
    public function extract_mondo_mappings_builds_xref_lists(): void
    {
        $meta = [
            'basicPropertyValues' => [],
            'xrefs' => [
                ['val' => 'OMIM:123456'],
                ['val' => 'Orphanet:789'],
            ],
        ];

        $this->callMethod('extractMondoMappingsArray', ['MONDO:0001234', $meta]);

        $xrefOmim = $this->getProperty('mondoXrefOmim');
        $xrefOrphanet = $this->getProperty('mondoXrefOrphanet');

        $this->assertArrayHasKey('OMIM:123456', $xrefOmim);
        $this->assertContains('MONDO:0001234', $xrefOmim['OMIM:123456']);

        $this->assertArrayHasKey('Orphanet:789', $xrefOrphanet);
        $this->assertContains('MONDO:0001234', $xrefOrphanet['Orphanet:789']);
    }

    /**
     * @test
     */
    public function extract_mondo_mappings_skips_xref_if_exact_match_exists(): void
    {
        // First establish an exact_match
        $metaWithExactMatch = [
            'basicPropertyValues' => [
                [
                    'pred' => 'http://www.w3.org/2004/02/skos/core#exactMatch',
                    'val' => 'http://omim.org/entry/123456',
                ],
            ],
            'xrefs' => [],
        ];

        $this->callMethod('extractMondoMappingsArray', ['MONDO:0001111', $metaWithExactMatch]);

        // Now try to add it as xref from another MONDO term
        $metaWithXref = [
            'basicPropertyValues' => [],
            'xrefs' => [
                ['val' => 'OMIM:123456'],
            ],
        ];

        $this->callMethod('extractMondoMappingsArray', ['MONDO:0002222', $metaWithXref]);

        // xref should NOT have been added since exact_match exists
        $xrefOmim = $this->getProperty('mondoXrefOmim');
        $this->assertArrayNotHasKey('OMIM:123456', $xrefOmim);
    }

    /**
     * @test
     */
    public function extract_mondo_mappings_throws_on_duplicate_exact_match(): void
    {
        $meta = [
            'basicPropertyValues' => [
                [
                    'pred' => 'http://www.w3.org/2004/02/skos/core#exactMatch',
                    'val' => 'http://omim.org/entry/999999',
                ],
            ],
            'xrefs' => [],
        ];

        // First MONDO term claims this OMIM
        $this->callMethod('extractMondoMappingsArray', ['MONDO:0001111', $meta]);

        // Second MONDO term tries to claim the same OMIM - should throw
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('multiple MONDO exact_match');

        $this->callMethod('extractMondoMappingsArray', ['MONDO:0002222', $meta]);
    }

    /**
     * @test
     */
    public function extract_mondo_mappings_handles_empty_meta(): void
    {
        // Should not throw
        $this->callMethod('extractMondoMappingsArray', ['MONDO:0001234', []]);
        $this->assertTrue(true); // No exception means success
    }

    // =========================================================================
    // MONDO ID Determination Tests
    // =========================================================================

    /**
     * @test
     */
    public function determine_mondo_id_for_omim_prioritizes_exact_match(): void
    {
        // Set up mappings
        $this->setProperty('mondoExactMatchOmim', ['OMIM:123456' => 'MONDO:0001111']);
        $this->setProperty('mondoXrefOmim', ['OMIM:123456' => ['MONDO:0002222', 'MONDO:0003333']]);
        $this->setProperty('mondoCurieToId', [
            'MONDO:0001111' => 100,
            'MONDO:0002222' => 200,
            'MONDO:0003333' => 300,
        ]);

        $result = $this->callMethod('determineMondoIdForOmim', ['OMIM:123456']);

        // Should return exact_match ID, not xref
        $this->assertEquals(100, $result);
    }

    /**
     * @test
     */
    public function determine_mondo_id_for_omim_falls_back_to_xref(): void
    {
        // Set up mappings - no exact_match
        $this->setProperty('mondoExactMatchOmim', []);
        $this->setProperty('mondoXrefOmim', ['OMIM:123456' => ['MONDO:0002222', 'MONDO:0003333']]);
        $this->setProperty('mondoCurieToId', [
            'MONDO:0002222' => 200,
            'MONDO:0003333' => 300,
        ]);

        $result = $this->callMethod('determineMondoIdForOmim', ['OMIM:123456']);

        // Should return first xref
        $this->assertEquals(200, $result);
    }

    /**
     * @test
     */
    public function determine_mondo_id_for_omim_returns_null_when_not_found(): void
    {
        $this->setProperty('mondoExactMatchOmim', []);
        $this->setProperty('mondoXrefOmim', []);
        $this->setProperty('mondoCurieToId', []);

        $result = $this->callMethod('determineMondoIdForOmim', ['OMIM:999999']);

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function determine_mondo_id_for_orphanet_prioritizes_exact_match(): void
    {
        $this->setProperty('mondoExactMatchOrphanet', ['Orphanet:789' => 'MONDO:0001111']);
        $this->setProperty('mondoXrefOrphanet', ['Orphanet:789' => ['MONDO:0002222']]);
        $this->setProperty('mondoCurieToId', [
            'MONDO:0001111' => 100,
            'MONDO:0002222' => 200,
        ]);

        $result = $this->callMethod('determineMondoIdForOrphanet', ['Orphanet:789']);

        $this->assertEquals(100, $result);
    }

    // =========================================================================
    // Disease Reconciliation Tests
    // =========================================================================

    /**
     * @test
     */
    public function mark_as_removed_deprecates_active_disease(): void
    {
        $disease = Disease::factory()->create([
            'curie' => 'MONDO:0099999',
            'name' => 'Test Disease',
            'type' => Disease::TYPE_MONDO,
            'status' => Disease::STATUS_ACTIVE,
        ]);

        $result = $this->callMethod('markAsRemovedOrDeprecated', [$disease]);

        $disease->refresh();

        $this->assertTrue($result['deprecated']);
        $this->assertFalse($result['has_refs']);
        $this->assertEquals(Disease::STATUS_DEPRECATED, $disease->status);
        $this->assertEquals('REMOVED- Test Disease', $disease->deprecated_name);
    }

    /**
     * @test
     */
    public function mark_as_removed_skips_already_deprecated(): void
    {
        $disease = Disease::factory()->create([
            'curie' => 'MONDO:0099999',
            'name' => 'Test Disease',
            'type' => Disease::TYPE_MONDO,
            'status' => Disease::STATUS_DEPRECATED,
        ]);

        $result = $this->callMethod('markAsRemovedOrDeprecated', [$disease]);

        $this->assertFalse($result['deprecated']);
        $this->assertFalse($result['has_refs']);
    }

    /**
     * @test
     */
    public function mark_as_removed_detects_submission_references(): void
    {
        $disease = Disease::factory()->create([
            'curie' => 'MONDO:0099999',
            'name' => 'Test Disease',
            'type' => Disease::TYPE_MONDO,
            'status' => Disease::STATUS_ACTIVE,
        ]);

        // Create a submission that references this disease
        Submission::factory()->create([
            'disease_id' => $disease->id,
        ]);

        $result = $this->callMethod('markAsRemovedOrDeprecated', [$disease]);

        $this->assertTrue($result['deprecated']);
        $this->assertTrue($result['has_refs']);
    }

    // =========================================================================
    // Xref Parsing Tests
    // =========================================================================

    /**
     * @test
     */
    public function x_mondo_xrefs_array_extracts_all_fields(): void
    {
        $meta = [
            'basicPropertyValues' => [
                ['val' => 'http://omim.org/entry/123456'],
                ['val' => 'http://omim.org/entry/789012'],
            ],
            'xrefs' => [
                ['val' => 'DOID:1234'],
                ['val' => 'OMIM:345678'],
                ['val' => 'Orphanet:9999'],
                ['val' => 'GARD:555'],
                ['val' => 'UMLS:C12345'],
                ['val' => 'MESH:D001234'],
                ['val' => 'NCIT:C99999'],
                ['val' => 'OGMS:0000001'],
            ],
        ];

        $result = $this->callMethod('x_mondo_xrefs_array', [$meta]);

        $this->assertIsArray($result);
        $this->assertContains('123456', $result['omim_id']);
        $this->assertContains('789012', $result['omim_id']);
        $this->assertContains('345678', $result['omim_id']);
        $this->assertEquals('1234', $result['do_id']);
        $this->assertEquals('9999', $result['orpha_id']);
        $this->assertEquals('555', $result['gard_id']);
        $this->assertEquals('C12345', $result['umls_id']);
        $this->assertEquals('D001234', $result['mesh']);
        $this->assertEquals('C99999', $result['ncit']);
        $this->assertEquals('0000001', $result['ogms']);
    }

    /**
     * @test
     */
    public function x_mondo_xrefs_array_handles_empty_meta(): void
    {
        $result = $this->callMethod('x_mondo_xrefs_array', [[]]);

        $this->assertIsArray($result);
        $this->assertEquals([], $result['omim_id']);
        $this->assertNull($result['orpha_id']);
    }

    /**
     * @test
     */
    public function x_mondo_xrefs_array_deduplicates_omim_ids(): void
    {
        $meta = [
            'basicPropertyValues' => [
                ['val' => 'http://omim.org/entry/123456'],
            ],
            'xrefs' => [
                ['val' => 'OMIM:123456'],  // Duplicate
            ],
        ];

        $result = $this->callMethod('x_mondo_xrefs_array', [$meta]);

        $this->assertCount(1, $result['omim_id']);
        $this->assertEquals(['123456'], $result['omim_id']);
    }

    // =========================================================================
    // Synonym Parsing Tests
    // =========================================================================

    /**
     * @test
     */
    public function x_mondo_synonym_array_filters_exact_synonyms(): void
    {
        $synonyms = [
            ['pred' => 'hasExactSynonym', 'val' => 'Disease synonym 1'],
            ['pred' => 'hasRelatedSynonym', 'val' => 'Related synonym'],
            ['pred' => 'hasExactSynonym', 'val' => 'Disease synonym 2'],
        ];

        $result = $this->callMethod('x_mondo_synonym_array', [$synonyms]);

        $this->assertCount(2, $result);
        $this->assertContains('Disease synonym 1', $result);
        $this->assertContains('Disease synonym 2', $result);
        $this->assertNotContains('Related synonym', $result);
    }

    /**
     * @test
     */
    public function x_mondo_synonym_array_handles_empty_array(): void
    {
        $result = $this->callMethod('x_mondo_synonym_array', [[]]);
        $this->assertEquals([], $result);
    }

    // =========================================================================
    // Disease Cache Tests
    // =========================================================================

    /**
     * @test
     */
    public function preload_disease_cache_builds_correct_structure(): void
    {
        // Create test diseases
        $mondoDisease = Disease::factory()->create([
            'curie' => 'MONDO:0001234',
            'type' => Disease::TYPE_MONDO,
            'status' => Disease::STATUS_ACTIVE,
        ]);

        $omimDisease = Disease::factory()->create([
            'curie' => 'OMIM:123456',
            'type' => Disease::TYPE_OMIM,
            'status' => Disease::STATUS_ACTIVE,
        ]);

        $this->callMethod('preloadDiseaseCache', []);

        $cache = $this->getProperty('diseaseCache');
        $mondoCurieToId = $this->getProperty('mondoCurieToId');

        // Check disease cache
        $this->assertArrayHasKey('MONDO:0001234', $cache);
        $this->assertArrayHasKey('OMIM:123456', $cache);
        $this->assertEquals($mondoDisease->id, $cache['MONDO:0001234']['id']);
        $this->assertEquals($mondoDisease->ident, $cache['MONDO:0001234']['ident']);

        // Check MONDO curie to ID mapping
        $this->assertArrayHasKey('MONDO:0001234', $mondoCurieToId);
        $this->assertEquals($mondoDisease->id, $mondoCurieToId['MONDO:0001234']);

        // OMIM should NOT be in mondoCurieToId
        $this->assertArrayNotHasKey('OMIM:123456', $mondoCurieToId);
    }

    /**
     * @test
     */
    public function disease_cache_preserves_existing_ident(): void
    {
        $existingIdent = 'existing-ident-uuid';

        Disease::factory()->create([
            'curie' => 'MONDO:0001234',
            'ident' => $existingIdent,
            'type' => Disease::TYPE_MONDO,
            'status' => Disease::STATUS_ACTIVE,
        ]);

        $this->callMethod('preloadDiseaseCache', []);

        $cache = $this->getProperty('diseaseCache');

        $this->assertEquals($existingIdent, $cache['MONDO:0001234']['ident']);
    }
}
