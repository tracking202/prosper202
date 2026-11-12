<?php

declare(strict_types=1);

namespace Tests\Spend;

use Api\V3\Auth;
use Api\V3\Support\ReportFilter;
use PHPUnit\Framework\TestCase;
use Prosper202\Spend\LedgerCost;

/**
 * The window the ledger reads beside a report is the report's own
 * (ReportFilter::bounds(), the same reading apply() makes), and the ledger's
 * routes are their own scope area.
 */
final class SpendLedgerWindowTest extends TestCase
{
    private static function utc(): string
    {
        return 'UTC';
    }

    public function testTheBoundsAreWhereTheWindowAndThePeriodOverlap(): void
    {
        $tz = static fn (): string => self::utc();
        self::assertSame([null, null], ReportFilter::bounds([], $tz));
        self::assertSame([100, 200], ReportFilter::bounds(['time_from' => '100', 'time_to' => '200'], $tz));
        [$from, $to] = ReportFilter::bounds(['period' => 'yesterday'], $tz);
        $midnight = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->getTimestamp();
        self::assertSame([$midnight - 86400, $midnight - 1], [$from, $to]);
        // Both given: the WHERE applies both, so the ledger reads the overlap.
        self::assertSame([$midnight - 3600, $midnight - 1], ReportFilter::bounds(['period' => 'yesterday', 'time_from' => (string) ($midnight - 3600)], $tz));
        self::assertSame([$midnight - 86400, $midnight - 7200], ReportFilter::bounds(['period' => 'yesterday', 'time_to' => (string) ($midnight - 7200)], $tz));
    }

    public function testTheLedgerReadsTheWholeHoursAWindowTouches(): void
    {
        self::assertSame([7200, 10800], LedgerCost::hours(7300, 14000));
        self::assertSame([0, 4294963200], LedgerCost::hours(null, null));
    }

    public function testTheLedgerIsItsOwnScopeArea(): void
    {
        self::assertContains('spend-ledger', Auth::KNOWN_SCOPE_AREAS);
        self::assertSame('spend-ledger', Auth::scopeAreaForPath('/spend-ledger'));
        self::assertSame('spend-ledger', Auth::scopeAreaForPath('/spend-ledger/sync/3'));
    }
}
