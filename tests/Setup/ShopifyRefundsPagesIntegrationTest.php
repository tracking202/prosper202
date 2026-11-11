<?php

declare(strict_types=1);

namespace Tests\Setup;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\Receiver;
use Prosper202\PaymentNotification\Shopify\ShopifyAdapter;
use Prosper202\PaymentNotification\Shopify\ShopifyWebhookSecret;
use Tests\PaymentNotification\ShopifyAdapterTest;

/**
 * Setup › Inbound Log's Shopify refunds panel and the Shopify card on
 * Account › API integrations, each run as the account in a process of its
 * own (fixtures/page-runner.php), over rows the real webhook receiver made.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class ShopifyRefundsPagesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990531;
    private const OTHER = 990532;
    private const ROLE = 990531;
    private const CLICK = 99053101;
    private const CAMPAIGN = 990581;
    private const SECRET = 'demo-store-webhook-secret-0031';
    private const LOG = 'tracking202/setup/inbound_log.php';
    private const CARD = '202-account/api-integrations.php';

    private const CLICK_TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_rotator',
        '202_clicks_record', '202_clicks_site', '202_attribution_rollup_dirty_clicks', '202_dataengine',
        '202_click_latest_visits',
    ];

    private static string $sessions = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var list<int> */
    private static array $addedPermissions = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any page ran.'
                . ' tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        require_once $root . '/202-config/static-endpoint-helpers.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        foreach ([self::USER, self::OTHER] as $user) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_account_currency = 'USD'");
        }
        self::q("UPDATE 202_users SET p202_customer_api_key = 'page-runner', install_hash = 'h"
            . self::USER . "' WHERE user_id = " . self::USER);
        self::row('202_roles', ['role_id' => self::ROLE, 'role_name' => 'shopify refunds test']);
        foreach (['access_to_api_integrations', 'access_to_setup_section'] as $name) {
            $grant = "permission_description = '$name'";
            $found = self::$db->query("SELECT permission_id FROM 202_permissions WHERE $grant");
            $permission = $found instanceof \mysqli_result ? ($found->fetch_row()[0] ?? null) : null;
            if ($permission === null) {
                $permission = self::insert("INSERT INTO 202_permissions SET $grant");
                self::$addedPermissions[] = $permission;
            }
            self::row('202_role_permission', ['role_id' => self::ROLE, 'permission_id' => (int) $permission]);
        }
        self::row('202_user_role', ['user_id' => self::USER, 'role_id' => self::ROLE]);
        self::q('INSERT INTO 202_aff_campaigns SET aff_campaign_id = ' . self::CAMPAIGN . ', user_id = ' . self::USER
            . ", aff_network_id = 1, aff_campaign_name = 'Demo coffee store',"
            . " aff_campaign_url = 'https://demo-coffee.example/products/beans',"
            . ' aff_campaign_payout = 0, aff_campaign_time = 1, aff_campaign_foreign_payout = 0,'
            . " payout_mode = 'accumulate'");
        self::$sessions = sys_get_temp_dir() . '/p202-uo-shopify-pages-' . getmypid();
        if (!is_dir(self::$sessions) && !mkdir(self::$sessions, 0700)) {
            throw new \RuntimeException('could not create ' . self::$sessions);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            foreach (self::$addedPermissions as $permission) {
                self::q('DELETE FROM 202_permissions WHERE permission_id = ' . $permission);
            }
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
        if (self::$sessions !== '' && is_dir(self::$sessions)) {
            array_map('unlink', glob(self::$sessions . '/sess_*') ?: []);
            rmdir(self::$sessions);
        }
    }

    protected function setUp(): void
    {
        $db = self::requireScratch();
        self::cleanRows();
        self::q('UPDATE 202_users_pref SET ' . ShopifyWebhookSecret::COLUMN . ' = ' . self::str(self::SECRET)
            . ' WHERE user_id = ' . self::USER);
        (new MysqlClickRepository(new Connection($db)))->recordClick(new ClickRecord(
            clickId: self::CLICK,
            userId: self::USER,
            clickTime: strtotime('2026-11-01T14:00:00Z'),
            affCampaignId: self::CAMPAIGN,
            clickPayout: '0',
            recordsCampaignRows: false,
        ));
    }

    private static function cleanRows(): void
    {
        $users = self::USER . ', ' . self::OTHER;
        $range = 'BETWEEN ' . self::CLICK . ' AND ' . (self::CLICK + 99);
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN'
            . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id $range)");
        foreach ([...self::CLICK_TABLES, '202_conversion_logs'] as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        self::q("DELETE FROM 202_shopify_pending_refunds WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_shopify_refund_transactions WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_inbound_log WHERE endpoint = 'shopify' AND user_id IN (0, $users)");
    }

    private static function cleanUp(): void
    {
        self::cleanRows();
        foreach ([self::USER, self::OTHER] as $u) {
            foreach (['202_aff_campaigns', '202_user_role', '202_users_pref', '202_users'] as $table) {
                self::q("DELETE FROM $table WHERE user_id = $u");
            }
        }
        self::q('DELETE FROM 202_role_permission WHERE role_id = ' . self::ROLE);
        self::q('DELETE FROM 202_roles WHERE role_id = ' . self::ROLE);
    }

    /** @param array<string, mixed> $body */
    private static function deliver(string $topic, array $body, string $secret = self::SECRET): void
    {
        $db = self::requireScratch();
        // The receiver's log line is the server log's, not this run's output.
        $previous = (string) ini_get('error_log');
        $sink = (string) tempnam(sys_get_temp_dir(), 'p202-uo-shopify-pages-log');
        ini_set('error_log', $sink);
        try {
            (new Receiver(new ShopifyAdapter($db), $db))->receive(ShopifyAdapterTest::webhook($topic, $body, $secret));
        } finally {
            ini_set('error_log', $previous);
            @unlink($sink);
        }
    }

    private static function paidOrderWithAWaitingRefund(): void
    {
        self::deliver('orders/paid', ShopifyAdapterTest::order(['line_items' => [[
            'id' => 13001, 'title' => 'Single-origin coffee beans, 1 kg', 'quantity' => 1, 'price' => '54.00',
            'properties' => [['name' => '_t202subid', 'value' => (string) self::CLICK]],
        ]]]));
        self::deliver('refunds/create', ShopifyAdapterTest::refund(['id' => 9100001, 'refund_line_items' => [],
            'transactions' => [
                ['id' => 71001, 'kind' => 'refund', 'status' => 'pending', 'amount' => '10.00', 'currency' => 'USD'],
            ]]));
    }

    /** @return list<string> */
    private static function payouts(): array
    {
        $result = self::requireScratch()->query('SELECT click_payout FROM 202_conversion_logs WHERE click_id = '
            . self::CLICK . ' AND deleted = 0 ORDER BY conv_id');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'click_payout');
    }

    public function testTheLogListsAWaitingRefundWarnsAndRecordsItFromItsForm(): void
    {
        self::paidOrderWithAWaitingRefund();

        $page = self::decoded(self::page(self::LOG));
        self::assertStringContainsString('id="shopify-refunds"', $page);
        self::assertStringContainsString('Waiting for Shopify to say its transaction settled.', $page);
        // The fixture's refund is days old and no transaction webhook ever arrived.
        self::assertStringContainsString('Transaction creation webhook has never arrived for this account', $page);
        $prefilled = '#name="refund_id" value="9100001">\s*<input[^>]*name="amount"[^>]*value="10"#';
        self::assertMatchesRegularExpression($prefilled, $page);

        // No token, another account, a made-up id: nothing is recorded.
        self::page(self::LOG, ['action' => 'record_refund', 'refund_id' => '9100001', 'amount' => '10.00']);
        self::page(self::LOG, ['action' => 'record_refund', 'refund_id' => '9100001', 'amount' => '10.00',
            'token' => 'SESSION'], self::OTHER);
        self::page(self::LOG, ['action' => 'record_refund', 'refund_id' => '9100001abc', 'amount' => '10.00',
            'token' => 'SESSION']);
        self::assertSame(['59.00000'], self::payouts());

        self::page(self::LOG, ['action' => 'record_refund', 'refund_id' => '9100001', 'amount' => '10.00',
            'token' => 'SESSION']);
        self::assertSame(['59.00000', '-10.00000'], self::payouts());
        $after = self::decoded(self::page(self::LOG));
        self::assertStringNotContainsString('id="shopify-refunds"', $after);
        self::assertStringContainsString('Recorded by hand from Setup › Inbound Log.', $after);
    }

    public function testClosingFromTheLogRecordsNothingAndClearsTheList(): void
    {
        self::paidOrderWithAWaitingRefund();
        self::page(self::LOG, ['action' => 'close_refund', 'refund_id' => '9100001']);
        self::assertStringContainsString('id="shopify-refunds"', self::page(self::LOG));

        self::page(self::LOG, ['action' => 'close_refund', 'refund_id' => '9100001', 'token' => 'SESSION']);
        self::assertStringNotContainsString('id="shopify-refunds"', self::page(self::LOG));
        self::assertSame(['59.00000'], self::payouts());
    }

    public function testTheCardSaysWhetherWebhooksArriveAndCountsRefundsLeft(): void
    {
        $none = self::card();
        self::assertStringContainsString('p202-pill p202-pill--warn">No webhook yet<', $none);
        self::assertStringContainsString('properties[_t202subid]', self::decoded($none));
        self::assertStringContainsString('?t202subid=[[subid]]', self::decoded($none));

        self::deliver('orders/paid', ShopifyAdapterTest::order(), 'a-secret-the-store-does-not-have');
        $failing = self::decoded(self::card());
        self::assertStringContainsString('p202-pill p202-pill--warn">Signature not verifying<', $failing);
        self::assertStringContainsString('that no saved secret verifies', $failing);
        $log = self::decoded(self::page(self::LOG, [], self::USER, ['endpoint' => 'shopify']));
        self::assertStringContainsString('The Shopify webhooks no stored secret verified are listed too', $log);
        self::assertStringNotContainsString('orders/paid', $log, 'an unverified request is listed without its fields');

        self::paidOrderWithAWaitingRefund();
        $receiving = self::decoded(self::card());
        self::assertStringContainsString('p202-pill p202-pill--good">Receiving webhooks<', $receiving);
        self::assertStringContainsString('Last webhook: Refund create', $receiving);
        self::assertStringContainsString('Last order recorded', $receiving);
        self::assertMatchesRegularExpression('#1 refund is\s+not recorded#', $receiving);

        // Without a secret there is nothing to report on.
        self::q('UPDATE 202_users_pref SET ' . ShopifyWebhookSecret::COLUMN . ' = NULL WHERE user_id = ' . self::USER);
        $off = self::card();
        self::assertStringContainsString('<span class="p202-pill">Not connected</span>', $off);
        self::assertStringNotContainsString('id="shopify-status"', $off);
    }

    private static function card(): string
    {
        $out = self::page(self::CARD);
        $start = strpos($out, '<section class="p202-panel h-100" id="shopify">');
        self::assertIsInt($start, 'the page drew no Shopify card: ' . substr($out, 0, 500));
        $end = strpos($out, '</section>', $start);
        self::assertIsInt($end);

        return substr($out, $start, $end - $start);
    }

    /**
     * @param array<string, string> $post
     * @param array<string, string> $get
     */
    private static function page(string $page, array $post = [], int $user = self::USER, array $get = []): string
    {
        self::requireScratch();
        $env = [
            'P202_TEST_REPORT_USER' => (string) $user,
            'P202_TEST_SESSION_DIR' => self::$sessions,
            'P202_TEST_GET' => (string) json_encode((object) $get),
            'P202_TEST_POST' => (string) json_encode((object) $post),
        ] + getenv();
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL & ~E_DEPRECATED',
                '-d', 'log_errors=0', __DIR__ . '/fixtures/page-runner.php', $page,
            ],
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
        $code = proc_close($proc);
        self::assertNotSame(2, $code, "the runner could not set up: $err");
        self::assertStringNotContainsString('Fatal error', $out . $err, $err);
        self::assertStringNotContainsString('Warning:', $err, $err);

        return $out;
    }

    private static function decoded(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
