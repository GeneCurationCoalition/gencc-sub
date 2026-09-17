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
}
