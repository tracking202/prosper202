<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\ClickTables;
use Prosper202\DataEngine\ClickRollupSql;
use Tests\Support\SourceScan;

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
        $this->sql = ClickRollupSql::forClick('202_dataengine', 123);
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

    /**
     * A re-roll converges a row to what a fresh rollup computes: every
     * column the SELECT derives is refreshed, the key alone excepted. The
     * list named fourteen columns, so a row kept the traffic source, text ad
     * and the rest of what its first rollup saw (ClickRollupSql says what
     * that looked like in the reports).
     */
    public function testADuplicateKeyRefreshesEveryColumnButTheKey(): void
    {
        preg_match('/insert into 202_dataengine\(([^)]+)\)/s', $this->sql, $insertMatch);
        $insertColumns = array_map(
            static fn(string $column): string => preg_replace('/\s+/', '', $column),
            explode(',', $insertMatch[1])
        );
        preg_match('/on duplicate key update\s+(.*)$/s', $this->sql, $updateMatch);
        $updated = array_map(
            static fn(string $set): string => preg_replace('/=values\((\w+)\)$/', '', trim($set)),
            explode(",\n", $updateMatch[1])
        );
        foreach (explode(",\n", $updateMatch[1]) as $set) {
            self::assertMatchesRegularExpression(
                '/^(\w+)=values\(\1\)$/',
                trim($set),
                'each column takes its own new value'
            );
        }

        self::assertSame(array_values(array_diff($insertColumns, ['click_id', 'click_time'])), $updated);
        $copied = ['ppc_network_id', 'text_ad_id', 'landing_page_id', 'keyword_id', 'country_id', 'variable_set_id'];
        foreach ($copied as $column) {
            self::assertContains($column, $updated);
        }
        self::assertNotContains('click_id', $updated);
        self::assertNotContains('click_time', $updated);
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

    /**
     * On MariaDB 10.11 a small 202_clicks was scanned (EXPLAIN type ALL), and
     * the scan's shared locks deadlocked the rollup of one click with the next
     * click's insert, which was rolled back and lost (errno 1213). The rollup
     * of one click is the range statement with 202_clicks read by its key.
     */
    public function testTheRollupOfOneClickReadsTheClickThroughItsKey(): void
    {
        self::assertStringContainsString(
            "\nFROM 202_clicks AS 2c FORCE INDEX (`click_id`)\nLEFT OUTER JOIN 202_clicks_record AS 2cr",
            $this->sql,
            'The rollup of one click must read 202_clicks through its click_id key'
        );
        self::assertStringContainsString("\nWHERE 2c.click_id=123\n", $this->sql);
        self::assertSame(
            str_replace(
                "\nFROM 202_clicks AS 2c\n",
                "\nFROM 202_clicks AS 2c FORCE INDEX (`click_id`)\n",
                ClickRollupSql::insertSelect('202_dataengine', '2c.click_id=123')
            ),
            $this->sql,
            'Only the read of 202_clicks differs from the range statement'
        );
    }

    public function testARangeRollupKeepsTheOptimizersChoice(): void
    {
        $sql = ClickRollupSql::insertSelect('202_dataengine', '2c.click_time >= 1 AND 2c.click_time <= 2');

        self::assertStringContainsString("\nFROM 202_clicks AS 2c\nLEFT OUTER JOIN 202_clicks_record AS 2cr", $sql);
        self::assertDoesNotMatchRegularExpression('/\b(FORCE|USE|IGNORE)\s+(INDEX|KEY)\b/i', $sql);
    }

    /** A hint naming a key the table lacks fails every rollup: "Key 'click_id' doesn't exist". */
    public function testTheKeyTheHintNamesIsInTheSchema(): void
    {
        self::assertStringContainsString('KEY `click_id` (`click_id`)', ClickTables::clicks()->createStatement);
    }

    /**
     * Every rollup of one click goes through forClick(); insertSelect() is
     * left the ranges. Read from tokens: the argument list of each
     * insertSelect() call in the served tree, to its closing parenthesis. A
     * predicate put in a variable first is not seen.
     */
    public function testNoServedFileRollsUpOneClickThroughTheRangeStatement(): void
    {
        $calls = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            $tokens = token_get_all($source);
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'insertSelect') {
                    continue;
                }
                $before = $i - 1;
                while (is_array($tokens[$before]) && $tokens[$before][0] === T_WHITESPACE) {
                    $before--;
                }
                $j = $i + 1;
                while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ((is_array($tokens[$before]) && $tokens[$before][0] === T_FUNCTION) || $tokens[$j] !== '(') {
                    continue;
                }
                $depth = 0;
                $arguments = '';
                for ($k = $j; $k < count($tokens); $k++) {
                    $text = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                    $depth += $text === '(' ? 1 : ($text === ')' ? -1 : 0);
                    $arguments .= $text;
                    if ($depth === 0) {
                        break;
                    }
                }
                $calls++;
                $where = $path . ':' . $token[2];
                self::assertStringNotContainsString(
                    'click_id',
                    $arguments,
                    "$where rolls up one click with insertSelect(); use ClickRollupSql::forClick()"
                );
            }
        }
        self::assertGreaterThanOrEqual(1, $calls, 'the scan found the range rollup DataEngine::getSummary() makes');
    }
}
