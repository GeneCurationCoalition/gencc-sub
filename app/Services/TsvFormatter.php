<?php

namespace App\Services;

/**
 * TSV post-processor that strips unnecessary quotes from TSV files.
 *
 * PhpSpreadsheet/Maatwebsite Excel quotes ALL values by default.
 * This class processes TSV files to only quote values that need it:
 * - Values containing tabs
 * - Values containing newlines
 * - Values containing double quotes (which are escaped as "")
 */
class TsvFormatter
{
    /**
     * Process a TSV file to strip unnecessary quotes.
     *
     * @param string $inputPath Path to the input TSV file
     * @param string|null $outputPath Path to write output (null = overwrite input)
     * @return int Number of rows processed
     */
    public static function stripUnnecessaryQuotes(string $inputPath, ?string $outputPath = null): int
    {
        $outputPath = $outputPath ?? $inputPath;
        $tempPath = $inputPath . '.tmp';

        $input = fopen($inputPath, 'r');
        $output = fopen($tempPath, 'w');

        if (!$input || !$output) {
            throw new \RuntimeException("Failed to open files for TSV processing");
        }

        $rowCount = 0;

        // Use fgetcsv which properly handles quoted values spanning multiple lines
        while (($fields = fgetcsv($input, 0, "\t", '"', '"')) !== false) {
            $rowCount++;

            // Process each field - only quote if necessary
            $processedFields = array_map([self::class, 'formatField'], $fields);

            // Write the processed row
            fwrite($output, implode("\t", $processedFields) . "\n");
        }

        fclose($input);
        fclose($output);

        // Replace original with processed file
        rename($tempPath, $outputPath);

        return $rowCount;
    }

    /**
     * Format a single field, quoting only if necessary.
     *
     * TSV files should have one row per line for maximum compatibility,
     * so embedded newlines are replaced with spaces.
     *
     * @param string|null $value The field value
     * @return string The formatted field (quoted if necessary)
     */
    private static function formatField(?string $value): string
    {
        // Handle null values as empty strings
        if ($value === null) {
            return '';
        }

        // Replace embedded newlines with spaces for TSV compatibility
        // (most TSV parsers expect one row per line)
        $value = preg_replace('/\r\n|\r|\n/', ' ', $value);

        // Trim trailing spaces that may have resulted from trailing newlines
        $value = rtrim($value);

        // Check if the value needs quoting (now only tabs or quotes)
        if (!self::needsQuoting($value)) {
            return $value;
        }

        // Escape any embedded double quotes by doubling them
        $escaped = str_replace('"', '""', $value);

        return '"' . $escaped . '"';
    }

    /**
     * Determine if a value needs to be quoted in TSV format.
     *
     * A value needs quoting if it contains:
     * - Tab characters (the delimiter)
     * - Double quote characters
     *
     * Note: Newlines are replaced with spaces before this check,
     * so they don't need to be considered here.
     *
     * @param string $value The value to check
     * @return bool True if the value needs quoting
     */
    private static function needsQuoting(string $value): bool
    {
        // Empty values don't need quoting
        if ($value === '') {
            return false;
        }

        // Check for characters that require quoting (tabs or quotes only)
        return strpbrk($value, "\t\"") !== false;
    }
}
