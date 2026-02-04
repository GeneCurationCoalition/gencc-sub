<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\PmidNormalizer;

class PmidNormalizerTest extends TestCase
{
    public function test_null_input_returns_empty()
    {
        $result = PmidNormalizer::normalize(null);
        $this->assertSame([], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_empty_string_returns_empty()
    {
        $result = PmidNormalizer::normalize('');
        $this->assertSame([], $result['pmids']);
        $this->assertSame([], $result['issues']);

        $result = PmidNormalizer::normalize('  ');
        $this->assertSame([], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_simple_comma_separated()
    {
        $result = PmidNormalizer::normalize('12345678,23456789');
        $this->assertSame(['12345678', '23456789'], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_semicolon_separated()
    {
        $result = PmidNormalizer::normalize('12345678;23456789');
        $this->assertSame(['12345678', '23456789'], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_underscore_separated()
    {
        $result = PmidNormalizer::normalize('12345678_23456789');
        $this->assertSame(['12345678', '23456789'], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_whitespace_separated()
    {
        $result = PmidNormalizer::normalize('12345678 23456789');
        $this->assertSame(['12345678', '23456789'], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_mixed_separators()
    {
        $result = PmidNormalizer::normalize('12345678,23456789;34567890 45678901');
        $this->assertSame(['12345678', '23456789', '34567890', '45678901'], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_pmid_prefix_stripped()
    {
        $result = PmidNormalizer::normalize('PMID:12345678');
        $this->assertSame(['12345678'], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_pmid_suffix_stripped()
    {
        $result = PmidNormalizer::normalize('12345678[PMID]');
        $this->assertSame(['12345678'], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_pmid_prefix_case_insensitive()
    {
        $result = PmidNormalizer::normalize('pmid:12345678');
        $this->assertSame(['12345678'], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_literal_null_flagged()
    {
        $result = PmidNormalizer::normalize('NULL');
        $this->assertSame([], $result['pmids']);
        $this->assertCount(1, $result['issues']);
        $this->assertSame('NULL', $result['issues'][0]['value']);
        $this->assertSame('literal_null', $result['issues'][0]['reason']);
    }

    public function test_literal_null_case_insensitive()
    {
        $result = PmidNormalizer::normalize('null');
        $this->assertSame([], $result['pmids']);
        $this->assertCount(1, $result['issues']);
        $this->assertSame('null', $result['issues'][0]['value']);
        $this->assertSame('literal_null', $result['issues'][0]['reason']);
    }

    public function test_scientific_notation_flagged()
    {
        $result = PmidNormalizer::normalize('1.5845E+15');
        $this->assertSame([], $result['pmids']);
        $this->assertCount(1, $result['issues']);
        $this->assertSame('1.5845E+15', $result['issues'][0]['value']);
        $this->assertSame('scientific_notation', $result['issues'][0]['reason']);
    }

    public function test_non_numeric_flagged()
    {
        $result = PmidNormalizer::normalize('abc123');
        $this->assertSame([], $result['pmids']);
        $this->assertCount(1, $result['issues']);
        $this->assertSame('abc123', $result['issues'][0]['value']);
        $this->assertSame('non_numeric', $result['issues'][0]['reason']);
    }

    public function test_zero_value_flagged()
    {
        $result = PmidNormalizer::normalize('0');
        $this->assertSame([], $result['pmids']);
        $this->assertCount(1, $result['issues']);
        $this->assertSame('0', $result['issues'][0]['value']);
        $this->assertSame('zero_value', $result['issues'][0]['reason']);

        $result = PmidNormalizer::normalize('000');
        $this->assertSame([], $result['pmids']);
        $this->assertCount(1, $result['issues']);
        $this->assertSame('000', $result['issues'][0]['value']);
        $this->assertSame('zero_value', $result['issues'][0]['reason']);
    }

    public function test_exceeds_max_digits_flagged()
    {
        $result = PmidNormalizer::normalize('123456789');
        $this->assertSame([], $result['pmids']);
        $this->assertCount(1, $result['issues']);
        $this->assertSame('123456789', $result['issues'][0]['value']);
        $this->assertSame('exceeds_max_digits', $result['issues'][0]['reason']);
    }

    public function test_eight_digits_is_valid()
    {
        $result = PmidNormalizer::normalize('12345678');
        $this->assertSame(['12345678'], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_non_breaking_space_handled()
    {
        $result = PmidNormalizer::normalize("12345678\xC2\xA023456789");
        $this->assertSame(['12345678', '23456789'], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_sorted_ascending()
    {
        $result = PmidNormalizer::normalize('23456789,12345678');
        $this->assertSame(['12345678', '23456789'], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_duplicates_removed()
    {
        $result = PmidNormalizer::normalize('12345678,12345678');
        $this->assertSame(['12345678'], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_mixed_valid_and_invalid()
    {
        $result = PmidNormalizer::normalize('12345678,abc,23456789,NULL');
        $this->assertSame(['12345678', '23456789'], $result['pmids']);
        $this->assertCount(2, $result['issues']);
    }

    public function test_all_invalid_returns_empty_pmids()
    {
        $result = PmidNormalizer::normalize('abc,NULL,0');
        $this->assertSame([], $result['pmids']);
        $this->assertCount(3, $result['issues']);
    }

    public function test_pmid_suffix_with_underscore_separator()
    {
        $result = PmidNormalizer::normalize('12345678[PMID]_23456789[PMID]');
        $this->assertSame(['12345678', '23456789'], $result['pmids']);
        $this->assertSame([], $result['issues']);
    }

    public function test_leading_zeros_stripped_with_warning()
    {
        $result = PmidNormalizer::normalize('0012345678');
        // Leading zeros are stripped, producing the clean PMID
        $this->assertSame(['12345678'], $result['pmids']);
        // A warning issue is recorded for the stripping
        $this->assertCount(1, $result['issues']);
        $this->assertSame('0012345678', $result['issues'][0]['value']);
        $this->assertSame('leading_zeros_stripped', $result['issues'][0]['reason']);
    }

    public function test_leading_zeros_exceeds_digits()
    {
        $result = PmidNormalizer::normalize('00123456789');
        $this->assertSame([], $result['pmids']);
        // Two issues: leading_zeros_stripped then exceeds_max_digits
        $this->assertCount(2, $result['issues']);
        $this->assertSame('leading_zeros_stripped', $result['issues'][0]['reason']);
        $this->assertSame('exceeds_max_digits', $result['issues'][1]['reason']);
    }
}
