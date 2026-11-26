<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;

/**
 * The traffic source pixels gpb.php and upx.php render for a conversion carry
 * the click's values as stored, URL-encoded once by replaceTokens(): both
 * used to SQL-escape them first, so O'Brien reached the network as
 * O%5C%27Brien (HRR9). The referer is URL-encoded once before that, as the
 * queued postbacks have it (TrafficSourcePixels::clickTokens()). Each script
 * runs in a process of its own (fixtures/gpb-runner.php).
 *
 * Needs a 202-config.php (connect2.php's DB class lives there) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class PixelTokensKeepTheirValuesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990811;
    private const CLICK = 99081101;

    private const C1 = "O'Brien\\x";
    private const KEYWORD = 'tom\'s "best"';
    private const REFERER = "https://ref.example/?q=a'b\\c";

    private const CLICK_TABLES = ['202_clicks', '202_clicks_spy', '202_clicks_tracking', '202_clicks_advance',
        '202_clicks_site', '202_click_latest_visits', '202_conversion_logs', '202_dataengine', '202_google',
        '202_clicks_ad_ids', '202_inbound_log'];

    private static int $campaign = 0;
    private static int $network = 0;
    private static int $account = 0;
    /** @var array<string, int> the lookup rows the click names */
    private static array $lookups = [];

    public static function setUpBeforeClass(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect2.php has no DB class without one.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        self::user(self::USER);
        self::postbackAuthOff(self::USER);
        self::row('202_users_pref', ['user_id' => self::USER]);
        self::$campaign = self::insert('INSERT INTO 202_aff_campaigns SET user_id = ' . self::USER
            . ", aff_network_id = 0, aff_campaign_name = 'Store checkout', aff_campaign_url = 'https://shop.example',"
            . " aff_campaign_payout = 3.00, aff_campaign_foreign_payout = 3.00, aff_campaign_time = 0,"
            . " payout_mode = 'accumulate'");
        self::$network = self::insert('INSERT INTO 202_ppc_networks SET user_id = ' . self::USER
            . ", ppc_network_name = 'Search ads', ppc_network_time = 0");
        self::$account = self::insert('INSERT INTO 202_ppc_accounts SET user_id = ' . self::USER
            . ', ppc_network_id = ' . self::$network . ", ppc_account_name = 'Main', ppc_account_time = 0");
        self::row('202_ppc_account_pixels', ['ppc_account_id' => self::$account, 'pixel_type_id' => 1,
            'pixel_code' => 'https://ads.example/cv?c1=[[c1]]&kw=[[t202kw]]&ref=[[referer]]']);
        self::$lookups = [
            'c1' => self::insert('INSERT INTO 202_tracking_c1 SET c1 = ' . self::str(self::C1)),
            'keyword' => self::insert('INSERT INTO 202_keywords SET keyword = ' . self::str(self::KEYWORD)),
            'site_url' => self::insert('INSERT INTO 202_site_urls SET site_domain_id = 0, site_url_address = '
                . self::str(self::REFERER)),
        ];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::deleteClick();
        $columns = 'click_id = ' . self::CLICK . ', user_id = ' . self::USER . ', aff_campaign_id = ' . self::$campaign
            . ', landing_page_id = 0, ppc_account_id = ' . self::$account . ', click_cpc = 0.25, click_payout = 3.00,'
            . ' click_lead = 0, click_time = ' . (time() - 600);
        self::q("INSERT INTO 202_clicks SET $columns");
        self::q("INSERT INTO 202_clicks_spy SET $columns");
        self::q('INSERT INTO 202_clicks_tracking SET click_id = ' . self::CLICK . ', c1_id = ' . self::$lookups['c1']
            . ', c2_id = 0, c3_id = 0, c4_id = 0');
        self::row('202_clicks_advance', ['click_id' => self::CLICK, 'keyword_id' => self::$lookups['keyword'],
            'ip_id' => 0, 'country_id' => 0, 'region_id' => 0, 'city_id' => 0, 'platform_id' => 0, 'browser_id' => 0,
            'device_id' => 0]);
        self::row('202_clicks_site', [
            'click_id' => self::CLICK,
            'click_referer_site_url_id' => self::$lookups['site_url'],
        ]);
        self::row('202_google', ['click_id' => self::CLICK, 'gclid' => '', 'utm_source_id' => 0,
            'utm_medium_id' => 0, 'utm_campaign_id' => 0, 'utm_term_id' => 0, 'utm_content_id' => 0]);
        LatestVisit::refresh(new Connection(self::$db), self::CLICK);
    }

    private static function deleteClick(): void
    {
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN'
            . ' (SELECT conv_id FROM 202_conversion_logs WHERE click_id = ' . self::CLICK . ')');
        foreach (self::CLICK_TABLES as $table) {
            self::q("DELETE FROM $table WHERE click_id = " . self::CLICK);
        }
        self::q('DELETE FROM 202_inbound_log WHERE user_id IN (0, ' . self::USER . ')');
    }

    private static function cleanUp(): void
    {
        self::deleteClick();
        foreach (self::$lookups as $what => $id) {
            [$table, $key] = ['c1' => ['202_tracking_c1', 'c1_id'], 'keyword' => ['202_keywords', 'keyword_id'],
                'site_url' => ['202_site_urls', 'site_url_id']][$what];
            self::q("DELETE FROM $table WHERE $key = $id");
        }
        if (self::$account > 0) {
            self::q('DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id = ' . self::$account);
        }
        $tables = ['202_aff_campaigns', '202_ppc_accounts', '202_ppc_networks', '202_revenue_events', '202_dirty_hours',
            '202_postback_auth', '202_users_pref', '202_users'];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::USER);
        }
    }

    /**
     * @param array<string, string> $get
     * @return array{int, string} status and body
     */
    private static function runScript(string $script, array $get): array
    {
        $env = getenv();
        $env['P202_TEST_SCRIPT'] = $script;
        $env['P202_TEST_GET'] = json_encode((object) $get, JSON_THROW_ON_ERROR);
        $env['P202_TEST_POST'] = '{}';
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', __DIR__ . '/fixtures/gpb-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertSame(0, $exit, "$script exited $exit: $err\n$out");
        [$status, $body] = explode("\n", $out, 2) + [1 => ''];

        return [(int) $status, $body];
    }

    /** @return iterable<string, array{string}> */
    public static function scripts(): iterable
    {
        yield 'the global postback' => ['gpb.php'];
        yield 'the universal pixel' => ['upx.php'];
    }

    /** @dataProvider scripts */
    public function testTheNetworkIsSentTheValuesAsStored(string $script): void
    {
        [$status, $body] = self::runScript($script, [
            'subid' => (string) self::CLICK,
            'amount' => '12.50',
            'txid' => 'ord-1',
        ]);

        self::assertLessThan(300, $status, $body);
        self::assertSame(1, (int) self::$db->query('SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id = '
            . self::CLICK)->fetch_row()[0], 'the conversion was recorded');
        $referer = (string) TrafficSourcePixels::encode(urlencode(self::REFERER));
        self::assertStringContainsString(
            "<img src='https://ads.example/cv?c1=O%27Brien%5Cx&amp;kw=tom%27s%20%22best%22&amp;ref=$referer'",
            $body
        );
        self::assertStringNotContainsString('%5C%27', $body, 'no value reaches the network SQL-escaped');
    }

    public function testThePixelsAndTheQueuedPostbacksAreSentOneReferer(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/static-endpoint-helpers.php';
        $queued = TrafficSourcePixels::clickTokens(new Connection(self::$db), self::USER, self::CLICK);
        self::assertNotNull($queued);
        $tokens = p202ConversionPixelTokens(self::CLICK, [
            'c1' => self::C1, 'keyword' => self::KEYWORD, 'site_url_address' => self::REFERER,
        ], \Prosper202\Click\AdClickIdColumns::of(new Connection(self::$db), self::CLICK), '0.25000', '3.00');

        foreach (['c1', 't202kw', 'referer', 'subid', 'gclid', 'cpc', 'cpc2'] as $name) {
            self::assertSame($queued['tokens'][$name], $tokens[$name], $name);
        }
    }
}
