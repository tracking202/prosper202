<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ClicksController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * GET /clicks and /clicks/{id} serve what the Visitors page shows for a
 * click — names, IP, keyword, URLs — where they served ids; a name is joined
 * only from the click's own account, and what the visitor wrote is cleaned.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 5601 and 5602's rows and
 * clicks 960001-960002.
 *
 * @group integration
 */
final class ClickDetailIntegrationTest extends TestCase
{
    private const USER = 5601;
    private const OTHER = 5602;

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
        foreach (['202_clicks', '202_clicks_advance', '202_clicks_record', '202_clicks_site'] as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id IN (960001, 960002)");
        }
        foreach ([self::USER, self::OTHER] as $u) {
            self::$db->query("DELETE FROM 202_aff_campaigns WHERE user_id = $u");
        }
    }

    private static function q(string $sql): int
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);

        return (int) self::$db->insert_id;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::cleanUp();
        $mine = self::q("INSERT INTO 202_aff_campaigns SET user_id = " . self::USER . ", aff_network_id = 1, aff_campaign_name = 'My Offer', aff_campaign_url = 'https://o.example', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0");
        $theirs = self::q("INSERT INTO 202_aff_campaigns SET user_id = " . self::OTHER . ", aff_network_id = 1, aff_campaign_name = 'Their Offer', aff_campaign_url = 'https://t.example', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0");
        $keyword = self::q("INSERT INTO 202_keywords SET keyword = 'blue " . "\u{202E}" . "shoes'");
        $ip = self::q("INSERT INTO 202_ips SET ip_address = '203.0.113.9'");
        $referer = self::q("INSERT INTO 202_site_urls SET site_domain_id = 0, site_url_address = 'https://search.example/?q=blue'");
        foreach ([[960001, $mine], [960002, $theirs]] as [$click, $campaign]) {
            self::q("INSERT INTO 202_clicks SET click_id = $click, user_id = " . self::USER . ", aff_campaign_id = $campaign, ppc_account_id = 0, click_cpc = 0, click_time = UNIX_TIMESTAMP() - $click % 10");
            self::q("INSERT INTO 202_clicks_advance SET click_id = $click, keyword_id = $keyword, ip_id = $ip, country_id = 0, region_id = 0, city_id = 0, platform_id = 0, browser_id = 0, device_id = 0");
            self::q("INSERT INTO 202_clicks_record SET click_id = $click, click_id_public = '9$click'");
            self::q("INSERT INTO 202_clicks_site SET click_id = $click, click_referer_site_url_id = $referer");
        }
    }

    public function testAClickCarriesWhatTheVisitorsPageShows(): void
    {
        $clicks = new ClicksController(self::$db, self::USER);
        $row = $clicks->get(960001)['data'];
        self::assertSame('My Offer', $row['aff_campaign_name']);
        self::assertSame('203.0.113.9', $row['ip_address']);
        self::assertSame('https://search.example/?q=blue', $row['referer']);
        self::assertStringNotContainsString("\u{202E}", (string) $row['keyword'], 'a bidi override the visitor sent is cleaned');
        self::assertStringContainsString('shoes', (string) $row['keyword']);

        $listed = array_column($clicks->list(['limit' => 50])['data'], null, 'click_id');
        self::assertSame('My Offer', $listed[960001]['aff_campaign_name'] ?? null, 'the list serves the same');
        self::assertSame('203.0.113.9', $listed[960001]['ip_address'] ?? null);
    }

    public function testANameComesOnlyFromTheClicksOwnAccount(): void
    {
        $row = (new ClicksController(self::$db, self::USER))->get(960002)['data'];
        self::assertNull($row['aff_campaign_name'], "another account's campaign is not named");
    }
}
