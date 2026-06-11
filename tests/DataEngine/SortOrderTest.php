<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\SortOrder;

final class SortOrderTest extends TestCase
{
    public function testUnknownKeyFallsBackToLeadsDesc(): void
    {
        self::assertSame(' ORDER BY leads DESC', SortOrder::orderByClause(''));
        self::assertSame(' ORDER BY leads DESC', SortOrder::orderByClause('nonsense'));
        self::assertSame(' ORDER BY leads DESC', SortOrder::orderByClause('clicks; DROP TABLE 202_clicks'));
    }

    public function testDefaultClauseKeepsItsLeadingSpace(): void
    {
        // Grouped-report SQL concatenates the default clause directly after
        // "group by <column>", so the leading space is load-bearing.
        self::assertStringStartsWith(' ', SortOrder::DEFAULT_ORDER);
    }

    public function testTimeOrderingMapsDirectly(): void
    {
        self::assertSame('ORDER BY click_time DESC', SortOrder::orderByClause('sort_breakdown_time_order desc'));
        self::assertSame('ORDER BY click_time ASC', SortOrder::orderByClause('sort_breakdown_time_order asc'));
    }

    public function testMetricOrderingIsInverted(): void
    {
        // The posted value is the *next* toggle state, so "asc" sorts DESC.
        self::assertSame('ORDER BY `clicks` DESC', SortOrder::orderByClause('sort_breakdown_clicks asc'));
        self::assertSame('ORDER BY `clicks` ASC', SortOrder::orderByClause('sort_breakdown_clicks desc'));
        self::assertSame('ORDER BY `net` DESC', SortOrder::orderByClause('sort_breakdown_net asc'));
        self::assertSame('ORDER BY `roi` ASC', SortOrder::orderByClause('sort_breakdown_roi desc'));
    }

    public function testHourBreakdownOrdering(): void
    {
        self::assertSame(
            'ORDER BY cast(DATE_FORMAT(FROM_UNIXTIME(click_time),"%k") as UNSIGNED) ASC',
            SortOrder::orderByClause('breakdown asc')
        );
    }

    public function testEveryMappedClauseIsAWhitelistedOrderBy(): void
    {
        $keys = [
            'sort_breakdown_clicks', 'sort_breakdown_click_throughs', 'sort_breakdown_ctr',
            'sort_breakdown_leads', 'sort_breakdown_su_ratio', 'sort_breakdown_payout',
            'sort_breakdown_epc', 'sort_breakdown_cpc', 'sort_breakdown_income',
            'sort_breakdown_cost', 'sort_breakdown_net', 'sort_breakdown_roi',
        ];

        foreach ($keys as $key) {
            foreach (['asc', 'desc'] as $direction) {
                $clause = SortOrder::orderByClause($key . ' ' . $direction);
                self::assertMatchesRegularExpression(
                    '/^ORDER BY `[a-z_]+` (ASC|DESC)$/',
                    $clause,
                    "Clause for '$key $direction' must be a simple whitelisted ORDER BY"
                );
            }
        }
    }
}
