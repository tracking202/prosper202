<?php
declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * Tests for functions-timeframe.php
 * These are pure date/time calculation functions
 */
final class TimeframeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Include the functions file
        require_once __DIR__ . '/../../202-config/functions-timeframe.php';

        // Set a consistent timezone for testing
        date_default_timezone_set('UTC');
    }

    /**
     * @dataProvider timeframeProvider
     */
    public function testGrabTimeframeReturnsCorrectStructure(string $timeframe): void
    {
        $result = grab_timeframe($timeframe);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('from', $result);
        $this->assertArrayHasKey('to', $result);
        $this->assertIsInt($result['from']);
        $this->assertIsInt($result['to']);
        $this->assertLessThanOrEqual($result['to'], $result['from']);
    }

    public static function timeframeProvider(): array
    {
        return [
            'today' => ['today'],
            'yesterday' => ['yesterday'],
            'last7' => ['last7'],
            'last14' => ['last14'],
            'last30' => ['last30'],
            'thismonth' => ['thismonth'],
            'lastmonth' => ['lastmonth'],
        ];
    }

    public function testGrabTimeframeTodayCoversFullDay(): void
    {
        $result = grab_timeframe('today');

        // Should start at 00:00:00
        $this->assertSame('00:00:00', date('H:i:s', $result['from']));
        // Should end at 23:59:59
        $this->assertSame('23:59:59', date('H:i:s', $result['to']));
        // Should be same day
        $this->assertSame(date('Y-m-d', $result['from']), date('Y-m-d', $result['to']));
        $this->assertSame(date('Y-m-d'), date('Y-m-d', $result['from']));
    }

    public function testGrabTimeframeYesterdayCoversFullDay(): void
    {
        $result = grab_timeframe('yesterday');

        // Should start at 00:00:00
        $this->assertSame('00:00:00', date('H:i:s', $result['from']));
        // Should end at 23:59:59
        $this->assertSame('23:59:59', date('H:i:s', $result['to']));
        // Should be same day (yesterday)
        $this->assertSame(date('Y-m-d', $result['from']), date('Y-m-d', $result['to']));
        $this->assertSame(date('Y-m-d', strtotime('-1 day')), date('Y-m-d', $result['from']));
    }

    public function testGrabTimeframeLast7DaysCoversCorrectRange(): void
    {
        $result = grab_timeframe('last7');

        // Should end today at 23:59:59
        $this->assertSame(date('Y-m-d'), date('Y-m-d', $result['to']));
        $this->assertSame('23:59:59', date('H:i:s', $result['to']));

        // Should start 7 days ago at 00:00:00
        $expectedStart = date('Y-m-d', strtotime('-7 days'));
        $this->assertSame($expectedStart, date('Y-m-d', $result['from']));
        $this->assertSame('00:00:00', date('H:i:s', $result['from']));
    }

    public function testGrabTimeframeLast14DaysCoversCorrectRange(): void
    {
        $result = grab_timeframe('last14');

        // Should end today at 23:59:59
        $this->assertSame(date('Y-m-d'), date('Y-m-d', $result['to']));

        // Should start 14 days ago
        $expectedStart = date('Y-m-d', strtotime('-14 days'));
        $this->assertSame($expectedStart, date('Y-m-d', $result['from']));
    }

    public function testGrabTimeframeLast30DaysCoversCorrectRange(): void
    {
        $result = grab_timeframe('last30');

        // Should end today at 23:59:59
        $this->assertSame(date('Y-m-d'), date('Y-m-d', $result['to']));

        // Should start 30 days ago
        $expectedStart = date('Y-m-d', strtotime('-30 days'));
        $this->assertSame($expectedStart, date('Y-m-d', $result['from']));
    }

    public function testGrabTimeframeThisMonthCoversFullMonth(): void
    {
        $result = grab_timeframe('thismonth');

        // Should start on first day of this month
        $expectedStart = date('Y-m-01');
        $this->assertSame($expectedStart, date('Y-m-d', $result['from']));
        $this->assertSame('00:00:00', date('H:i:s', $result['from']));

        // Should end on last day of this month
        $expectedEnd = date('Y-m-t');
        $this->assertSame($expectedEnd, date('Y-m-d', $result['to']));
        $this->assertSame('23:59:59', date('H:i:s', $result['to']));
    }

    public function testGrabTimeframeLastMonthCoversFullMonth(): void
    {
        $result = grab_timeframe('lastmonth');

        // Should start on first day of last month
        $expectedStart = date('Y-m-01', strtotime('first day of last month'));
        $this->assertSame($expectedStart, date('Y-m-d', $result['from']));
        $this->assertSame('00:00:00', date('H:i:s', $result['from']));

        // Should end on last day of last month
        $expectedEnd = date('Y-m-t', strtotime('last day of last month'));
        $this->assertSame($expectedEnd, date('Y-m-d', $result['to']));
        $this->assertSame('23:59:59', date('H:i:s', $result['to']));
    }

    public function testGrabTimeframeUnknownDefaultsToToday(): void
    {
        $result = grab_timeframe('unknown');
        $today = grab_timeframe('today');

        $this->assertSame($today['from'], $result['from']);
        $this->assertSame($today['to'], $result['to']);
    }

    public function testGrabTimeframeEmptyStringDefaultsToToday(): void
    {
        $result = grab_timeframe('');
        $today = grab_timeframe('today');

        $this->assertSame($today['from'], $result['from']);
        $this->assertSame($today['to'], $result['to']);
    }

    public function testGetLastDayOfMonthReturnsCorrectValue(): void
    {
        // Test various months
        $this->assertSame('31', getLastDayOfMonth(1, 2024));  // January
        $this->assertSame('29', getLastDayOfMonth(2, 2024));  // February (leap year)
        $this->assertSame('28', getLastDayOfMonth(2, 2023));  // February (non-leap year)
        $this->assertSame('31', getLastDayOfMonth(3, 2024));  // March
        $this->assertSame('30', getLastDayOfMonth(4, 2024));  // April
        $this->assertSame('31', getLastDayOfMonth(5, 2024));  // May
        $this->assertSame('30', getLastDayOfMonth(6, 2024));  // June
        $this->assertSame('31', getLastDayOfMonth(7, 2024));  // July
        $this->assertSame('31', getLastDayOfMonth(8, 2024));  // August
        $this->assertSame('30', getLastDayOfMonth(9, 2024));  // September
        $this->assertSame('31', getLastDayOfMonth(10, 2024)); // October
        $this->assertSame('30', getLastDayOfMonth(11, 2024)); // November
        $this->assertSame('31', getLastDayOfMonth(12, 2024)); // December
    }

    public function testGetLastDayOfMonthLeapYearHandling(): void
    {
        // Leap years
        $this->assertSame('29', getLastDayOfMonth(2, 2000)); // Divisible by 400
        $this->assertSame('29', getLastDayOfMonth(2, 2020)); // Divisible by 4
        $this->assertSame('29', getLastDayOfMonth(2, 2024)); // Divisible by 4

        // Non-leap years
        $this->assertSame('28', getLastDayOfMonth(2, 1900)); // Divisible by 100 but not 400
        $this->assertSame('28', getLastDayOfMonth(2, 2021)); // Not divisible by 4
        $this->assertSame('28', getLastDayOfMonth(2, 2023)); // Not divisible by 4
    }

    public function testGetLastDayOfMonthWithStringInputs(): void
    {
        // Function should handle string inputs
        $this->assertSame('31', getLastDayOfMonth('1', '2024'));
        $this->assertSame('29', getLastDayOfMonth('02', '2024'));
    }
}
