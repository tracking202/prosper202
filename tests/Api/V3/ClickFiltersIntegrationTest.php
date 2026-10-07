<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ClicksController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * GET /clicks takes the Visitors page's filters, as the reports take the
 * Analyze pages': keyword and referer "contains", one IP, device type,
 * text ad, location, browser and platform, traffic source and category,
 * direct link or landing page, and show=real|filtered|filtered_bot|leads,
 * with the named periods. It took three ids and a time window, and ignored
 * anything else, so `keyword=shoes` answered every click.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes user 5901's rows and clicks
 * 970001-970006.
 *
 * @group integration
 */
final class ClickFiltersIntegrationTest extends TestCase
{
    private const USER = 5901;
    private const CLICKS = [970001, 970002, 970003, 970004, 970005, 970006];

    private static ?\mysqli $db = null;

    /** @var array<string, int> */
    private static array $ids = [];

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
        $list = implode(',', self::CLICKS);
        foreach (['202_clicks', '202_clicks_advance', '202_clicks_record', '202_clicks_site'] as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id IN ($list)");
        }
        foreach (['202_aff_campaigns', '202_aff_networks', '202_ppc_accounts', '202_ppc_networks'] as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id = " . self::USER);
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
        $u = self::USER;
        $netA = self::q("INSERT INTO 202_aff_networks SET user_id = $u, aff_network_name = 'cat a', aff_network_time = 0");
        $netB = self::q("INSERT INTO 202_aff_networks SET user_id = $u, aff_network_name = 'cat b', aff_network_time = 0");
        $campA = self::q("INSERT INTO 202_aff_campaigns SET user_id = $u, aff_network_id = $netA, aff_campaign_name = 'A', aff_campaign_url = 'https://a.example', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0");
        $campB = self::q("INSERT INTO 202_aff_campaigns SET user_id = $u, aff_network_id = $netB, aff_campaign_name = 'B', aff_campaign_url = 'https://b.example', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0");
        $source = self::q("INSERT INTO 202_ppc_networks SET user_id = $u, ppc_network_name = 'src', ppc_network_time = 0");
        $account = self::q("INSERT INTO 202_ppc_accounts SET user_id = $u, ppc_network_id = $source, ppc_account_name = 'acct', ppc_account_time = 0");
        $shoes = self::q("INSERT INTO 202_keywords SET keyword = 'blue shoes 50%'");
        $hats = self::q("INSERT INTO 202_keywords SET keyword = 'red hats'");
        $ipA = self::q("INSERT INTO 202_ips SET ip_address = '198.51.100.7'");
        $ipB = self::q("INSERT INTO 202_ips SET ip_address = '198.51.100.8'");
        $refNews = self::q("INSERT INTO 202_site_urls SET site_domain_id = 0, site_url_address = 'https://news.example/story'");
        $refSearch = self::q("INSERT INTO 202_site_urls SET site_domain_id = 0, site_url_address = 'https://search.example/?q=x'");
        $mobileType = (int) self::$db->query("SELECT type_id FROM 202_device_types WHERE type_name = 'Mobile' LIMIT 1")->fetch_row()[0];
        self::assertGreaterThan(0, $mobileType, 'the schema seeds device types');
        $phone = self::q("INSERT INTO 202_device_models SET device_name = 'cf-phone', device_type = $mobileType");
        self::$ids = compact('netA', 'netB', 'campA', 'campB', 'source', 'account', 'phone') + ['mobileType' => $mobileType];

        // click => [campaign, account, landing page, keyword, ip, referer, device, filtered, bot, lead, days ago]
        $rows = [
            970001 => [$campA, $account, 0, $shoes, $ipA, $refNews, $phone, 0, 0, 1, 0],
            970002 => [$campA, $account, 7, $hats, $ipB, $refSearch, 0, 0, 0, 0, 0],
            970003 => [$campB, 0, 0, $shoes, $ipB, $refSearch, 0, 1, 0, 0, 0],
            970004 => [$campB, 0, 7, $hats, $ipA, $refNews, $phone, 1, 1, 0, 0],
            970005 => [$campA, $account, 0, $hats, $ipB, 0, 0, 0, 0, 0, 3],
            970006 => [$campB, 0, 0, $hats, $ipB, 0, 0, 0, 0, 0, 400],
        ];
        foreach ($rows as $click => [$camp, $acct, $lp, $kw, $ip, $ref, $device, $filtered, $bot, $lead, $daysAgo]) {
            $time = time() - $daysAgo * 86400 - 60;
            self::q("INSERT INTO 202_clicks SET click_id = $click, user_id = $u, aff_campaign_id = $camp, ppc_account_id = $acct, landing_page_id = $lp, click_cpc = 0, click_filtered = $filtered, click_bot = $bot, click_lead = $lead, click_time = $time");
            self::q("INSERT INTO 202_clicks_advance SET click_id = $click, keyword_id = $kw, ip_id = $ip, country_id = 0, region_id = 0, city_id = 0, platform_id = 0, browser_id = 0, device_id = $device, text_ad_id = 0, isp_id = 0");
            self::q("INSERT INTO 202_clicks_record SET click_id = $click, click_id_public = '8$click'");
            self::q("INSERT INTO 202_clicks_site SET click_id = $click, click_referer_site_url_id = $ref");
        }
    }

    /** @return list<int> the clicks, oldest id first, and the total the page reports */
    private function clicks(array $params): array
    {
        $result = (new ClicksController(self::$db, self::USER))->list($params + ['limit' => '500']);
        $ids = array_map('intval', array_column($result['data'], 'click_id'));
        sort($ids);
        self::assertSame(count($ids), (int) $result['pagination']['total'], 'the total counts the same clicks as the page: ' . json_encode($params));

        return $ids;
    }

    public function testEachVisitorsFilterNarrowsToItsClicks(): void
    {
        $i = self::$ids;
        $cases = [
            'keyword contains, % literal' => [['keyword' => 'shoes 50%'], [970001, 970003]],
            'keyword with no match'       => [['keyword' => 'shoes 5_%'], []],
            'one ip'                      => [['ip' => '198.51.100.7'], [970001, 970004]],
            'referer contains'            => [['referer' => 'news.example'], [970001, 970004]],
            'device type'                 => [['device_type' => (string) $i['mobileType']], [970001, 970004]],
            'category'                    => [['aff_network_id' => (string) $i['netB']], [970003, 970004, 970006]],
            'traffic source'              => [['ppc_network_id' => (string) $i['source']], [970001, 970002, 970005]],
            'direct link'                 => [['method_of_promotion' => 'directlink'], [970001, 970003, 970005, 970006]],
            'landing page'                => [['method_of_promotion' => 'landingpage'], [970002, 970004]],
            'show real'                   => [['show' => 'real'], [970001, 970002, 970005, 970006]],
            'show filtered'               => [['show' => 'filtered'], [970003, 970004]],
            'show filtered_bot'           => [['show' => 'filtered_bot'], [970004]],
            'show leads'                  => [['show' => 'leads'], [970001]],
            'a period'                    => [['period' => 'last7'], [970001, 970002, 970003, 970004, 970005]],
            'all time'                    => [['period' => 'alltime'], self::CLICKS],
            'two filters'                 => [['keyword' => 'hats', 'show' => 'real', 'period' => 'last7'], [970002, 970005]],
            'the old switches'            => [['click_bot' => '1'], [970004]],
        ];
        foreach ($cases as $name => [$params, $want]) {
            self::assertSame($want, $this->clicks($params), $name);
        }
    }

    public function testAMisspeltOrMalformedParameterIsRefusedByName(): void
    {
        $refused = [
            [['keywrod' => 'shoes'], 'keywrod'],
            [['show' => 'everything'], 'show'],
            [['limit' => '0'], 'limit'],
            [['limit' => '501'], 'limit'],
            [['offset' => '-1'], 'offset'],
            [['click_lead' => 'yes'], 'click_lead'],
            [['ip' => 'not-an-ip'], 'ip'],
            [['period' => 'lastweek'], 'period'],
            [['keyword' => ['a', 'b']], 'keyword'],
        ];
        foreach ($refused as [$params, $field]) {
            try {
                (new ClicksController(self::$db, self::USER))->list($params);
                self::fail(json_encode($params) . ' was answered');
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->getFieldErrors(), json_encode($params) . ' refused for the wrong field: ' . json_encode($e->getFieldErrors()));
            }
        }
    }
}
