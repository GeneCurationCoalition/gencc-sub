<?php

namespace Tests\Unit;

use App\Services\SubmittedDate;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Tests\TestCase;

/**
 * Which dates a submitter can write, and which values are refused.
 */
class SubmittedDateTest extends TestCase
{
    public static function acceptedCases(): array
    {
        return [
            'ISO date' => ['2024-01-15', '2024-01-15'],
            'slashes' => ['2024/01/15', '2024-01-15'],
            'ISO timestamp with zone' => ['2024-01-15T00:00:00+00:00', '2024-01-15'],
            'ISO timestamp with space' => ['2024-01-15 13:45:00', '2024-01-15'],
            'ISO timestamp with minutes' => ['2024-01-15T13:45', '2024-01-15'],
            'ISO timestamp with UTC marker' => ['2024-01-15T13:45:59Z', '2024-01-15'],
            'fractional seconds and negative offset' => ['2024-01-15T23:45:00.123456-05:00', '2024-01-15'],
            'positive offset preserves written date' => ['2024-01-15T00:30:00+14:00', '2024-01-15'],
            'timestamp on leap day' => ['2024-02-29T12:30:00Z', '2024-02-29'],
            'surrounding spaces' => ['  2024-01-15  ', '2024-01-15'],
            'spreadsheet date cell' => [ExcelDate::stringToExcel('2024-01-15'), '2024-01-15'],
            'the earliest allowed day' => [SubmittedDate::EARLIEST, SubmittedDate::EARLIEST],
        ];
    }

    /**
     * @test
     *
     * @dataProvider acceptedCases
     */
    public function it_accepts_year_first_dates($submitted, string $expected): void
    {
        $this->assertSame($expected, SubmittedDate::usable($submitted)?->format('Y-m-d'));
        $this->assertNull(SubmittedDate::rejectionReason($submitted));
    }

    public static function rejectedCases(): array
    {
        return [
            // A year typed into a date column is Excel day 2026, which is in 1905
            'a bare year' => [2026, 'outside the allowed date range'],
            'US month first' => ['08/26/2024', 'Not a date'],
            'day first' => ['26/08/2024', 'Not a date'],
            'long form' => ['Aug 26, 2024', 'Not a date'],
            'no day' => ['2024-08', 'Not a date'],
            'single digit parts' => ['2024-8-6', 'Not a date'],
            'impossible day' => ['2024-13-45', 'Not a date'],
            'malformed timestamp suffix' => ['2024-01-15T99:99garbage', 'Not a date'],
            'invalid hour' => ['2024-01-15T25:00:00', 'Not a date'],
            'invalid minute' => ['2024-01-15T12:60:00', 'Not a date'],
            'invalid second' => ['2024-01-15T12:30:99', 'Not a date'],
            'invalid offset hour' => ['2024-01-15T12:30:00+99:00', 'Not a date'],
            'invalid offset minute' => ['2024-01-15T12:30:00+01:60', 'Not a date'],
            'trailing junk' => ['2024-01-15T12:30:00Zjunk', 'Not a date'],
            'incomplete seconds' => ['2024-01-15T12:30:', 'Not a date'],
            'incomplete fraction' => ['2024-01-15T12:30:00.', 'Not a date'],
            'incomplete offset' => ['2024-01-15T12:30:00+01:', 'Not a date'],
            'timestamp with impossible day' => ['2024-02-30T12:30:00Z', 'Not a date'],
            'timestamp on invalid leap day' => ['2023-02-29T12:30:00Z', 'Not a date'],
            'not a date at all' => ['sometime last year', 'Not a date'],
            'empty' => ['', 'Not a date'],
            'null' => [null, 'Not a date'],
            'before the range' => ['1969-12-31', 'outside the allowed date range'],
            'a date cell before the range' => [ExcelDate::stringToExcel('1905-07-18'), 'outside the allowed date range'],
            'far future' => ['2999-01-01', 'outside the allowed date range'],
        ];
    }

    /**
     * @test
     *
     * @dataProvider rejectedCases
     */
    public function it_rejects_anything_else($submitted, string $because): void
    {
        $this->assertNull(SubmittedDate::usable($submitted));
        $this->assertStringContainsString($because, SubmittedDate::rejectionReason($submitted));
    }

    /** @test */
    public function it_allows_a_day_of_slack_for_a_submitter_already_on_tomorrow(): void
    {
        $now = CarbonImmutable::now('UTC');

        $this->assertNotNull(SubmittedDate::usable($now->format('Y-m-d')));
        $this->assertNotNull(SubmittedDate::usable($now->addDay()->format('Y-m-d')));
        $this->assertNull(SubmittedDate::usable($now->addDays(2)->format('Y-m-d')));
    }

    public function test_range_reason_includes_the_actual_upper_bound(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC'));

        try {
            $this->assertSame(
                "'2999-01-01' is outside the allowed date range, 1970-01-01 to 2026-09-18 (including a one-day allowance for time zones).",
                SubmittedDate::rejectionReason('2999-01-01')
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
