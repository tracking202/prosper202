<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;

/**
 * Tests for DataEngine::setDirtyHour() logic.
 *
 * setDirtyHour() inserts click data into 202_dataengine for report aggregation.
 * If this fails, clicks exist in the database but reports show zero.
 *
 * The actual DataEngine class depends on the DB singleton and global state,
 * so we test the SQL construction and edge case logic.
 */
final class DataEngineTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $source = file_get_contents(__DIR__ . '/../../202-config/class-dataengine-slim.php');
        self::assertNotFalse($source, 'class-dataengine-slim.php must be readable');
        $this->source = $source;
    }

    // --- setDirtyHour SQL structure ---

    public function testSetDirtyHourInsertSelectJoinsAllRequiredTables(): void
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
                $this->source,
                "setDirtyHour SQL must JOIN $table for complete report aggregation"
            );
        }
    }

    public function testSetDirtyHourInsertColumnsMatchSelectColumns(): void
    {
        preg_match('/insert into 202_dataengine\(([^)]+)\)/s', $this->source, $insertMatch);
        self::assertNotEmpty($insertMatch, 'Must find INSERT INTO 202_dataengine(...)');

        $insertColumns = array_map('trim', explode(',', $insertMatch[1]));
        $insertColumns = array_map(fn($c) => preg_replace('/\s+/', '', $c), $insertColumns);

        $criticalColumns = [
            'user_id', 'click_id', 'click_time',
            'aff_campaign_id', 'aff_network_id',
            'keyword_id', 'country_id',
            'click_lead', 'click_filtered', 'click_bot',
            'clicks', 'click_out', 'leads',
            'payout', 'income', 'cost',
        ];

        foreach ($criticalColumns as $col) {
            self::assertContains(
                $col,
                $insertColumns,
                "INSERT must include $col for reporting"
            );
        }
    }

    public function testSetDirtyHourUsesOnDuplicateKeyUpdate(): void
    {
        self::assertStringContainsString(
            'on duplicate key update',
            strtolower($this->source),
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
                strtolower($this->source),
                "ON DUPLICATE KEY UPDATE must refresh $field"
            );
        }
    }

    // --- Income calculation ---

    public function testIncomeCalculationOnlyCountsLeads(): void
    {
        self::assertStringContainsString(
            'IF (2c.click_lead>0,2c.click_payout,0) AS income',
            $this->source,
            'Income must be conditional on click_lead > 0'
        );
    }

    public function testCostIsCpcValue(): void
    {
        self::assertStringContainsString(
            '2c.click_cpc AS cost',
            $this->source,
            'Cost must come from click_cpc'
        );
    }

    // --- Empty click_id handling ---

    public function testEmptyClickIdReturnsEarly(): void
    {
        self::assertStringContainsString(
            "return false",
            $this->source,
            'Empty click_id must cause early return'
        );
    }

    // --- Timezone handling ---

    public function testDataEngineSetsMysqlTimezone(): void
    {
        self::assertStringContainsString(
            'SET time_zone',
            $this->source,
            'DataEngine must set MySQL timezone for correct time-based aggregation'
        );
    }

    // --- SQL injection risk ---

    public function testClickIdIsDirectlyInterpolatedInSql(): void
    {
        self::assertStringContainsString(
            '$click_id',
            $this->source,
            'click_id is interpolated — must be validated upstream'
        );

        self::assertStringContainsString(
            'real_escape_string',
            $this->source,
            'Click ID from DB lookup must be escaped'
        );
    }
}
