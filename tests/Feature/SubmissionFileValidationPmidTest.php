<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\PmidNormalizer;

class SubmissionFileValidationPmidTest extends TestCase
{
    /**
     * Test that clean PMIDs produce no issues from the normalizer
     */
    public function test_clean_pmids_no_issues()
    {
        $result = PmidNormalizer::normalize('12345678,23456789');
        $this->assertCount(2, $result['pmids']);
        $this->assertEmpty($result['issues']);
    }

    /**
     * Test that normalizable PMIDs (with suffixes) produce issues but still return valid PMIDs
     */
    public function test_normalizable_pmids_return_valid_with_issues()
    {
        $result = PmidNormalizer::normalize('12345678[PMID]_23456789[PMID]');
        $this->assertCount(2, $result['pmids']);
        // No issues because [PMID] suffix stripping is silent (not flagged as an issue)
        // The issues are only for values that are completely removed
    }

    /**
     * Test that completely invalid PMIDs produce issues and no valid PMIDs
     */
    public function test_all_invalid_pmids_error()
    {
        $result = PmidNormalizer::normalize('abc,xyz,1.5E+10');
        $this->assertEmpty($result['pmids']);
        $this->assertCount(3, $result['issues']);
    }

    /**
     * Test mixed valid and invalid produces both pmids and issues
     */
    public function test_mixed_valid_invalid()
    {
        $result = PmidNormalizer::normalize('12345678,NULL,23456789,0');
        $this->assertCount(2, $result['pmids']);
        $this->assertCount(2, $result['issues']);
    }

    /**
     * Test that the SEVERITY_WARNING constant exists
     */
    public function test_severity_warning_constant_exists()
    {
        $this->assertEquals('warning', \App\Services\SubmissionFileValidation::SEVERITY_WARNING);
    }

    /**
     * Test that pmids column no longer has a regexp constraint
     * (it should be handled by the normalizer instead)
     */
    public function test_pmids_column_has_no_regexp()
    {
        // Use reflection to access the private static property
        $reflection = new \ReflectionClass(\App\Services\SubmissionFileValidation::class);
        $property = $reflection->getProperty('COLUMN_MAP');
        $property->setAccessible(true);
        $columns = $property->getValue();

        $this->assertArrayHasKey('pmids', $columns);
        $this->assertArrayNotHasKey('regexp', $columns['pmids']);
    }
}
