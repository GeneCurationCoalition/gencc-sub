<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * The one reading of a date a submitter gives us — the report date from a
 * spreadsheet, the API or the portal's dialog, and the publish date the portal
 * sets — so those paths cannot disagree about what was written.
 *
 * Accepted spellings, all year-first, so a day and month can never be read the
 * wrong way round:
 *
 *  - a spreadsheet date cell, which reaches PHP as Excel's day count
 *  - YYYY-MM-DD
 *  - YYYY/MM/DD
 *  - a full ISO 8601 timestamp, of which only the date is kept
 *
 * Anything else is rejected, including `08/26/2026` and `26/08/2026`, which
 * differ only by local convention, and free text such as `Aug 26, 2026`.
 *
 * A bare number needs no special case: a year typed into a date column, 2026
 * say, is Excel day 2026 and lands in 1905, outside the allowed range.  That
 * range also keeps stored values inside the limits of the MySQL timestamp
 * columns these dates live in, which would otherwise fail the write.
 */
class SubmittedDate
{
    /** No GenCC curation predates this, and MySQL timestamps start here */
    public const EARLIEST = '1970-01-01';

    /** Told to a submitter whose value we could not read */
    public const GUIDANCE = 'Use YYYY-MM-DD (e.g. 2024-01-15); YYYY/MM/DD and a full ISO 8601 timestamp are also accepted.';

    private const DATE_PATTERN = '/^(\d{4})[-\/](\d{2})[-\/](\d{2})$/';

    private const ISO_TIMESTAMP_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})[T ]\d{2}:\d{2}/';

    /**
     * The date a submitted value spells, or null when it spells none.  Whether
     * that date is in the allowed range is a separate question, asked with
     * isInAllowedRange().
     */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (is_numeric($value)) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
        }

        if (! is_string($value)) {
            return null;
        }

        if (preg_match(self::DATE_PATTERN, trim($value), $parts)
            || preg_match(self::ISO_TIMESTAMP_PATTERN, trim($value), $parts)) {
            [, $year, $month, $day] = $parts;

            return checkdate((int) $month, (int) $day, (int) $year)
                ? CarbonImmutable::create((int) $year, (int) $month, (int) $day)
                : null;
        }

        return null;
    }

    /**
     * Whether a date falls in the allowed range: not before curation existed,
     * and not in the future.  A day of slack covers a submitter whose local
     * date is already tomorrow.
     */
    public static function isInAllowedRange(CarbonImmutable $date): bool
    {
        return $date->betweenIncluded(self::earliest(), self::latest());
    }

    public static function earliest(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::EARLIEST);
    }

    public static function latest(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addDay()->endOfDay();
    }

    /**
     * The date to store, or null when the value is unusable.
     */
    public static function usable(mixed $value): ?CarbonImmutable
    {
        $date = self::parse($value);

        return $date !== null && self::isInAllowedRange($date) ? $date : null;
    }

    /**
     * Why a value cannot be used as a date, or null when it can.
     */
    public static function rejectionReason(mixed $value): ?string
    {
        $date = self::parse($value);

        if ($date === null) {
            return 'Not a date. '.self::GUIDANCE;
        }

        if (! self::isInAllowedRange($date)) {
            return "'{$date->format('Y-m-d')}' is outside the allowed date range, "
                .self::earliest()->format('Y-m-d').' to today.';
        }

        return null;
    }
}
