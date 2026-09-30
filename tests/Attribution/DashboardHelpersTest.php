<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;

/**
 * The dashboard's pure helpers (202-config/functions-attribution-ui.php),
 * executed: the exact sums the journey view's "each column sums to the
 * whole conversion" rests on, the rounding the page shows money with, and
 * the report window each preset means.
 */
final class DashboardHelpersTest extends TestCase
{
    private string $tz;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/functions-ui-partials.php';
        require_once dirname(__DIR__, 2) . '/202-config/functions-attribution-ui.php';
    }

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    public function testCreditsSumExactlyWhereFloatsWouldNot(): void
    {
        // Three linear thirds as the engine stores them: the remainder is on
        // the last touch, and the column sums to exactly one.
        self::assertSame('1.00000000', p202_attr_sum(['0.33333333', '0.33333333', '0.33333334'], 8));
        self::assertSame('100.00%', p202_attr_share('1.00000000'));
        // A float sum of ten 0.1s is 0.9999999999999999.
        self::assertSame('1.00000', p202_attr_sum(array_fill(0, 10, '0.10000'), 5));
        self::assertSame('0.99999999', p202_attr_sum(['0.33333333', '0.33333333', '0.33333333'], 8), 'a missing unit shows');
        self::assertSame('100.00%', p202_attr_share('0.99999999'), 'rounded for display, but');
        self::assertNotSame('1.00000000', p202_attr_sum(['0.33333333', '0.33333333', '0.33333333'], 8), 'the exact sum says what it is');
        self::assertSame('-0.50000', p202_attr_sum(['1.00000', '-1.50000'], 5));
    }

    public function testMoneyIsRoundedHalfUpFromTheExactValue(): void
    {
        self::assertSame('$2.67', p202_attr_money('2.66666667'));
        self::assertSame('$0.01', p202_attr_money('0.00500'));
        self::assertSame('$0.00', p202_attr_money('0.00499'));
        self::assertSame('$1,234,567.89', p202_attr_money('1234567.88500'));
        self::assertSame('-$3.50', p202_attr_money('-3.50000'));
        self::assertSame('$12.00', p202_attr_money('12'));
        self::assertSame('2.67', p202_attr_credit('2.66666667'));
        self::assertSame('33.33%', p202_attr_share('0.33333333'));
        self::assertSame('66.67%', p202_attr_share('0.66666667'));
    }

    public function testANonDecimalIsABugNotAZero(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        p202_attr_sum(['1.0', 'NaN'], 5);
    }

    public function testTooManyPlacesAreRefusedRatherThanTruncated(): void
    {
        self::assertSame(150000, p202_attr_units('1.500000', 5), 'trailing zeros are fine');
        $this->expectException(\UnexpectedValueException::class);
        p202_attr_units('1.123456', 5);
    }

    public function testThePresetsMeanTheCalendarsWindows(): void
    {
        $now = (int) mktime(15, 30, 0, 3, 10, 2026); // Tue 10 March 2026, 15:30 New York
        $w = p202_attr_window('last7', '', '', $now);
        self::assertSame(['2026-03-03', '2026-03-10'], [$w['from_date'], $w['to_date']]);
        self::assertSame((int) mktime(0, 0, 0, 3, 3, 2026), $w['from']);
        self::assertSame((int) mktime(23, 59, 59, 3, 10, 2026), $w['to']);
        // Across the spring-forward change (8 March 2026) the days are still
        // whole local days, not multiples of 86,400 seconds.
        self::assertSame(7 * 86400 - 3600 + 86399, $w['to'] - $w['from']);

        $y = p202_attr_window('yesterday', '', '', $now);
        self::assertSame(['2026-03-09', '2026-03-09'], [$y['from_date'], $y['to_date']]);
        $m = p202_attr_window('lastmonth', '', '', $now);
        self::assertSame(['2026-02-01', '2026-02-28'], [$m['from_date'], $m['to_date']]);
        $t = p202_attr_window('thismonth', '', '', $now);
        self::assertSame(['2026-03-01', '2026-03-31'], [$t['from_date'], $t['to_date']]);
        $jan = p202_attr_window('lastmonth', '', '', (int) mktime(12, 0, 0, 1, 15, 2026));
        self::assertSame(['2025-12-01', '2025-12-31'], [$jan['from_date'], $jan['to_date']]);
        $d = p202_attr_window(null, '', '', $now);
        self::assertSame('last30', $d['range']);
        self::assertNull($d['error']);
    }

    public function testACustomWindowIsReadOrRefusedWithASentence(): void
    {
        $now = (int) mktime(12, 0, 0, 3, 10, 2026);
        $c = p202_attr_window('custom', '2026-02-01', '2026-02-03', $now);
        self::assertSame('custom', $c['range']);
        self::assertSame((int) mktime(0, 0, 0, 2, 1, 2026), $c['from']);
        self::assertSame((int) mktime(23, 59, 59, 2, 3, 2026), $c['to']);
        // Dates alone (a hand-written URL) mean custom.
        self::assertSame('custom', p202_attr_window(null, '2026-02-01', '2026-02-01', $now)['range']);

        foreach ([['2026-02-31', '2026-03-01'], ['02/01/2026', '2026-03-01'], ['', '2026-03-01']] as [$from, $to]) {
            $bad = p202_attr_window('custom', $from, $to, $now);
            self::assertSame('last30', $bad['range'], "$from..$to");
            self::assertStringContainsString('real day', (string) $bad['error']);
        }
        self::assertSame('The start date is after the end date.', p202_attr_window('custom', '2026-03-05', '2026-03-01', $now)['error']);
        self::assertStringContainsString('not a range this report offers', (string) p202_attr_window('forever', '', '', $now)['error']);
    }
}
