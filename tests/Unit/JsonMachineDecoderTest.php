<?php

namespace Tests\Unit;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use PHPUnit\Framework\TestCase;

/**
 * Tests to ensure JsonMachine is configured to return arrays, not stdClass objects.
 *
 * These tests exist because JsonMachine's default behavior is to return stdClass
 * objects, which causes "Cannot use object of type stdClass as array" errors.
 * The ExtJsonDecoder(true) option must be used to return associative arrays.
 */
class JsonMachineDecoderTest extends TestCase
{
    /**
     * Test that ExtJsonDecoder(true) returns arrays, not stdClass objects.
     */
    public function test_ext_json_decoder_returns_arrays(): void
    {
        $json = '{"items": [{"id": 1, "name": "test", "meta": {"nested": "value"}}]}';
        $tempFile = tempnam(sys_get_temp_dir(), 'json_test_');
        file_put_contents($tempFile, $json);

        try {
            $items = Items::fromFile($tempFile, [
                'pointer' => '/items',
                'decoder' => new ExtJsonDecoder(true),
            ]);

            foreach ($items as $item) {
                // Should be array, not stdClass
                $this->assertIsArray($item, 'Item should be an array, not stdClass');
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('name', $item);
                $this->assertArrayHasKey('meta', $item);

                // Nested objects should also be arrays
                $this->assertIsArray($item['meta'], 'Nested object should be an array');
                $this->assertEquals('value', $item['meta']['nested']);

                // Can use array access syntax
                $this->assertEquals(1, $item['id']);
                $this->assertEquals('test', $item['name']);
            }
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Test that without ExtJsonDecoder, JsonMachine returns stdClass (default behavior).
     * This test documents the behavior we're protecting against.
     */
    public function test_default_decoder_returns_stdclass(): void
    {
        $json = '{"items": [{"id": 1, "name": "test"}]}';
        $tempFile = tempnam(sys_get_temp_dir(), 'json_test_');
        file_put_contents($tempFile, $json);

        try {
            $items = Items::fromFile($tempFile, [
                'pointer' => '/items',
                // No decoder specified - uses default
            ]);

            foreach ($items as $item) {
                // Default behavior returns stdClass, not array
                $this->assertInstanceOf(\stdClass::class, $item, 'Default should return stdClass');

                // This would throw "Cannot use object of type stdClass as array"
                // if we tried: $item['id']
            }
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Test array access on deeply nested structures.
     * MONDO JSON has deeply nested structures like meta->basicPropertyValues.
     */
    public function test_nested_array_access(): void
    {
        $json = '{"nodes": [{"id": "test", "meta": {"basicPropertyValues": [{"pred": "exactMatch", "val": "http://example.com"}], "xrefs": [{"val": "OMIM:123"}]}}]}';
        $tempFile = tempnam(sys_get_temp_dir(), 'json_test_');
        file_put_contents($tempFile, $json);

        try {
            $nodes = Items::fromFile($tempFile, [
                'pointer' => '/nodes',
                'decoder' => new ExtJsonDecoder(true),
            ]);

            foreach ($nodes as $node) {
                $this->assertIsArray($node);

                $meta = $node['meta'] ?? [];
                $this->assertIsArray($meta);

                // Access nested arrays
                $basicProps = $meta['basicPropertyValues'] ?? [];
                $this->assertIsArray($basicProps);
                $this->assertCount(1, $basicProps);
                $this->assertEquals('exactMatch', $basicProps[0]['pred']);

                $xrefs = $meta['xrefs'] ?? [];
                $this->assertIsArray($xrefs);
                $this->assertEquals('OMIM:123', $xrefs[0]['val']);
            }
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Test null coalescing works correctly with array access.
     */
    public function test_null_coalescing_with_arrays(): void
    {
        $json = '{"items": [{"id": 1}]}';
        $tempFile = tempnam(sys_get_temp_dir(), 'json_test_');
        file_put_contents($tempFile, $json);

        try {
            $items = Items::fromFile($tempFile, [
                'pointer' => '/items',
                'decoder' => new ExtJsonDecoder(true),
            ]);

            foreach ($items as $item) {
                // Null coalescing should work
                $this->assertEquals(1, $item['id'] ?? 'default');
                $this->assertEquals('default', $item['nonexistent'] ?? 'default');
                $this->assertEquals('nested_default', $item['meta']['nested'] ?? 'nested_default');
            }
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Test that HUGO-style JSON (used in UpdateGenes) parses correctly.
     */
    public function test_hugo_json_structure(): void
    {
        $json = '{"response": {"docs": [{"hgnc_id": "HGNC:1", "symbol": "A1BG", "name": "alpha-1-B glycoprotein", "alias_symbol": ["ABG", "GAB"], "omim_id": ["138670"]}]}}';
        $tempFile = tempnam(sys_get_temp_dir(), 'json_test_');
        file_put_contents($tempFile, $json);

        try {
            $genes = Items::fromFile($tempFile, [
                'pointer' => '/response/docs',
                'decoder' => new ExtJsonDecoder(true),
            ]);

            foreach ($genes as $record) {
                $this->assertIsArray($record);

                // Direct key access
                $this->assertEquals('HGNC:1', $record['hgnc_id']);
                $this->assertEquals('A1BG', $record['symbol']);
                $this->assertEquals('alpha-1-B glycoprotein', $record['name']);

                // Array fields
                $this->assertIsArray($record['alias_symbol']);
                $this->assertEquals(['ABG', 'GAB'], $record['alias_symbol']);

                // Nested array with null coalescing
                $omimId = $record['omim_id'][0] ?? null;
                $this->assertEquals('138670', $omimId);

                // Missing field with null coalescing
                $missingField = $record['nonexistent'][0] ?? null;
                $this->assertNull($missingField);
            }
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Test that MONDO-style JSON (used in UpdateDiseases) parses correctly.
     */
    public function test_mondo_json_structure(): void
    {
        $json = '{"graphs": [{"nodes": [{"id": "http://purl.obolibrary.org/obo/MONDO_0000001", "lbl": "disease", "meta": {"deprecated": false, "definition": {"val": "A disease is..."}, "synonyms": [{"pred": "hasExactSynonym", "val": "illness"}], "basicPropertyValues": [{"pred": "http://www.w3.org/2004/02/skos/core#exactMatch", "val": "http://omim.org/entry/123456"}], "xrefs": [{"val": "OMIM:123456"}]}}]}]}';
        $tempFile = tempnam(sys_get_temp_dir(), 'json_test_');
        file_put_contents($tempFile, $json);

        try {
            $nodes = Items::fromFile($tempFile, [
                'pointer' => '/graphs/0/nodes',
                'decoder' => new ExtJsonDecoder(true),
            ]);

            foreach ($nodes as $node) {
                $this->assertIsArray($node);

                // Direct access
                $nodeId = $node['id'] ?? '';
                $this->assertStringContains('MONDO_0000001', $nodeId);

                $label = $node['lbl'] ?? '';
                $this->assertEquals('disease', $label);

                // Nested meta access
                $meta = $node['meta'] ?? [];
                $this->assertIsArray($meta);

                $isDeprecated = $meta['deprecated'] ?? false;
                $this->assertFalse($isDeprecated);

                $definition = $meta['definition']['val'] ?? '';
                $this->assertEquals('A disease is...', $definition);

                // Access basicPropertyValues array
                $basicProps = $meta['basicPropertyValues'] ?? [];
                $this->assertIsArray($basicProps);
                foreach ($basicProps as $prop) {
                    $this->assertIsArray($prop);
                    $this->assertArrayHasKey('pred', $prop);
                    $this->assertArrayHasKey('val', $prop);
                }

                // Access xrefs array
                $xrefs = $meta['xrefs'] ?? [];
                $this->assertIsArray($xrefs);
                foreach ($xrefs as $xref) {
                    $this->assertIsArray($xref);
                    $val = $xref['val'] ?? '';
                    $this->assertStringContains('OMIM', $val);
                }

                // Access synonyms array
                $synonyms = $meta['synonyms'] ?? [];
                $this->assertIsArray($synonyms);
                foreach ($synonyms as $synonym) {
                    $this->assertIsArray($synonym);
                    $pred = $synonym['pred'] ?? '';
                    $this->assertEquals('hasExactSynonym', $pred);
                }
            }
        } finally {
            unlink($tempFile);
        }
    }

    /**
     * Helper to check string contains substring (PHP 7 compatible).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            strpos($haystack, $needle) !== false,
            "Failed asserting that '$haystack' contains '$needle'"
        );
    }
}
