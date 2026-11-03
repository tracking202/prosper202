<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Outcome;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Receiver;
use Prosper202\PaymentNotification\Response;
use Prosper202\PaymentNotification\Shopify\ShopifyAdapter;
use Prosper202\PaymentNotification\Shopify\ShopifyWebhookSecret;

/**
 * Shopify's order webhooks end to end on a real server: the secret read
 * from 202_users_pref, the signature, the processor and the real conversion
 * writer, and the rows they leave. Bodies are Shopify-shaped demo data
 * (ShopifyAdapterTest::order()/refund()).
 *
 * @group integration
 */
final class ShopifyWebhookIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ACCOUNT = 990501;
    private const OTHER = 990502;
    private const CLICK = 99050101;
    private const OTHER_CLICK = 99050111;
    private const CAMPAIGN = 990561;
    private const OTHER_CAMPAIGN = 990562;

    private const SECRET = 'demo-store-webhook-secret-0001';
    private const OTHER_SECRET = 'other-store-webhook-secret-002';

    private const CLICK_TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_rotator',
        '202_clicks_record', '202_clicks_site', '202_attribution_rollup_dirty_clicks', '202_dataengine',
        '202_click_latest_visits',
    ];

    private string $log = '';
    private string $previousLog = '';

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
        self::cleanUp();
        foreach ([self::ACCOUNT => self::CAMPAIGN, self::OTHER => self::OTHER_CAMPAIGN] as $user => $campaign) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_account_currency = 'USD'");
            self::q("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $campaign, user_id = $user,"
                . " aff_network_id = 1, aff_campaign_name = 'shop$campaign',"
                . " aff_campaign_url = 'https://demo-coffee.example/products/beans',"
                . ' aff_campaign_payout = 0, aff_campaign_time = 1, aff_campaign_foreign_payout = 0,'
                . " payout_mode = 'accumulate'");
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        $db = self::requireScratch();
        $range = 'BETWEEN ' . self::CLICK . ' AND ' . (self::CLICK + 99);
        foreach ([...self::CLICK_TABLES, '202_conversion_logs'] as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        self::secret(self::ACCOUNT, self::SECRET);
        self::secret(self::OTHER, self::OTHER_SECRET);
        $repo = new MysqlClickRepository(new Connection($db));
        foreach ([self::CLICK => [self::ACCOUNT, self::CAMPAIGN], self::OTHER_CLICK => [self::OTHER, self::OTHER_CAMPAIGN]]
            as $click => [$user, $campaign]) {
            $repo->recordClick(new ClickRecord(
                clickId: $click,
                userId: $user,
                clickTime: strtotime('2026-11-01T14:00:00Z'),
                affCampaignId: $campaign,
                clickPayout: '0',
                recordsCampaignRows: false,
            ));
        }
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-shopify-log');
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        if ($this->log !== '') {
            ini_set('error_log', $this->previousLog);
            @unlink($this->log);
        }
    }

    private static function cleanUp(): void
    {
        $range = 'BETWEEN ' . self::CLICK . ' AND ' . (self::CLICK + 99);
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN'
            . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id $range)");
        foreach ([...self::CLICK_TABLES, '202_conversion_logs'] as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        $users = self::ACCOUNT . ', ' . self::OTHER;
        self::q("DELETE FROM 202_aff_campaigns WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users_pref WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users WHERE user_id IN ($users)");
    }

    private static function secret(int $user, ?string $secret): void
    {
        $value = $secret === null ? 'NULL' : self::str($secret);
        self::q('UPDATE 202_users_pref SET ' . ShopifyWebhookSecret::COLUMN . " = $value WHERE user_id = $user");
    }

    /** @param array<string, mixed> $overrides */
    private static function order(array $overrides = [], int $click = self::CLICK): array
    {
        return $overrides + ShopifyAdapterTest::order(['line_items' => [[
            'id' => 13001, 'title' => 'Single-origin coffee beans, 1 kg', 'quantity' => 1, 'price' => '54.00',
            'properties' => [['name' => 't202subid', 'value' => (string) $click]],
        ]]]);
    }

    /** @param array<string, mixed> $body */
    private function deliver(string $topic, array $body, ?string $secret = self::SECRET): Outcome
    {
        return $this->receive(ShopifyAdapterTest::webhook($topic, $body, $secret));
    }

    private function receive(InboundRequest $request): Outcome
    {
        $db = self::requireScratch();

        return (new Receiver(new ShopifyAdapter($db), $db))->receive($request);
    }

    /** @return list<array<string, string|null>> */
    private static function conversions(): array
    {
        $result = self::requireScratch()->query('SELECT click_id, user_id, transaction_id, click_payout, source,'
            . ' pixel_type, reverses_conv_id, conv_time FROM 202_conversion_logs WHERE click_id BETWEEN '
            . self::CLICK . ' AND ' . (self::CLICK + 99) . ' ORDER BY conv_id');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    private static function assertOutcome(OutcomeKind $kind, int $status, Outcome $outcome): void
    {
        self::assertSame($kind, $outcome->kind, $outcome->message);
        self::assertSame($status, Response::status($outcome->kind));
    }

    public function testAPaidOrderIsRecordedOnItsClickUnderItsOrderIdAtItsTotal(): void
    {
        $outcome = $this->deliver('orders/paid', self::order());

        self::assertOutcome(OutcomeKind::RECORDED, 200, $outcome);
        self::assertSame(self::ACCOUNT, $outcome->accountId);
        self::assertSame([[
            'click_id' => (string) self::CLICK, 'user_id' => (string) self::ACCOUNT,
            'transaction_id' => '5550001234567', 'click_payout' => '59.00000', 'source' => 'shopify',
            'pixel_type' => '2', 'reverses_conv_id' => null,
            'conv_time' => (string) strtotime('2026-11-01T15:15:02Z'),
        ]], self::conversions());

        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('Shopify notification: recorded (200), orders/paid (sale), click ' . self::CLICK
            . ', transaction 5550001234567, account ' . self::ACCOUNT, $log);
        foreach (['buyer@example.com', self::SECRET] as $private) {
            self::assertStringNotContainsString($private, $log);
        }
    }

    public function testOrderCreationAndPaymentCountTheOrderOnce(): void
    {
        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->deliver('orders/create', self::order()));
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->deliver('orders/paid', self::order()));
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->deliver('orders/paid', self::order()));
        self::assertCount(1, self::conversions());
    }

    public function testABadSignatureIsRefusedAndRecordsNothing(): void
    {
        $outcome = $this->deliver('orders/paid', self::order(), 'an-attacker-guessed-secret');

        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $outcome);
        self::assertSame([], self::conversions());
        self::assertStringContainsString('Shopify notification from 203.0.113.9: unverified (403)',
            (string) file_get_contents($this->log));

        $unsigned = $this->deliver('orders/paid', self::order(), null);
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $unsigned);
        self::assertSame([], self::conversions());
    }

    public function testWithNoSecretStoredTheRefusalSaysSoInTheLog(): void
    {
        self::secret(self::ACCOUNT, null);
        self::secret(self::OTHER, 'not a usable secret');

        $outcome = $this->deliver('orders/paid', self::order());

        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $outcome);
        self::assertSame([], self::conversions());
        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('No Prosper202 account stores a Shopify webhook secret', $log);
        self::assertStringContainsString('account ' . self::OTHER . '\'s stored webhook secret is not a secret', $log);
    }

    public function testAnotherAccountsClickReadsAsNoClick(): void
    {
        $outcome = $this->deliver('orders/paid', self::order([], self::OTHER_CLICK));

        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $outcome);
        self::assertSame([], self::conversions());
    }

    public function testAnOrderNamingNoClickRecordsNothing(): void
    {
        $order = self::order(['line_items' => [['id' => 1, 'properties' => []]]]);

        self::assertOutcome(OutcomeKind::NO_CLICK, 200, $this->deliver('orders/paid', $order));
        self::assertSame([], self::conversions());
    }

    public function testATestOrderRecordsNothing(): void
    {
        self::assertOutcome(OutcomeKind::TEST, 200, $this->deliver('orders/create', self::order(['test' => true])));
        self::assertSame([], self::conversions());
    }

    public function testARefundReversesTheOrderByWhatWasReturned(): void
    {
        $this->deliver('orders/paid', self::order());
        $sale = self::conversions()[0];

        $outcome = $this->deliver('refunds/create', ShopifyAdapterTest::refund([
            'refund_line_items' => [['id' => 1, 'line_item' => self::order()['line_items'][0]]],
        ]));

        self::assertOutcome(OutcomeKind::REVERSED, 200, $outcome);
        $rows = self::conversions();
        self::assertCount(2, $rows);
        self::assertSame(['-20.00000', 'shopify', '5550001234567'],
            [$rows[1]['click_payout'], $rows[1]['source'], $rows[1]['transaction_id']]);
        self::assertNotNull($rows[1]['reverses_conv_id']);
        self::assertSame('59.00000', $sale['click_payout']);

        // Shopify's retry of the same refund.
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->deliver('refunds/create', ShopifyAdapterTest::refund([
            'refund_line_items' => [['id' => 1, 'line_item' => self::order()['line_items'][0]]],
        ])));
        self::assertCount(2, self::conversions());
    }

    public function testARefundWithoutLineItemsFindsTheOrdersClick(): void
    {
        $this->deliver('orders/paid', self::order());

        $outcome = $this->deliver('refunds/create', ShopifyAdapterTest::refund(['refund_line_items' => []]));

        self::assertOutcome(OutcomeKind::REVERSED, 200, $outcome);
        self::assertSame(['59.00000', '-20.00000'], array_column(self::conversions(), 'click_payout'));
    }

    public function testARefundOfAnOrderNotOnFileReversesNothing(): void
    {
        $outcome = $this->deliver('refunds/create', ShopifyAdapterTest::refund(['refund_line_items' => []]));

        self::assertOutcome(OutcomeKind::NO_CLICK, 200, $outcome);
        self::assertSame([], self::conversions());
    }

    public function testAnOrderInAnotherCurrencyIsRefused(): void
    {
        $outcome = $this->deliver('orders/paid', self::order(['currency' => 'EUR']));

        self::assertOutcome(OutcomeKind::CURRENCY_MISMATCH, 422, $outcome);
        self::assertSame([], self::conversions());
    }

    public function testAnOrderPaidAtAnotherTotalIsRefusedButAcceptedByShopify(): void
    {
        $this->deliver('orders/paid', self::order());
        $changed = $this->deliver('orders/paid', self::order(['total_price' => '64.00']));

        self::assertOutcome(OutcomeKind::CONFLICT, 409, $changed);
        self::assertSame(200, ShopifyAdapter::deliveryStatus($changed->kind), 'not retried until Shopify drops it');
        self::assertSame(['59.00000'], array_column(self::conversions(), 'click_payout'));
    }

    public function testAnUnpaidOrderIsLeftToItsPayment(): void
    {
        $pending = self::order(['financial_status' => 'pending']);

        self::assertOutcome(OutcomeKind::IGNORED, 200, $this->deliver('orders/create', $pending));
        self::assertSame([], self::conversions());
        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->deliver('orders/paid', self::order()));
    }

    public function testASecondRefundOfTheOrderIsRefusedAndKeptInTheLog(): void
    {
        $this->deliver('orders/paid', self::order());
        $this->deliver('refunds/create', ShopifyAdapterTest::refund(['refund_line_items' => []]));

        $second = $this->deliver('refunds/create', ShopifyAdapterTest::refund(['id' => 9100002, 'refund_line_items' => []]));

        self::assertOutcome(OutcomeKind::CONFLICT, 409, $second);
        self::assertSame(200, ShopifyAdapter::deliveryStatus($second->kind));
        self::assertSame(['59.00000', '-20.00000'], array_column(self::conversions(), 'click_payout'));
        self::assertStringContainsString('Shopify notification: conflict (409), refunds/create (refund)',
            (string) file_get_contents($this->log));
    }

    public function testARefundOfAnOrderOnTwoClicksIsRefused(): void
    {
        $this->deliver('orders/paid', self::order());
        (new MysqlClickRepository(new Connection(self::requireScratch())))->recordClick(new ClickRecord(
            clickId: self::CLICK + 1,
            userId: self::ACCOUNT,
            clickTime: strtotime('2026-11-01T14:00:00Z'),
            affCampaignId: self::CAMPAIGN,
            clickPayout: '0',
            recordsCampaignRows: false,
        ));
        $this->deliver('orders/paid', self::order([], self::CLICK + 1));

        $outcome = $this->deliver('refunds/create', ShopifyAdapterTest::refund(['refund_line_items' => []]));

        self::assertOutcome(OutcomeKind::CONFLICT, 409, $outcome);
        self::assertStringContainsString('recorded on more than one click', $outcome->message);
        self::assertCount(2, self::conversions());
    }

    /** gpb.php, run as the server runs it, hands a request with Shopify's headers to shopify.php. */
    public function testGpbHandsShopifyWebhooksToTheShopifyEndpoint(): void
    {
        $shopify = self::runGpb(['HTTP_X_SHOPIFY_TOPIC' => 'orders/paid', 'HTTP_X_SHOPIFY_HMAC_SHA256' => 'bm90LXNpZ25lZA==']);
        $answer = json_decode($shopify, true);
        self::assertIsArray($answer, $shopify);
        self::assertSame('unverified', $answer['outcome'] ?? null, $shopify);
        self::assertSame(403, $answer['code'] ?? null);
        self::assertStringContainsString('Shopify notification from', (string) file_get_contents($this->log) . self::$runnerErr);

        $postback = self::runGpb([]);
        self::assertStringContainsString('SubID not found', $postback, 'any other request is a postback, as before');
    }

    /**
     * shopify.php, run as the server runs it, answers a verified webhook it
     * refuses with a 200 carrying the refusal. The runner's body is empty
     * (php://input under the CLI), so the webhook signs "".
     */
    public function testTheEndpointAnswersAVerifiedRefusalWith200(): void
    {
        $out = self::runGpb([
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/paid',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => ShopifyAdapter::sign('', self::SECRET),
        ], 'tracking202/static/shopify.php');
        $answer = json_decode($out, true);

        self::assertIsArray($answer, $out . self::$runnerErr);
        self::assertSame(['error' => true, 'code' => 200, 'outcome' => 'malformed'],
            array_intersect_key($answer, ['error' => 1, 'code' => 1, 'outcome' => 1]));
        self::assertStringContainsString('is not a JSON object', (string) $answer['msg']);
        self::assertSame([], self::conversions());
    }

    private static string $runnerErr = '';

    /** @param array<string, string> $server */
    private static function runGpb(array $server, string $page = 'tracking202/static/gpb.php'): string
    {
        $sessions = sys_get_temp_dir() . '/p202-shopify-gpb-' . getmypid();
        if (!is_dir($sessions) && !mkdir($sessions, 0700)) {
            throw new \RuntimeException('could not create ' . $sessions);
        }
        $env = [
            'P202_TEST_REPORT_USER' => (string) self::ACCOUNT,
            'P202_TEST_SESSION_DIR' => $sessions,
            'P202_TEST_GET' => '{}',
            'P202_TEST_POST' => (string) json_encode(['id' => '5550001234567']),
            'P202_TEST_SERVER' => (string) json_encode((object) $server),
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__) . '/Setup/fixtures/page-runner.php',
                $page],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        self::$runnerErr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), 'the runner could not set up: ' . self::$runnerErr);
        array_map('unlink', glob($sessions . '/*') ?: []);
        @rmdir($sessions);

        return $out;
    }

    public function testAGetIsRefused(): void
    {
        $signed = ShopifyAdapterTest::webhook('orders/paid', self::order());
        $get = new InboundRequest('GET', '', [], '203.0.113.9', '203.0.113.9', '', $signed->body, $signed->headers);

        self::assertOutcome(OutcomeKind::METHOD_NOT_ALLOWED, 405, $this->receive($get));
    }
}
