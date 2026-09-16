<?php

namespace Tests\Unit;

use App\Console\Commands\UpdateDiseases;
use App\Models\Disease;
use App\Models\Submission;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Tests for UpdateDiseases command to prevent regressions.
 *
 * The parsing tests pin the storage policy: a row's xrefs record only what that
 * row's own ontology asserts, exactly.  Anything non-exact, and anything the
 * other ontology asserts about this row, must not survive parsing.
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

    /**
     * An <ExternalReferenceList> element, as Orphadata writes them.
     *
     * @param array<int, array{source: string, reference: string, relation?: string, validation?: string}> $references
     */
    protected function externalReferenceList(array $references): \SimpleXMLElement
    {
        $xml = '<ExternalReferenceList>';

        foreach ($references as $reference) {
            $relation = $reference['relation'] ?? '21527';
            $validation = $reference['validation'] ?? '21611';

            $xml .= '<ExternalReference>'
                . '<Source>' . $reference['source'] . '</Source>'
                . '<Reference>' . $reference['reference'] . '</Reference>'
                . '<DisorderMappingRelation id="' . $relation . '"><Name lang="en">relation</Name></DisorderMappingRelation>'
                . '<DisorderMappingValidationStatus id="' . $validation . '"><Name lang="en">status</Name></DisorderMappingValidationStatus>'
                . '</ExternalReference>';
        }

        return simplexml_load_string($xml . '</ExternalReferenceList>');
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
    public function record_mondo_exact_matches_claims_omim_and_orphanet_ids(): void
    {
        $this->callMethod('recordMondoExactMatches', ['MONDO:0014651', ['omim_id' => ['615438'], 'orpha_id' => ['464724']]]);

        $this->assertSame(
            ['OMIM:615438' => 'MONDO:0014651', 'Orphanet:464724' => 'MONDO:0014651'],
            $this->getProperty('exactMatchClaims')
        );
    }

    /**
     * @test
     */
    public function record_mondo_exact_matches_throws_when_two_terms_claim_one_id(): void
    {
        $matches = ['omim_id' => ['999999'], 'orpha_id' => []];

        $this->callMethod('recordMondoExactMatches', ['MONDO:0001111', $matches]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('multiple MONDO exact_match');

        $this->callMethod('recordMondoExactMatches', ['MONDO:0002222', $matches]);
    }

    // =========================================================================
    // Xref Parsing Tests
    // =========================================================================

    /**
     * @test
     */
    public function x_mondo_xrefs_array_keeps_exact_matches_as_arrays(): void
    {
        $meta = [
            'basicPropertyValues' => [
                ['pred' => 'http://www.w3.org/2004/02/skos/core#exactMatch', 'val' => 'https://omim.org/entry/123456'],
                ['pred' => 'http://www.w3.org/2004/02/skos/core#exactMatch', 'val' => 'http://omim.org/entry/789012'],
                ['pred' => 'http://www.w3.org/2004/02/skos/core#exactMatch', 'val' => 'http://www.orpha.net/ORDO/Orphanet_9999'],
                ['pred' => 'http://www.w3.org/2004/02/skos/core#exactMatch', 'val' => 'http://www.orpha.net/ORDO/Orphanet_8888'],
            ],
            'xrefs' => [],
        ];

        $result = $this->callMethod('x_mondo_xrefs_array', [$meta]);

        $this->assertSame(['123456', '789012'], $result['omim_id']);
        $this->assertSame(['9999', '8888'], $result['orpha_id']);
        $this->assertNull($result['replaced_by']);
    }

    /**
     * Only exactMatch relates terms across ontologies.  The predicate filter on
     * the OMIM branch is what makes the reciprocity rule mean something.
     *
     * @test
     */
    public function x_mondo_xrefs_array_ignores_non_exact_predicates(): void
    {
        $meta = [
            'basicPropertyValues' => [
                ['pred' => 'http://www.w3.org/2004/02/skos/core#closeMatch', 'val' => 'https://omim.org/entry/123456'],
                ['pred' => 'http://purl.obolibrary.org/obo/IAO_0000233', 'val' => 'https://github.com/.../Orphanet_9999'],
            ],
            'xrefs' => [
                ['val' => 'OMIM:345678'],
                ['val' => 'Orphanet:7777'],
                ['val' => 'DOID:1234'],
                ['val' => 'GARD:555'],
                ['val' => 'UMLS:C12345'],
                ['val' => 'MESH:D001234'],
                ['val' => 'NCIT:C99999'],
                ['val' => 'OGMS:0000001'],
            ],
        ];

        $result = $this->callMethod('x_mondo_xrefs_array', [$meta]);

        $this->assertSame([], $result['omim_id']);
        $this->assertSame([], $result['orpha_id']);

        // The namespaces this policy stopped loading leave no trace at all
        $this->assertSame(['omim_id', 'orpha_id', 'replaced_by'], array_keys($result));
    }

    /**
     * @test
     */
    public function x_mondo_xrefs_array_records_the_successor_of_an_obsolete_term(): void
    {
        $meta = [
            'basicPropertyValues' => [
                ['pred' => 'http://purl.obolibrary.org/obo/IAO_0100001', 'val' => 'http://purl.obolibrary.org/obo/MONDO_0009299'],
            ],
        ];

        $result = $this->callMethod('x_mondo_xrefs_array', [$meta]);

        $this->assertSame('MONDO:0009299', $result['replaced_by']);
    }

    /**
     * A successor in another ontology is not a MONDO term and is not stored.
     *
     * @test
     */
    public function x_mondo_xrefs_array_ignores_a_foreign_successor(): void
    {
        $meta = [
            'basicPropertyValues' => [
                ['pred' => 'http://purl.obolibrary.org/obo/IAO_0100001', 'val' => 'http://purl.obolibrary.org/obo/CHEBI_17792'],
            ],
        ];

        $this->assertNull($this->callMethod('x_mondo_xrefs_array', [$meta])['replaced_by']);
    }

    /**
     * @test
     */
    public function x_mondo_xrefs_array_keeps_a_mondo_successor_listed_before_a_foreign_one(): void
    {
        $meta = [
            'basicPropertyValues' => [
                ['pred' => 'http://purl.obolibrary.org/obo/IAO_0100001', 'val' => 'http://purl.obolibrary.org/obo/MONDO_0009299'],
                ['pred' => 'http://purl.obolibrary.org/obo/IAO_0100001', 'val' => 'http://purl.obolibrary.org/obo/CHEBI_17792'],
            ],
        ];

        $this->assertSame('MONDO:0009299', $this->callMethod('x_mondo_xrefs_array', [$meta])['replaced_by']);
    }

    /**
     * @test
     */
    public function x_mondo_xrefs_array_handles_empty_meta(): void
    {
        $result = $this->callMethod('x_mondo_xrefs_array', [[]]);

        $this->assertSame([], $result['omim_id']);
        $this->assertSame([], $result['orpha_id']);
        $this->assertNull($result['replaced_by']);
    }

    /**
     * @test
     */
    public function x_mondo_xrefs_array_deduplicates_omim_ids(): void
    {
        $meta = [
            'basicPropertyValues' => [
                ['pred' => 'http://www.w3.org/2004/02/skos/core#exactMatch', 'val' => 'http://omim.org/entry/123456'],
                ['pred' => 'http://www.w3.org/2004/02/skos/core#exactMatch', 'val' => 'https://omim.org/entry/123456'],
            ],
        ];

        $result = $this->callMethod('x_mondo_xrefs_array', [$meta]);

        $this->assertSame(['123456'], $result['omim_id']);
    }

    // =========================================================================
    // Orphanet Xref Parsing Tests
    // =========================================================================

    /**
     * @test
     */
    public function x_orphanet_xrefs_xml_keeps_exact_validated_mondo_and_omim(): void
    {
        $list = $this->externalReferenceList([
            ['source' => 'MONDO', 'reference' => '0011778'],
            ['source' => 'OMIM', 'reference' => '217090'],
        ]);

        $result = $this->callMethod('x_orphanet_xrefs_xml', [$list]);

        $this->assertSame(['MONDO:0011778'], $result['mondo_id']);
        $this->assertSame(['217090'], $result['omim_id']);
    }

    /**
     * About a fifth of Orphadata's MONDO references are written unpadded.
     *
     * @test
     */
    public function x_orphanet_xrefs_xml_pads_mondo_references(): void
    {
        $list = $this->externalReferenceList([
            ['source' => 'MONDO', 'reference' => '44'],
            ['source' => 'MONDO', 'reference' => '7800'],
            ['source' => 'MONDO', 'reference' => '0018887'],
        ]);

        $result = $this->callMethod('x_orphanet_xrefs_xml', [$list]);

        $this->assertSame(['MONDO:0000044', 'MONDO:0007800', 'MONDO:0018887'], $result['mondo_id']);
    }

    /**
     * More than half of Orphadata's OMIM references are broader, narrower or
     * undecided.  None of them may relate terms across ontologies.
     *
     * @test
     */
    public function x_orphanet_xrefs_xml_drops_non_exact_relations(): void
    {
        $list = $this->externalReferenceList([
            ['source' => 'OMIM', 'reference' => '100100', 'relation' => '21541'],  // BTNT
            ['source' => 'OMIM', 'reference' => '100200', 'relation' => '21534'],  // NTBT
            ['source' => 'OMIM', 'reference' => '100300', 'relation' => '21576'],  // ND
            ['source' => 'OMIM', 'reference' => '100400'],                          // E
        ]);

        $result = $this->callMethod('x_orphanet_xrefs_xml', [$list]);

        $this->assertSame(['100400'], $result['omim_id']);
    }

    /**
     * @test
     */
    public function x_orphanet_xrefs_xml_drops_unvalidated_references(): void
    {
        $list = $this->externalReferenceList([
            ['source' => 'OMIM', 'reference' => '100100', 'validation' => '21618'],  // Not yet validated
            ['source' => 'MONDO', 'reference' => '0011778', 'validation' => '21618'],
        ]);

        $result = $this->callMethod('x_orphanet_xrefs_xml', [$list]);

        $this->assertSame([], $result['omim_id']);
        $this->assertSame([], $result['mondo_id']);
    }

    /**
     * @test
     */
    public function x_orphanet_xrefs_xml_drops_other_namespaces(): void
    {
        $list = $this->externalReferenceList([
            ['source' => 'UMLS', 'reference' => 'C0001080'],
            ['source' => 'GARD', 'reference' => '5810'],
            ['source' => 'ICD-10', 'reference' => 'Q77.4'],
            ['source' => 'MeSH', 'reference' => 'D000130'],
            ['source' => 'MedDRA', 'reference' => '10000003'],
        ]);

        $result = $this->callMethod('x_orphanet_xrefs_xml', [$list]);

        $this->assertSame(['mondo_id' => [], 'omim_id' => []], $result);
    }

    /**
     * An empty result must serialize as a JSON object, so reading it back gives
     * the same shape as a populated one.
     *
     * @test
     */
    public function x_orphanet_xrefs_xml_serializes_as_an_object_when_empty(): void
    {
        $result = $this->callMethod('x_orphanet_xrefs_xml', [null]);

        $this->assertSame('{"mondo_id":[],"omim_id":[]}', json_encode($result));
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

    /**
     * A phase that failed reported nothing as seen, which is indistinguishable
     * from "the source dropped every term".  Its namespace must be left alone:
     * a failed MONDO download plus a changed Orphanet file would otherwise
     * deprecate every MONDO row in the table.
     *
     * @test
     */
    public function reconciliation_skips_a_namespace_whose_phase_failed(): void
    {
        $mondo = Disease::factory()->mondo()->create(['curie' => 'MONDO:0099999']);
        $omim = Disease::factory()->omim()->create(['curie' => 'OMIM:600001']);

        $this->setProperty('seenMondoIds', []);
        $this->setProperty('seenOmimIds', []);
        $this->setProperty('seenOrphanetIds', []);

        $this->callMethod('reconcileUnseenDiseases', [['mondo']]);

        $this->assertEquals(Disease::STATUS_ACTIVE, $mondo->fresh()->status);
        $this->assertEquals(Disease::STATUS_DEPRECATED, $omim->fresh()->status);
    }

    /**
     * When the MONDO file is unchanged, every stored MONDO row counts as seen,
     * deprecated ones included.  Filtering to ACTIVE would feed reconciliation a
     * partial list.
     *
     * @test
     */
    public function existing_mondo_curies_include_deprecated_rows(): void
    {
        Disease::factory()->mondo()->create(['curie' => 'MONDO:0000001']);
        Disease::factory()->mondo()->deprecated()->create(['curie' => 'MONDO:0000002']);
        Disease::factory()->omim()->create(['curie' => 'OMIM:600001']);

        $this->callMethod('preloadDiseaseCache', []);
        $this->callMethod('loadExistingMondoCuries', []);

        $seen = $this->getProperty('seenMondoIds');

        sort($seen);
        $this->assertSame(['MONDO:0000001', 'MONDO:0000002'], $seen);
    }

    // =========================================================================
    // Disease Cache Tests
    // =========================================================================

    /**
     * @test
     */
    public function preload_disease_cache_builds_correct_structure(): void
    {
        $mondoDisease = Disease::factory()->create([
            'curie' => 'MONDO:0001234',
            'type' => Disease::TYPE_MONDO,
            'status' => Disease::STATUS_ACTIVE,
        ]);

        Disease::factory()->create([
            'curie' => 'OMIM:123456',
            'type' => Disease::TYPE_OMIM,
            'status' => Disease::STATUS_ACTIVE,
        ]);

        $this->callMethod('preloadDiseaseCache', []);

        $cache = $this->getProperty('diseaseCache');

        $this->assertArrayHasKey('MONDO:0001234', $cache);
        $this->assertArrayHasKey('OMIM:123456', $cache);
        $this->assertEquals($mondoDisease->id, $cache['MONDO:0001234']['id']);
        $this->assertEquals($mondoDisease->ident, $cache['MONDO:0001234']['ident']);
        $this->assertEquals(Disease::TYPE_MONDO, $cache['MONDO:0001234']['type']);
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

    // =========================================================================
    // Batch Upsert Column Consistency Tests
    // =========================================================================

    /**
     * @test
     * Test that batch records have consistent columns for deprecated and non-deprecated diseases.
     * Laravel's upsert() requires all records in a batch to have identical column sets.
     * This test ensures deprecated diseases (with existing cache entries) still include
     * all required columns (name, description, deprecated_name) to prevent SQL errors.
     */
    public function batch_upsert_records_have_consistent_columns(): void
    {
        // Simulate the disease cache with an existing deprecated disease
        $existingDeprecatedDisease = [
            'id' => 1,
            'ident' => 'existing-ident',
            'status' => Disease::STATUS_DEPRECATED,
            'name' => 'Original Disease Name',
            'type' => Disease::TYPE_OMIM,
        ];

        $this->setProperty('diseaseCache', [
            'OMIM:100500' => $existingDeprecatedDisease,
        ]);

        // Build two records: one new, one existing deprecated
        // This simulates what happens in the OMIM batch processing
        $now = now();

        // Record 1: New active disease
        $record1 = [
            'ident' => 'new-ident-1',
            'curie' => 'OMIM:100050',
            'type' => Disease::TYPE_OMIM,
            'synonyms' => json_encode([]),
            'xrefs' => json_encode(['include_titles' => '']),
            'status' => Disease::STATUS_ACTIVE,
            'created_at' => $now,
            'updated_at' => $now,
            'name' => 'New Disease Name',
            'description' => null,
            'deprecated_name' => null,
        ];

        // Record 2: Existing deprecated disease (Caret prefix in OMIM)
        // The bug was that this record would be missing 'name' and 'description' columns
        $existing = $this->getProperty('diseaseCache')['OMIM:100500'] ?? null;
        $record2 = [
            'ident' => $existing['ident'],
            'curie' => 'OMIM:100500',
            'type' => Disease::TYPE_OMIM_CARET,
            'synonyms' => json_encode([]),
            'xrefs' => json_encode(['include_titles' => '']),
            'status' => Disease::STATUS_DEPRECATED,
            'created_at' => $now,
            'updated_at' => $now,
            // These must be present for batch upsert consistency
            'deprecated_name' => 'MOVED TO 200150',
            'name' => $existing ? $existing['name'] : 'MOVED TO 200150',
            'description' => null,
        ];

        // Verify both records have identical column sets
        $columns1 = array_keys($record1);
        $columns2 = array_keys($record2);
        sort($columns1);
        sort($columns2);

        $this->assertEquals(
            $columns1,
            $columns2,
            'All batch records must have identical column sets for upsert()'
        );

        // Verify the existing disease's name is preserved
        $this->assertEquals('Original Disease Name', $record2['name']);
    }

    /**
     * @test
     * Test that new deprecated diseases get proper name values.
     */
    public function new_deprecated_disease_gets_name_from_deprecated_name(): void
    {
        // Empty cache - no existing disease
        $this->setProperty('diseaseCache', []);

        $existing = $this->getProperty('diseaseCache')['OMIM:999999'] ?? null;

        // For a new deprecated disease, name should come from the deprecated_name value
        $deprecatedName = 'MOVED TO 123456';
        $name = $existing ? $existing['name'] : $deprecatedName;

        $this->assertEquals($deprecatedName, $name);
        $this->assertNull($existing);
    }
}
