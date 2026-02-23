<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\TsvFormatter;
use Illuminate\Support\Facades\File;

class TsvFormatterTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = storage_path('app/test-tsv-' . uniqid());
        File::makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    /**
     * Create a temp TSV file with the given content.
     */
    private function createTsvFile(string $content): string
    {
        $path = $this->tempDir . '/test.tsv';
        File::put($path, $content);
        return $path;
    }

    /**
     * Test that simple values (no special characters) have quotes stripped.
     */
    public function test_strips_unnecessary_quotes_from_simple_values(): void
    {
        $input = "\"col1\"\t\"col2\"\t\"col3\"\n\"value1\"\t\"value2\"\t\"value3\"\n";
        $expected = "col1\tcol2\tcol3\nvalue1\tvalue2\tvalue3\n";

        $path = $this->createTsvFile($input);
        TsvFormatter::stripUnnecessaryQuotes($path);

        $this->assertEquals($expected, File::get($path));
    }

    /**
     * Test that values containing tabs remain quoted.
     */
    public function test_preserves_quotes_for_values_with_tabs(): void
    {
        $input = "\"col1\"\t\"has\ttab\"\t\"col3\"\n";
        $expected = "col1\t\"has\ttab\"\tcol3\n";

        $path = $this->createTsvFile($input);
        TsvFormatter::stripUnnecessaryQuotes($path);

        $this->assertEquals($expected, File::get($path));
    }

    /**
     * Test that values containing newlines have them replaced with spaces.
     * TSV files should have one row per line for compatibility.
     */
    public function test_replaces_newlines_with_spaces(): void
    {
        // Input with a value containing a newline (properly quoted in CSV/TSV)
        $input = "\"col1\"\t\"has\nnewline\"\t\"col3\"\n";
        // Newline replaced with space, no quoting needed
        $expected = "col1\thas newline\tcol3\n";

        $path = $this->createTsvFile($input);
        TsvFormatter::stripUnnecessaryQuotes($path);

        $this->assertEquals($expected, File::get($path));
    }

    /**
     * Test that values containing double quotes remain quoted (with escaping).
     */
    public function test_preserves_quotes_for_values_with_embedded_quotes(): void
    {
        // In CSV/TSV, embedded quotes are escaped by doubling: "say ""hello"""
        $input = "\"col1\"\t\"say \"\"hello\"\"\"\t\"col3\"\n";
        $expected = "col1\t\"say \"\"hello\"\"\"\tcol3\n";

        $path = $this->createTsvFile($input);
        TsvFormatter::stripUnnecessaryQuotes($path);

        $this->assertEquals($expected, File::get($path));
    }

    /**
     * Test that empty values are handled correctly.
     */
    public function test_handles_empty_values(): void
    {
        $input = "\"col1\"\t\"\"\t\"col3\"\n";
        $expected = "col1\t\tcol3\n";

        $path = $this->createTsvFile($input);
        TsvFormatter::stripUnnecessaryQuotes($path);

        $this->assertEquals($expected, File::get($path));
    }

    /**
     * Test that null values from fgetcsv are handled correctly.
     * fgetcsv can return null for certain malformed fields.
     */
    public function test_handles_null_values(): void
    {
        // This tests the internal handling - a simple case with empty field
        $input = "\"col1\"\t\t\"col3\"\n";
        $expected = "col1\t\tcol3\n";

        $path = $this->createTsvFile($input);
        TsvFormatter::stripUnnecessaryQuotes($path);

        $this->assertEquals($expected, File::get($path));
    }

    /**
     * Test that values containing carriage return have them replaced with spaces.
     */
    public function test_replaces_carriage_return_with_spaces(): void
    {
        $input = "\"col1\"\t\"has\rreturn\"\t\"col3\"\n";
        // CR replaced with space, no quoting needed
        $expected = "col1\thas return\tcol3\n";

        $path = $this->createTsvFile($input);
        TsvFormatter::stripUnnecessaryQuotes($path);

        $this->assertEquals($expected, File::get($path));
    }

    /**
     * Test processing multiple rows.
     */
    public function test_processes_multiple_rows(): void
    {
        $input = "\"header1\"\t\"header2\"\t\"header3\"\n";
        $input .= "\"row1col1\"\t\"row1col2\"\t\"row1col3\"\n";
        $input .= "\"row2col1\"\t\"row2col2\"\t\"row2col3\"\n";

        $expected = "header1\theader2\theader3\n";
        $expected .= "row1col1\trow1col2\trow1col3\n";
        $expected .= "row2col1\trow2col2\trow2col3\n";

        $path = $this->createTsvFile($input);
        $rowCount = TsvFormatter::stripUnnecessaryQuotes($path);

        $this->assertEquals(3, $rowCount);
        $this->assertEquals($expected, File::get($path));
    }

    /**
     * Test that the method returns the correct row count.
     */
    public function test_returns_row_count(): void
    {
        $input = "\"h1\"\t\"h2\"\n\"v1\"\t\"v2\"\n\"v3\"\t\"v4\"\n";

        $path = $this->createTsvFile($input);
        $rowCount = TsvFormatter::stripUnnecessaryQuotes($path);

        $this->assertEquals(3, $rowCount);
    }

    /**
     * Test writing to a different output path.
     */
    public function test_can_write_to_different_output_path(): void
    {
        $input = "\"col1\"\t\"col2\"\n";
        $expected = "col1\tcol2\n";

        $inputPath = $this->createTsvFile($input);
        $outputPath = $this->tempDir . '/output.tsv';

        TsvFormatter::stripUnnecessaryQuotes($inputPath, $outputPath);

        // Original file should be unchanged
        $this->assertEquals($input, File::get($inputPath));
        // Output file should have stripped quotes
        $this->assertEquals($expected, File::get($outputPath));
    }

    /**
     * Test mixed values - some need quoting, some don't.
     */
    public function test_mixed_values(): void
    {
        // Tab requires quoting, newline gets replaced with space
        $input = "\"simple\"\t\"has\ttab\"\t\"also simple\"\t\"has\nnewline\"\n";
        $expected = "simple\t\"has\ttab\"\talso simple\thas newline\n";

        $path = $this->createTsvFile($input);
        TsvFormatter::stripUnnecessaryQuotes($path);

        $this->assertEquals($expected, File::get($path));
    }

    /**
     * Test realistic gencc-submissions data.
     */
    public function test_realistic_gencc_data(): void
    {
        // Simulate a typical gencc-submissions row with all quoted values
        $input = "\"SGC-100001\"\t\"1\"\t\"HGNC:1234\"\t\"GENE1\"\t\"MONDO:0000001\"\t\"Some Disease Name\"\n";
        $expected = "SGC-100001\t1\tHGNC:1234\tGENE1\tMONDO:0000001\tSome Disease Name\n";

        $path = $this->createTsvFile($input);
        TsvFormatter::stripUnnecessaryQuotes($path);

        $this->assertEquals($expected, File::get($path));
    }

    /**
     * Test that trailing newlines in values are trimmed.
     */
    public function test_trims_trailing_newlines(): void
    {
        // Value with trailing newline (common in notes fields)
        $input = "\"col1\"\t\"text with trailing newline\n\"\t\"col3\"\n";
        // Trailing newline replaced with space, then trimmed
        $expected = "col1\ttext with trailing newline\tcol3\n";

        $path = $this->createTsvFile($input);
        TsvFormatter::stripUnnecessaryQuotes($path);

        $this->assertEquals($expected, File::get($path));
    }

    /**
     * Test notes field with embedded newline (real-world case from gencc data).
     */
    public function test_notes_field_with_embedded_newline(): void
    {
        // Simulates the actual problematic case - notes with trailing newline
        $input = "\"SGC-125171\"\t\"1\"\t\"Long notes text here.\n\"\t\"30250217\"\n";
        // Newline replaced with space, trimmed, unquoted
        $expected = "SGC-125171\t1\tLong notes text here.\t30250217\n";

        $path = $this->createTsvFile($input);
        TsvFormatter::stripUnnecessaryQuotes($path);

        $this->assertEquals($expected, File::get($path));
    }
}
