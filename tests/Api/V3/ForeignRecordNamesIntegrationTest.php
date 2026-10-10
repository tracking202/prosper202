<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ConversionsController;
use Api\V3\Controllers\SetupCodeController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\ClickBreakdown;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Goals\MysqlGoalRepository;
use Prosper202\Identity\ClickIdentity;
use Prosper202\Ltv\LtvQuery;
use Prosper202\Ltv\MysqlEngagementRepository;
use Prosper202\Ltv\MysqlLtvRepository;
use Prosper202\Ltv\MysqlRecommendationRepository;
use Prosper202\Report\MysqlReportRepository;
use Prosper202\Report\ReportQuery;
use Prosper202\Update\CpcUpdate;
use Prosper202\Update\SubidBatch;

/**
 * A row of this account that names another account's Setup record — a
 * click whose tracker named another account's campaign, traffic source or
 * landing page before the API checked linked ids (229df10) — is read as
 * naming nothing: the other account's names, URLs, payouts and settings
 * never reach this account's reads or decide its writes, and the row is
 * still counted where it was counted before (e2f274f did it for GET
 * /clicks, 00e1fb6 for the reports; AccountScopedJoinTest keeps the tree
 * that way).
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 5911 and 5912's rows and
 * clicks 975001-975004.
 *
 * @group integration
 */
final class ForeignRecordNamesIntegrationTest extends TestCase
{
    private const USER = 5911;
    private const OTHER = 5912;
    private const CLICKS = [975001, 975002, 975003, 975004];

    private static ?\mysqli $db = null;

    /** @var array<string, int> */
    private array $id = [];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {} public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
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
        require_once dirname(__DIR__, 3) . '/202-config/functions-ui.php';
        require_once dirname(__DIR__, 3) . '/tracking202/setup/_includes/setup_ui.php';
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
        $clicks = implode(',', self::CLICKS);
        foreach (['202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_record', '202_clicks_tracking', '202_dataengine', '202_clicks_visitor', '202_identity_observations'] as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        self::$db->query("DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($clicks))");
        foreach ([self::USER, self::OTHER] as $u) {
            foreach ([
                '202_conversion_logs', '202_revenue_events', '202_revenue_line_items', '202_customers', '202_customer_aliases',
                '202_offer_transitions', '202_offer_recommendations', '202_campaign_goals', '202_identity_keys', '202_identity_visitors',
                '202_identity_signals', '202_clicks_visitor', '202_users_pref',
                '202_text_ads', '202_landing_pages', '202_aff_campaigns', '202_aff_networks', '202_ppc_accounts', '202_ppc_networks',
            ] as $table) {
                self::$db->query("DELETE FROM $table WHERE user_id = $u");
            }
        }
    }

    private static function q(string $sql): int
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);

        return (int) self::$db->insert_id;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $sql): array
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, $sql);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /** One of each Setup record for $user, named $prefix …; the campaign's payout terms are given. */
    private static function records(int $user, string $prefix, string $mode, string $payout): array
    {
        $network = self::q("INSERT INTO 202_aff_networks SET user_id = $user, aff_network_name = '$prefix Category', aff_network_time = 0");
        $campaign = self::q("INSERT INTO 202_aff_campaigns SET user_id = $user, aff_network_id = $network, aff_campaign_name = '$prefix Offer',
            aff_campaign_url = 'https://" . strtolower($prefix) . ".example/offer', aff_campaign_payout = $payout, aff_campaign_foreign_payout = 0,
            aff_campaign_time = 0, payout_mode = '$mode', identity_signals = " . ($prefix === 'Their' ? 0 : 1));
        $source = self::q("INSERT INTO 202_ppc_networks SET user_id = $user, ppc_network_name = '$prefix Network', ppc_network_time = 0");
        $account = self::q("INSERT INTO 202_ppc_accounts SET user_id = $user, ppc_network_id = $source, ppc_account_name = '$prefix Source', ppc_account_time = 0");
        $lp = self::q("INSERT INTO 202_landing_pages SET user_id = $user, aff_campaign_id = $campaign, landing_page_url = 'https://" . strtolower($prefix) . ".example/lp',
            landing_page_nickname = '$prefix LP', landing_page_time = 0");
        $ad = self::q("INSERT INTO 202_text_ads SET user_id = $user, aff_campaign_id = $campaign, landing_page_id = 0, text_ad_name = '$prefix Ad',
            text_ad_headline = 'h', text_ad_description = 'd', text_ad_display_url = 'x', text_ad_time = 0");

        return ['network' => $network, 'campaign' => $campaign, 'source' => $source, 'account' => $account, 'lp' => $lp, 'ad' => $ad];
    }

    private static function click(int $clickId, int $campaign, int $account, int $lp, int $ad, string $payout, int $time): void
    {
        $u = self::USER;
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            self::q("INSERT INTO $table SET click_id = $clickId, user_id = $u, aff_campaign_id = $campaign, ppc_account_id = $account,
                landing_page_id = $lp, click_cpc = 0.10, click_payout = $payout, click_lead = 0, click_time = $time");
        }
        self::q("INSERT INTO 202_clicks_advance SET click_id = $clickId, text_ad_id = $ad, keyword_id = 0, ip_id = 0, country_id = 0,
            region_id = 0, city_id = 0, platform_id = 0, browser_id = 0, device_id = 0");
        self::q("INSERT INTO 202_dataengine SET click_id = $clickId, user_id = $u, click_time = $time, aff_campaign_id = $campaign,
            ppc_account_id = $account, landing_page_id = $lp, text_ad_id = $ad, clicks = 1, payout = 0");
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::$db->query("SET SESSION sql_mode=''");
        self::cleanUp();
        $mine = self::records(self::USER, 'My', 'replace', '1.00');
        $theirs = self::records(self::OTHER, 'Their', 'accumulate', '9.00');
        $this->id = [
            'my_network' => $mine['network'], 'my_campaign' => $mine['campaign'], 'my_source' => $mine['source'],
            'their_network' => $theirs['network'], 'their_campaign' => $theirs['campaign'],
        ];
        $now = time();
        // 975001 names the other account's campaign, source, landing page
        // and text ad; 975002 is all its own.
        self::click(975001, $theirs['campaign'], $theirs['account'], $theirs['lp'], $theirs['ad'], '0.50', $now - 3600);
        self::click(975002, $mine['campaign'], $mine['account'], $mine['lp'], $mine['ad'], '1.00', $now - 1800);
        self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    }

    private function conn(): Connection
    {
        return new Connection(self::$db);
    }

    /** @return array<string, mixed> the conversion recorded on $clickId */
    private function convert(int $clickId, ?string $payout, string $tx): array
    {
        $data = ['click_id' => $clickId, 'transaction_id' => $tx, 'source' => 'api'];
        if ($payout !== null) {
            $data['payout'] = $payout;
        }
        $recorded = (new MysqlConversionRepository($this->conn()))->record(self::USER, $data);
        self::assertTrue($recorded['clickFound']);

        return self::rows('SELECT * FROM 202_conversion_logs WHERE conv_id = ' . (int) $recorded['convId'])[0];
    }

    public function testAConversionIsNamedOnlyFromItsOwnAccountsCampaign(): void
    {
        $foreign = $this->convert(975001, '2.00', 'foreign');
        $own = $this->convert(975002, '3.00', 'own');

        $api = new ConversionsController(self::$db, self::USER);
        $listed = array_column($api->list([])['data'], null, 'conv_id');
        self::assertCount(2, $listed, 'both conversions are listed');
        self::assertSame(2, $api->list([])['pagination']['total']);
        self::assertNull($listed[$foreign['conv_id']]['aff_campaign_name'], "another account's campaign is not named");
        self::assertSame('My Offer', $listed[$own['conv_id']]['aff_campaign_name']);
        self::assertNull($api->get((int) $foreign['conv_id'])['data']['aff_campaign_name']);
        self::assertSame((string) $this->id['their_campaign'], (string) $api->get((int) $foreign['conv_id'])['data']['campaign_id'], 'the stored id is served as stored');

        $repo = new MysqlConversionRepository($this->conn());
        $rows = array_column($repo->list(self::USER, [], 0, 50)['rows'], null, 'conv_id');
        self::assertNull($rows[$foreign['conv_id']]['aff_campaign_name']);
        self::assertSame('My Offer', $rows[$own['conv_id']]['aff_campaign_name']);
        self::assertNull($repo->findById((int) $foreign['conv_id'], self::USER)['aff_campaign_name']);
    }

    public function testAConversionIsNotValuedByAnotherAccountsCampaign(): void
    {
        // The other account's campaign accumulates and pays 9.00; a
        // conversion with no payout of its own takes this account's terms,
        // and a click with no campaign of its own replaces: the click's
        // own value, 0.50.
        $row = $this->convert(975001, null, 'no-payout');
        self::assertSame('0.50000', (string) $row['click_payout'], "another account's campaign payout was recorded");
        $click = self::rows('SELECT click_lead, click_payout FROM 202_clicks WHERE click_id = 975001')[0];
        self::assertSame(['1', '0.50000'], [(string) $click['click_lead'], (string) $click['click_payout']]);

        $breakdown = (new ClickBreakdown($this->conn()))->forClick(975001, self::USER);
        self::assertNotNull($breakdown);
        self::assertNull($breakdown['click']['campaign_name'], "the breakdown names no campaign of another account");
        self::assertSame('replace', $breakdown['click']['payout_mode'], "the click is explained by this account's terms");
        self::assertStringNotContainsString('Their', (string) json_encode($breakdown));
        self::assertCount(1, $breakdown['rows'], 'the row is listed');
    }

    public function testAClickNamesNoTrafficSourceOfAnotherAccountsThroughItsOwnAccount(): void
    {
        // This account's traffic-source account filed under the other
        // account's traffic source (a write could before 229df10).
        $u = self::USER;
        $theirSource = (int) self::rows('SELECT ppc_network_id FROM 202_ppc_networks WHERE user_id = ' . self::OTHER)[0]['ppc_network_id'];
        $account = self::q("INSERT INTO 202_ppc_accounts SET user_id = $u, ppc_network_id = $theirSource, ppc_account_name = 'My Source On Their Network', ppc_account_time = 0");
        self::$db->query("SET SESSION sql_mode=''");
        self::click(975004, $this->id['my_campaign'], $account, 0, 0, '1.00', time() - 60);
        self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        $row = (new \Api\V3\Controllers\ClicksController(self::$db, $u))->get(975004)['data'];
        self::assertSame('My Source On Their Network', $row['ppc_account_name'], 'the account\'s own source account is named');
        self::assertNull($row['ppc_network_name'], "another account's traffic source is not named");
        $listed = array_column((new \Api\V3\Controllers\ClicksController(self::$db, $u))->list(['limit' => 50])['data'], null, 'click_id');
        self::assertArrayHasKey(975004, $listed, 'the click is listed');
        self::assertNull($listed[975004]['ppc_network_name'], 'the list serves the same');
    }

    public function testGoalPayoutsAreOnlyTheAccountsOwn(): void
    {
        $goals = new MysqlGoalRepository($this->conn());
        self::q('INSERT INTO 202_campaign_goals SET campaign_id = ' . $this->id['their_campaign'] . ', goal_id = 1, user_id = ' . self::OTHER
            . ', payout = 5, notify_traffic_source = 1, created_at = 1, updated_at = 1');
        self::assertSame([], $goals->campaignTerms($this->id['their_campaign'], self::USER), "another account's goal terms do not pay this account's click");
        self::assertSame([1], array_keys($goals->campaignTerms($this->id['their_campaign'], self::OTHER)));
    }

    public function testTheReportRepositoryNamesNoOtherAccountsRecord(): void
    {
        $repo = new MysqlReportRepository($this->conn());
        foreach (['campaign' => 'Their Offer', 'ppc_account' => 'Their Source', 'landing_page' => 'https://their.example/lp', 'text_ad' => 'Their Ad'] as $dimension => $theirs) {
            $names = array_column($repo->breakdown(new ReportQuery(self::USER), $dimension), 'name');
            self::assertNotContains($theirs, $names, $dimension);
            self::assertCount(1, $names, "$dimension: the account's own row is still there");
        }
        self::assertSame('2', (string) $repo->summary(new ReportQuery(self::USER))['total_clicks'], 'both clicks are counted');
    }

    public function testLtvReadsNoOtherAccountsNamesOrOffers(): void
    {
        $now = time();
        $u = self::USER;
        $foreign = self::q("INSERT INTO 202_customers SET user_id = $u, primary_ref = 'f', company = 'Acme', first_seen_time = $now, last_activity_time = $now,
            first_click_id = 975001, created_at = $now, updated_at = $now, order_count = 1, total_revenue = 5");
        $own = self::q("INSERT INTO 202_customers SET user_id = $u, primary_ref = 'o', company = 'Acme', first_seen_time = $now, last_activity_time = $now,
            first_click_id = 975002, created_at = $now, updated_at = $now, order_count = 1, total_revenue = 7");
        foreach ([975001 => $foreign, 975002 => $own] as $click => $customer) {
            self::q("INSERT INTO 202_clicks_tracking SET click_id = $click, c1_id = 0, c2_id = 0, c3_id = 0, c4_id = 0, customer_id = $customer");
        }

        $ltv = new MysqlLtvRepository($this->conn());
        foreach (['campaign' => ['Their Offer', 'My Offer'], 'ppc_account' => ['Their Source', 'My Source'], 'landing_page' => ['https://their.example/lp', 'https://my.example/lp']] as $by => [$theirs, $mine]) {
            $names = array_column($ltv->breakdown(new LtvQuery($u), $by, 50, 0), 'name');
            self::assertNotContains($theirs, $names, $by);
            self::assertContains($mine, $names, $by);
        }

        $engagement = new MysqlEngagementRepository($this->conn());
        $rows = $engagement->customerEngagement($u, $foreign);
        self::assertCount(1, $rows, 'the click is counted');
        self::assertSame([null, null], [$rows[0]['campaign_name'], $rows[0]['landing_page']], "another account's campaign and landing page are not named");

        // The newest click names the other account's campaign, and a click
        // of the other account carries this account's customer.
        self::$db->query('UPDATE 202_clicks SET click_time = UNIX_TIMESTAMP() - 60 WHERE click_id = 975001');
        $o = self::OTHER;
        self::q("INSERT INTO 202_clicks SET click_id = 975004, user_id = $o, aff_campaign_id = " . $this->id['their_campaign'] . ",
            ppc_account_id = 0, landing_page_id = 0, click_cpc = 0, click_payout = 0, click_lead = 0, click_time = UNIX_TIMESTAMP() - 30");
        self::q("INSERT INTO 202_clicks_tracking SET click_id = 975004, c1_id = 0, c2_id = 0, c3_id = 0, c4_id = 0, customer_id = $own");
        $abm = array_column($engagement->abmBreakdown($u), null, 'company');
        self::assertSame('2', (string) $abm['Acme']['engagements'], "both of the account's clicks are engagements, and only they");
        self::assertSame('My Offer', $abm['Acme']['top_campaign_name'], "the newest click's campaign is another account's: never named");
        self::assertSame(2, array_sum(array_map('intval', array_column($engagement->abmCompanyDetail($u, 'Acme'), 'engagements'))));
    }

    public function testNoOtherAccountsOfferIsRecommended(): void
    {
        $now = time();
        $u = self::USER;
        $customer = self::q("INSERT INTO 202_customers SET user_id = $u, primary_ref = 'r', first_seen_time = $now, last_activity_time = $now,
            created_at = $now, updated_at = $now");
        $recommend = new MysqlRecommendationRepository($this->conn());

        // 3. The account's top converting campaign: two conversions on the
        //    click naming the other account's campaign, one on its own.
        $this->convert(975001, '1.00', 'r1');
        $this->convert(975001, '1.00', 'r2');
        $this->convert(975002, '1.00', 'r3');
        $pick = $recommend->nextOffer($u, $customer, $now);
        self::assertSame('My Offer', $pick['name'] ?? null, "the account's top campaign is its own, never another account's");

        // 2. What the customer browsed: a stamped click on the other
        //    account's campaign is no interest in an offer of this one's.
        self::q("INSERT INTO 202_clicks_tracking SET click_id = 975001, c1_id = 0, c2_id = 0, c3_id = 0, c4_id = 0, customer_id = $customer");
        $pick = $recommend->nextOffer($u, $customer, $now);
        self::assertNotSame('Their Offer', $pick['name'] ?? null);
        self::assertStringNotContainsString('their.example', (string) ($pick['url'] ?? ''));

        // 1. A transition the account's own ledger would never produce, to
        //    the other account's campaign, from one the customer bought.
        $conv = $this->convert(975002, '4.00', 'bought');
        self::q("INSERT INTO 202_revenue_events SET user_id = $u, customer_id = $customer, event_type = 'purchase', amount = 4, occurred_at = $now,
            source = 'conversion', conv_id = " . (int) $conv['conv_id'] . ", created_at = $now");
        self::q("INSERT INTO 202_offer_transitions SET user_id = $u, from_campaign_id = " . $this->id['my_campaign'] . ', to_campaign_id = '
            . $this->id['their_campaign'] . ", transition_count = 50, adjacent_count = 50, from_customers = 50, last_seen_at = $now, updated_at = $now");
        $pick = $recommend->nextOffer($u, $customer, $now);
        self::assertNotSame('Their Offer', $pick['name'] ?? null, "another account's offer is never a transition target");
    }

    public function testTheIdentitySettingIsTheClicksOwnAccounts(): void
    {
        // The other account's campaign has identity capture off; that is
        // not this account's setting, so a click naming it links as a click
        // with no campaign does.
        $key = ClickIdentity::trustedCustomer('cust-5911')->attachToStoredClick($this->conn(), 975001);
        self::assertNotNull($key, "another account's identity setting decided this account's click");
    }

    public function testAnotherAccountsCampaignDecidesNoBulkUpdate(): void
    {
        // The other account's campaign filed under this account's category,
        // and its traffic-source account under this account's traffic
        // source (a write could before 229df10), and a click of this
        // account on both.
        $o = self::OTHER;
        $inMine = self::q("INSERT INTO 202_aff_campaigns SET user_id = $o, aff_network_id = " . $this->id['my_network'] . ", aff_campaign_name = 'Their Offer In My Category',
            aff_campaign_url = 'https://their.example/x', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0");
        $accountInMine = self::q("INSERT INTO 202_ppc_accounts SET user_id = $o, ppc_network_id = " . $this->id['my_source'] . ", ppc_account_name = 'Their Source In My Network', ppc_account_time = 0");
        self::$db->query("SET SESSION sql_mode=''");
        self::click(975003, $inMine, $accountInMine, 0, 0, '1.00', time() - 900);
        self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        foreach (['aff_network_id' => $this->id['my_network'], 'ppc_network_id' => $this->id['my_source']] as $field => $id) {
            $parsed = CpcUpdate::parse([
                $field => (string) $id, 'cpc' => '0.20', 'method_of_promotion' => '',
                'from' => date('Y-m-d', time() - 86400), 'to' => date('Y-m-d', time() + 86400),
            ]);
            self::assertSame([], $parsed['errors']);
            $preview = CpcUpdate::preview($this->conn(), $parsed['values'], self::USER);
            self::assertSame(1, $preview['matching'], "$field: only the click on the account's own record is under it");
        }

        $this->convert(975002, '1.00', 'reset-own');
        $this->convert(975003, '1.00', 'reset-foreign');
        $cleared = array_column((new SubidBatch($this->conn()))->resetClicks(self::USER, $this->id['my_network'], 0), 'click_id');
        self::assertSame([975002], array_map('intval', $cleared), "a reset of the category clears only its own campaigns' sales");
    }

    public function testACampaignInAnotherAccountsCategoryIsNotOffered(): void
    {
        $u = self::USER;
        $campaign = self::q("INSERT INTO 202_aff_campaigns SET user_id = $u, aff_network_id = " . $this->id['their_network'] . ", aff_campaign_name = 'Mine In Their Category',
            aff_campaign_url = 'https://my.example/y', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0");

        $groups = p202_setup_campaign_options(self::$db, $u);
        self::assertNotContains('Their Category', array_column($groups, 'label'), "another account's category is not named");
        self::assertContains('My Category', array_column($groups, 'label'));

        try {
            (new SetupCodeController(self::$db, $u))->postbackCode(['campaign_id' => (string) $campaign], [
                'SERVER_NAME' => 'server.example', 'SERVER_PORT' => 443, 'HTTPS' => 'on', 'DOCUMENT_ROOT' => dirname(__DIR__, 3),
            ]);
            self::fail('a campaign in another account\'s category was offered postback code');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('campaign_id', $e->getFieldErrors());
        }
    }
}
