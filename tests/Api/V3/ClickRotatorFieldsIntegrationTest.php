<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ClicksController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * A click's rotator, rule and redirect in GET /clicks and /clicks/{id} are
 * its 202_clicks_rotator row, and its money is numbers.
 *
 * They were 202_clicks.rotator_id and 202_clicks.rule_id. rtr.php fills
 * 202_clicks.rule_id with the chosen REDIRECT's id, so a click answered a
 * rule id that was a redirect id (another rule's, or none); and only rtr.php
 * sets 202_clicks.rotator_id, so a landing page's offer rotator's clicks
 * read as routed by no rotator. A click no rotator routed, and a rotator's
 * default (stored as rule 0), answer null.
 *
 * @group integration
 */
final class ClickRotatorFieldsIntegrationTest extends TestCase
{
    private const USER = 5911;
    /** routed by rule 3 to its redirect 7; routed by the default; no rotator; an offer rotator's */
    private const CLICKS = [971001, 971002, 971003, 971004];

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        self::$db = $db;
        self::cleanUp();

        $now = time();
        // [click, 202_clicks.rotator_id, 202_clicks.rule_id, clicks_rotator row (rotator, rule, redirect) or null]
        $rows = [
            [971001, 5, 7, [5, 3, 7]],
            [971002, 5, 0, [5, 0, 0]],
            [971003, 0, 0, null],
            [971004, 0, 0, [6, 4, 9]],
        ];
        foreach ($rows as [$click, $rotator, $rule, $routed]) {
            self::q("INSERT INTO 202_clicks SET click_id = $click, user_id = " . self::USER . ", aff_campaign_id = 0, ppc_account_id = 0,
                landing_page_id = 0, click_cpc = 0.25, click_payout = 12.5, click_lead = 0, click_filtered = 0, click_bot = 0,
                click_alp = 0, click_time = $now, rotator_id = $rotator, rule_id = $rule");
            if ($routed !== null) {
                self::q("INSERT INTO 202_clicks_rotator SET click_id = $click, rotator_id = {$routed[0]}, rule_id = {$routed[1]}, rule_redirect_id = {$routed[2]}");
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    private static function cleanUp(): void
    {
        $list = implode(',', self::CLICKS);
        foreach (['202_clicks', '202_clicks_rotator'] as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id IN ($list)");
        }
    }

    private static function q(string $sql): void
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
    }

    /** @return array<int, array<string, mixed>> click id => [rotator_id, rule_id, rule_redirect_id] */
    private static function routedInList(): array
    {
        $rows = (new ClicksController(self::$db, self::USER))->list(['limit' => '50'])['data'];
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['click_id']] = [$row['rotator_id'], $row['rule_id'], $row['rule_redirect_id']];
        }
        ksort($out);

        return $out;
    }

    public function testTheRotatorRuleAndRedirectAreTheClicksRotatorRow(): void
    {
        self::assertSame([
            971001 => [5, 3, 7],
            971002 => [5, null, null],
            971003 => [null, null, null],
            971004 => [6, 4, 9],
        ], self::routedInList());

        $one = (new ClicksController(self::$db, self::USER))->get(971001)['data'];
        self::assertSame([5, 3, 7], [$one['rotator_id'], $one['rule_id'], $one['rule_redirect_id']]);
    }

    public function testTheMoneyIsNumbers(): void
    {
        $one = (new ClicksController(self::$db, self::USER))->get(971003)['data'];
        self::assertSame([0.25, 12.5], [$one['click_cpc'], $one['click_payout']]);
    }
}
