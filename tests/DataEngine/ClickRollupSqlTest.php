<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\ClickRollupSql;

/**
 * Tests for the click rollup statement shared by DataEngine::setDirtyHour(),
 * DataEngine::getSummary() and the slim tracking-path engine.
 *
 * The rollup inserts click data into 202_dataengine for report aggregation.
 * If this breaks, clicks exist in the database but reports show zero.
 */
final class ClickRollupSqlTest extends TestCase
{
    private string $sql;

    protected function setUp(): void
    {
        $this->sql = ClickRollupSql::insertSelect('202_dataengine', '2c.click_id=123', true);
    }

    public function testInsertSelectJoinsAllRequiredTables(): void
    {
        $requiredJoins = [
            '202_clicks',
            '202_clicks_record',
            '202_clicks_advance',
            '202_clicks_tracking',
            '202_clicks_variable',
            '202_clicks_site',
            '202_clicks_rotator',
            '202_google',
            '202_aff_campaigns',
            '202_aff_networks',
            '202_ppc_accounts',
            '202_ppc_networks',
            '202_keywords',
            '202_browsers',
            '202_platforms',
            '202_text_ads',
            '202_site_urls',
            '202_locations_country',
            '202_locations_region',
            '202_locations_city',
            '202_locations_isp',
            '202_device_models',
            '202_ips',
            '202_tracking_c1',
            '202_tracking_c2',
            '202_tracking_c3',
            '202_tracking_c4',
            '202_landing_pages',
        ];

        foreach ($requiredJoins as $table) {
            self::assertStringContainsString(
                $table,
                $this->sql,
                "Rollup SQL must JOIN $table for complete report aggregation"
            );
        }
    }

    public function testInsertColumnsMatchSelectColumns(): void
    {
        preg_match('/insert into 202_dataengine\(([^)]+)\)/s', $this->sql, $insertMatch);
        self::assertNotEmpty($insertMatch, 'Must find INSERT INTO 202_dataengine(...)');

        $insertColumns = array_map(
            static fn(string $column): string => preg_replace('/\s+/', '', $column),
            explode(',', $insertMatch[1])
        );

        preg_match('/SELECT\s+(.*?)\s+FROM 202_clicks/s', $this->sql, $selectMatch);
        self::assertNotEmpty($selectMatch, 'Must find the SELECT list');
        $selectExpressions = array_map('trim', explode(",\n", $selectMatch[1]));

        self::assertSame(
            count($insertColumns),
            count($selectExpressions),
            'INSERT column count must equal SELECT expression count'
        );

        // Every SELECT expression must target its INSERT column: either the
        // expression is aliased "AS <column>" or it is a direct
        // "<table>.<column>" reference.
        foreach ($insertColumns as $position => $column) {
            $expression = $selectExpressions[$position];
            $matches = str_ends_with($expression, 'AS ' . $column)
                || str_ends_with(str_replace('`', '', $expression), '.' . $column)
                || str_replace('`', '', $expression) === $column;
            self::assertTrue(
                $matches,
                "SELECT expression '$expression' at position $position does not produce INSERT column '$column'"
            );
        }
    }

    /**
     * Regression test: the legacy getSummary() copy of this SQL listed
     * utm_medium_id before utm_source_id in the INSERT columns while the
     * SELECT still produced source before medium, silently writing each
     * value into the other column.
     */
    public function testUtmColumnsAreAligned(): void
    {
        preg_match('/insert into 202_dataengine\(([^)]+)\)/s', $this->sql, $insertMatch);
        $insertColumns = array_map(
            static fn(string $column): string => preg_replace('/\s+/', '', $column),
            explode(',', $insertMatch[1])
        );

        preg_match('/SELECT\s+(.*?)\s+FROM 202_clicks/s', $this->sql, $selectMatch);
        $selectExpressions = array_map('trim', explode(",\n", $selectMatch[1]));

        $sourcePosition = array_search('utm_source_id', $insertColumns, true);
        $mediumPosition = array_search('utm_medium_id', $insertColumns, true);

        self::assertNotFalse($sourcePosition);
        self::assertNotFalse($mediumPosition);
        self::assertSame('2gg.utm_source_id', $selectExpressions[$sourcePosition]);
        self::assertSame('2gg.utm_medium_id', $selectExpressions[$mediumPosition]);
    }

    public function testUsesOnDuplicateKeyUpdate(): void
    {
        self::assertStringContainsString(
            'on duplicate key update',
            strtolower($this->sql),
            'Must use ON DUPLICATE KEY UPDATE to handle reconversion'
        );
    }

    public function testDuplicateKeyUpdateRefreshesRevenueFields(): void
    {
        $updatedFields = [
            'click_lead', 'click_filtered', 'click_bot', 'click_out',
            'leads', 'payout', 'income', 'cost',
            'landing_page_id', 'aff_campaign_id', 'aff_network_id',
        ];

        foreach ($updatedFields as $field) {
            self::assertStringContainsString(
                "$field=values($field)",
                strtolower($this->sql),
                "ON DUPLICATE KEY UPDATE must refresh $field"
            );
        }
    }

    public function testLandingPageUpdateCanBeExcluded(): void
    {
        $sql = ClickRollupSql::insertSelect('202_dataengine', '2c.click_id=123', false);

        self::assertStringNotContainsString(
            'landing_page_id=values(landing_page_id)',
            $sql,
            'Batch re-aggregation paths must not refresh landing_page_id'
        );
        self::assertStringContainsString('leads=values(leads)', $sql);
    }

    public function testIncomeCalculationOnlyCountsLeads(): void
    {
        self::assertStringContainsString(
            'IF (2c.click_lead>0,2c.click_payout,0) AS income',
            $this->sql,
            'Income must be conditional on click_lead > 0'
        );
    }

    public function testCostIsCpcValue(): void
    {
        self::assertStringContainsString(
            '2c.click_cpc AS cost',
            $this->sql,
            'Cost must come from click_cpc'
        );
    }

    public function testTargetTableAndWhereClauseAreUsed(): void
    {
        $sql = ClickRollupSql::insertSelect('202_dataengine_new', '2c.click_time >= 1 AND 2c.click_time <= 2');

        self::assertStringContainsString('insert into 202_dataengine_new(', $sql);
        self::assertStringContainsString('WHERE 2c.click_time >= 1 AND 2c.click_time <= 2', $sql);
    }
}
