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

    public function testEveryClauseKeepsItsLeadingSpace(): void
    {
        // Report SQL concatenates the clause directly after
        // "group by <column>", so the leading space is load-bearing.
        self::assertStringStartsWith(' ', SortOrder::DEFAULT_ORDER);
        self::assertStringStartsWith(' ', SortOrder::orderByClause('sort_breakdown_clicks asc'));
        self::assertStringStartsWith(' ', SortOrder::orderByClause('breakdown desc'));
    }

    public function testTimeOrderingMapsDirectly(): void
    {
        self::assertSame(' ORDER BY click_time DESC', SortOrder::orderByClause('sort_breakdown_time_order desc'));
        self::assertSame(' ORDER BY click_time ASC', SortOrder::orderByClause('sort_breakdown_time_order asc'));
    }

    public function testMetricOrderingMatchesItsKey(): void
    {
        // The legacy map inverted these (asc sorted DESC) to match a toggle
        // contract of a UI that no longer exists; keys now mean what they say.
        self::assertSame(' ORDER BY `clicks` ASC', SortOrder::orderByClause('sort_breakdown_clicks asc'));
        self::assertSame(' ORDER BY `clicks` DESC', SortOrder::orderByClause('sort_breakdown_clicks desc'));
        self::assertSame(' ORDER BY `net` ASC', SortOrder::orderByClause('sort_breakdown_net asc'));
        self::assertSame(' ORDER BY `roi` DESC', SortOrder::orderByClause('sort_breakdown_roi desc'));
    }

    public function testSuRatioSortsByItsRealAlias(): void
    {
        // The legacy map produced ORDER BY `su`, a column no report query
        // defines; the metric's alias is su_ratio.
        self::assertSame(' ORDER BY `su_ratio` DESC', SortOrder::orderByClause('sort_breakdown_su_ratio desc'));
    }

    public function testCanonicalKeyOnlyReflectsWhitelistedKeys(): void
    {
        self::assertSame('sort_breakdown_income desc', SortOrder::canonicalKey('sort_breakdown_income desc'));
        self::assertSame('', SortOrder::canonicalKey(''));
        self::assertSame('', SortOrder::canonicalKey("x'); alert(1);//"));
        self::assertSame('', SortOrder::canonicalKey('sort_breakdown_income DESC'), 'Case must match exactly');
    }

    public function testHourBreakdownOrdering(): void
    {
        self::assertSame(
            ' ORDER BY cast(DATE_FORMAT(FROM_UNIXTIME(click_time),"%k") as UNSIGNED) ASC',
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
                self::assertSame(
                    ' ORDER BY `' . str_replace(['sort_breakdown_', 'click_throughs'], ['', 'click_out'], $key) . '` ' . strtoupper($direction),
                    $clause,
                    "Clause for '$key $direction' must sort its own column in the stated direction"
                );
            }
        }
    }
}
