<?php

namespace App\Services;

use App\Models\Classification;
use App\Models\Disease;
use App\Models\Gene;
use App\Models\Inheritance;
use Illuminate\Support\Collection;

/**
 * Field rules shared by imported records and portal field updates.
 *
 * Spreadsheet-specific validation stops before this layer. Once a row can be
 * interpreted, its values follow these same lookup and normalization rules as
 * values entered through the portal editor.
 */
class SubmissionValueValidation
{
    /** @return array{record: Gene|null, error: string|null} */
    public static function gene($value, ?Collection $cache = null): array
    {
        $submitted = trim((string) ($value ?? ''));
        $lookup = self::normalizeGeneId($submitted);
        $record = $lookup === ''
            ? null
            : ($cache !== null ? $cache->get($lookup) : Gene::hgnc_id($lookup)->first());

        return [
            'record' => $record,
            'error' => $record === null ? self::unresolvedMessage('HGNC ID', $submitted) : null,
        ];
    }

    public static function normalizeGeneId($value): string
    {
        $value = trim((string) ($value ?? ''));

        if (preg_match('/^(?:HGNC:)?(\d+)$/i', $value, $matches)) {
            return "HGNC:{$matches[1]}";
        }

        return $value;
    }

    /**
     * @return array{
     *     original: Disease|null,
     *     mondo: Disease|null,
     *     ambiguity: DiseaseMappingAmbiguity|null,
     *     error: string|null,
     *     original_error: string|null
     * }
     */
    public static function disease($value, ?DiseaseResolver $resolver = null): array
    {
        $submitted = trim((string) ($value ?? ''));
        $outcome = $submitted === ''
            ? null
            : ($resolver ?? Disease::resolver())->resolveDetailed($submitted);
        $ambiguity = $outcome instanceof DiseaseMappingAmbiguity ? $outcome : null;
        $resolution = $ambiguity === null ? $outcome : null;
        $original = $resolution?->original;
        $mondo = $resolution?->mondo;

        $error = match (true) {
            $submitted === '' => 'Missing Disease ID',
            $ambiguity !== null => $ambiguity->message($submitted),
            $mondo === null => "No MONDO term found for Disease ID '{$submitted}' (unknown ID, or no exact MONDO match)",
            default => null,
        };

        return [
            'original' => $original,
            'mondo' => $mondo,
            'ambiguity' => $ambiguity,
            'error' => $error,
            'original_error' => $original === null && $mondo !== null
                ? "Disease ID '{$submitted}' has no record of its own; submit {$mondo->curie} instead"
                : $error,
        ];
    }

    /** @return array{record: Inheritance|null, error: string|null} */
    public static function inheritance($value, ?Collection $cache = null): array
    {
        $submitted = trim((string) ($value ?? ''));
        $record = $submitted === ''
            ? null
            : ($cache !== null ? $cache->get($submitted) : Inheritance::curie($submitted)->first());

        return [
            'record' => $record,
            'error' => $record === null ? self::unresolvedMessage('MOI ID', $submitted) : null,
        ];
    }

    /** @return array{record: Classification|null, error: string|null} */
    public static function classification($value, ?Collection $cache = null): array
    {
        $submitted = trim((string) ($value ?? ''));
        $record = $submitted === ''
            ? null
            : ($cache !== null ? $cache->get($submitted) : Classification::curie($submitted)->first());

        if ($submitted === 'GENCC:000000') {
            $record = null;
            $error = 'Undefined classification cannot be selected';
        } else {
            $error = $record === null ? self::unresolvedMessage('Classification ID', $submitted) : null;
        }

        return ['record' => $record, 'error' => $error];
    }

    /** @return array{value: \Carbon\CarbonImmutable|null, error: string|null} */
    public static function reportDate($value): array
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return ['value' => null, 'error' => 'Missing Report Date'];
        }

        $date = SubmittedDate::usable($value);

        return [
            'value' => $date,
            'error' => $date === null
                ? 'Invalid Report Date: '.SubmittedDate::rejectionReason($value)
                : null,
        ];
    }

    /** @return array{value: string|null, error: string|null} */
    public static function url($value, string $label, bool $required): array
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return [
                'value' => null,
                'error' => $required ? "Missing {$label}" : null,
            ];
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $valid = filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true);

        return [
            'value' => $valid ? $value : null,
            'error' => $valid ? null : "Invalid {$label}",
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array{pmids: array<int, string>, issues: array, error: string|null}
     */
    public static function pmids(array $values): array
    {
        $raw = array_values(array_filter(
            array_map(fn ($value) => trim((string) $value), $values),
            fn ($value) => $value !== ''
        ));
        $normalized = PmidNormalizer::normalize(implode(',', $raw));

        return [
            'pmids' => $normalized['pmids'],
            'issues' => $normalized['issues'],
            'error' => ! empty($raw) && empty($normalized['pmids'])
                ? 'No valid PMIDs found after normalization'
                : null,
        ];
    }

    private static function unresolvedMessage(string $label, $submitted): string
    {
        $submitted = trim((string) $submitted);

        return $submitted === '' ? "Missing {$label}" : "Invalid {$label} '{$submitted}'";
    }
}
