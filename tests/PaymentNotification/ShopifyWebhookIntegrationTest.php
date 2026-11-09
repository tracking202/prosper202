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
use Prosper202\PaymentNotification\Shopify\RefundSettlementStore;

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
        self::q('DELETE FROM 202_shopify_pending_refunds WHERE user_id IN (' . self::ACCOUNT . ', ' . self::OTHER . ')');
        self::q('DELETE FROM 202_shopify_refund_transactions WHERE user_id IN (' . self::ACCOUNT . ', ' . self::OTHER . ')');
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
        self::q("DELETE FROM 202_shopify_pending_refunds WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_shopify_refund_transactions WHERE user_id IN ($users)");
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
        return $this->answer($request)[0];
    }

    /**
     * The outcome and the status Shopify is sent, at a given time.
     *
     * @return array{0: Outcome, 1: int}
     */
    private function answer(InboundRequest $request, ?int $now = null): array
    {
        $db = self::requireScratch();
        $receiver = new Receiver(new ShopifyAdapter($db, $now), $db);
        $outcome = $receiver->receive($request);

        return [$outcome, $receiver->status($outcome)];
    }

    private static function recordClick(int $click): void
    {
        (new MysqlClickRepository(new Connection(self::requireScratch())))->recordClick(new ClickRecord(
            clickId: $click,
            userId: self::ACCOUNT,
            clickTime: strtotime('2026-11-01T14:00:00Z'),
            affCampaignId: self::CAMPAIGN,
            clickPayout: '0',
            recordsCampaignRows: false,
        ));
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

    public function testPendingRefundWaitsForSettlementAndRetriesAreIdempotent(): void
    {
        $this->deliver('orders/paid', self::order());
        $pending = ShopifyAdapterTest::refund(['transactions' => [
            ['id' => 71001, 'kind' => 'refund', 'status' => 'success', 'amount' => '10.00', 'currency' => 'USD'],
            ['id' => 71002, 'kind' => 'refund', 'status' => 'pending', 'amount' => '10.00', 'currency' => 'USD'],
        ]]);
        [$outcome, $status] = $this->answer(ShopifyAdapterTest::webhook('refunds/create', $pending));
        self::assertSame(OutcomeKind::IGNORED, $outcome->kind);
        self::assertSame(200, $status);
        self::assertSame(['59.00000'], array_column(self::conversions(), 'click_payout'));
        $transaction = ['id' => 71002, 'order_id' => 5550001234567, 'kind' => 'refund',
            'status' => 'success', 'amount' => '10.00', 'currency' => 'USD'];
        $this->deliver('order_transactions/create', $transaction);
        $this->deliver('order_transactions/create', $transaction);
        $this->deliver('refunds/create', $pending);
        self::assertSame(['59.00000', '-20.00000'], array_column(self::conversions(), 'click_payout'));
    }

    public function testSettlementAndRefundBeforeOrderAreReconciledOnOrderArrival(): void
    {
        $transaction = ['id' => 71002, 'order_id' => 5550001234567, 'kind' => 'refund',
            'status' => 'success', 'amount' => '10.00', 'currency' => 'USD'];
        $pending = ShopifyAdapterTest::refund(['transactions' => [
            ['id' => 71002, 'kind' => 'refund', 'status' => 'pending', 'amount' => '10.00', 'currency' => 'USD'],
        ]]);
        $this->deliver('order_transactions/create', $transaction);
        $this->deliver('refunds/create', $pending);
        self::assertSame([], self::conversions());
        $this->deliver('orders/paid', self::order());
        self::assertSame(['59.00000', '-10.00000'], array_column(self::conversions(), 'click_payout'));
    }

    public function testFailedSettlementDoesNotReverseAndUnverifiedUpdateIsIgnored(): void
    {
        $this->deliver('orders/paid', self::order());
        $pending = ShopifyAdapterTest::refund(['transactions' => [
            ['id' => 71002, 'kind' => 'refund', 'status' => 'pending', 'amount' => '10.00', 'currency' => 'USD'],
        ]]);
        $this->deliver('refunds/create', $pending);
        $transaction = ['id' => 71002, 'order_id' => 5550001234567, 'kind' => 'refund',
            'status' => 'failure', 'amount' => '10.00', 'currency' => 'USD'];
        self::assertSame(OutcomeKind::UNVERIFIED, $this->deliver('order_transactions/create', $transaction, 'wrong-secret')->kind);
        $this->deliver('order_transactions/create', $transaction);
        $this->deliver('order_transactions/create', $transaction);
        self::assertSame(['59.00000'], array_column(self::conversions(), 'click_payout'));
    }

    public function testRetriedOrderReconcilesAfterAnEarlierPartialRefund(): void
    {
        $this->deliver('orders/paid', self::order());
        $this->deliver('refunds/create', ShopifyAdapterTest::refund(['refund_line_items' => []]));
        $store = new RefundSettlementStore(new Connection(self::requireScratch()));
        $store->register(self::ACCOUNT, '9100009', '5550001234567', 0, 0, [
            ['id' => '71009', 'status' => 'pending', 'amount' => '10.00', 'currency' => 'USD'],
        ]);
        $store->update(self::ACCOUNT, '5550001234567', '71009', 'success', '10.00', 'USD', time());
        $this->deliver('orders/paid', self::order());
        self::assertSame(['59.00000', '-20.00000', '-10.00000'], array_column(self::conversions(), 'click_payout'));
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
        self::assertSame(200, (new ShopifyAdapter())->deliveryStatus($changed->kind), 'not retried until Shopify drops it');
        self::assertSame(['59.00000'], array_column(self::conversions(), 'click_payout'));
    }

    public function testAnUnpaidOrderIsLeftToItsPayment(): void
    {
        $pending = self::order(['financial_status' => 'pending']);

        self::assertOutcome(OutcomeKind::IGNORED, 200, $this->deliver('orders/create', $pending));
        self::assertSame([], self::conversions());
        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->deliver('orders/paid', self::order()));
    }

    public function testMultiplePartialRefundsAndRetriesNeverExceedTheSale(): void
    {
        $this->deliver('orders/paid', self::order());
        $this->deliver('refunds/create', ShopifyAdapterTest::refund(['refund_line_items' => []]));

        [$second, $status] = $this->answer(ShopifyAdapterTest::webhook('refunds/create',
            ShopifyAdapterTest::refund(['id' => 9100002, 'refund_line_items' => []])));

        self::assertOutcome(OutcomeKind::REVERSED, 200, $second);
        self::assertSame(200, $status);
        self::assertSame(['59.00000', '-20.00000', '-20.00000'], array_column(self::conversions(), 'click_payout'));
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->deliver('refunds/create',
            ShopifyAdapterTest::refund(['id' => 9100002, 'refund_line_items' => []])));
        $last = $this->deliver('refunds/create', ShopifyAdapterTest::refund(['id' => 9100003, 'refund_line_items' => []]));
        self::assertOutcome(OutcomeKind::REVERSED, 200, $last);
        self::assertStringContainsString('19', $last->message);
        self::assertSame(['59.00000', '-20.00000', '-20.00000', '-19.00000'],
            array_column(self::conversions(), 'click_payout'));
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->deliver('refunds/create',
            ShopifyAdapterTest::refund(['id' => 9100003, 'refund_line_items' => []])));
    }

    public function testARefundOfAnOrderOnTwoClicksIsRefused(): void
    {
        $this->deliver('orders/paid', self::order());
        self::recordClick(self::CLICK + 1);
        $this->deliver('orders/paid', self::order([], self::CLICK + 1));

        $outcome = $this->deliver('refunds/create', ShopifyAdapterTest::refund(['refund_line_items' => []]));

        self::assertOutcome(OutcomeKind::CONFLICT, 409, $outcome);
        self::assertStringContainsString('recorded on more than one click', $outcome->message);
        self::assertCount(2, self::conversions());
    }

    /**
     * A refund naming a click with no sale under its order: retried for an
     * hour (the order may be late), then accepted, so a refund of an order
     * that will never arrive does not fail until Shopify drops the webhook.
     */
    public function testARefundWhoseSaleIsNotOnFileIsRetriedOnlyWhileRecent(): void
    {
        $refund = ShopifyAdapterTest::webhook('refunds/create', ShopifyAdapterTest::refund([
            'refund_line_items' => [['id' => 1, 'line_item' => self::order()['line_items'][0]]],
        ]));
        $refundAt = strtotime('2026-11-03T14:00:01Z');

        [$recent, $retried] = $this->answer($refund, $refundAt + 120);
        self::assertOutcome(OutcomeKind::NOTHING_TO_REVERSE, 404, $recent);
        self::assertSame(404, $retried);

        [$old, $accepted] = $this->answer($refund, $refundAt + 86400);
        self::assertSame(OutcomeKind::NOTHING_TO_REVERSE, $old->kind);
        self::assertSame(200, $accepted);
        self::assertStringContainsString('Shopify notification: nothing_to_reverse (200)',
            (string) file_get_contents($this->log));
        self::assertSame([], self::conversions());
    }

    /** An order is recorded on its first click; a refund of another item carrying another click takes it back. */
    public function testARefundOfAnotherItemReversesTheOrdersSale(): void
    {
        self::recordClick(self::CLICK + 1);
        $second = ['id' => 13003, 'title' => 'Ceramic pour-over dripper', 'quantity' => 1, 'price' => '5.00',
            'properties' => [['name' => 't202subid', 'value' => (string) (self::CLICK + 1)]]];
        $order = self::order();
        $order['line_items'][] = $second;
        $this->deliver('orders/paid', $order);

        $outcome = $this->deliver('refunds/create', ShopifyAdapterTest::refund([
            'refund_line_items' => [['id' => 2, 'line_item' => $second]],
        ]));

        self::assertOutcome(OutcomeKind::REVERSED, 200, $outcome);
        self::assertSame([[(string) self::CLICK, '59.00000'], [(string) self::CLICK, '-20.00000']],
            array_map(static fn (array $r): array => [$r['click_id'], $r['click_payout']], self::conversions()));
    }

    /** Another source's conversion under the same id is not the order. */
    public function testARefundFindsOnlyTheOrdersShopifySale(): void
    {
        self::recordClick(self::CLICK + 1);
        $this->deliver('orders/paid', self::order());
        $this->deliver('orders/paid', self::order([], self::CLICK + 1));
        self::q("UPDATE 202_conversion_logs SET source = 'postback' WHERE click_id = " . (self::CLICK + 1));

        $outcome = $this->deliver('refunds/create', ShopifyAdapterTest::refund(['refund_line_items' => []]));

        self::assertOutcome(OutcomeKind::REVERSED, 200, $outcome);
        self::assertSame([(string) self::CLICK, (string) (self::CLICK + 1), (string) self::CLICK],
            array_column(self::conversions(), 'click_id'));
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

    /**
     * shopify.php and gpb.php served by `php -S` over the 202-config.php the
     * integration job writes: the signed body is the one read from
     * php://input, and the status is the one sent on the wire.
     */
    public function testTheServedEndpointsRecordWhatTheRequestBodySays(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped('no 202-config.php for the served tree (tests/run-integration-suites.sh writes one)');
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $name = (string) stream_socket_get_name($probe, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);
        $server = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $this->log,
                '-S', "127.0.0.1:$port", '-t', $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::assertIsResource($server);
        try {
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', $port);
                if ($socket !== false) {
                    fclose($socket);
                    break;
                }
                usleep(100000);
            }
            $post = static function (string $page, InboundRequest $webhook) use ($port): array {
                $headers = [];
                foreach ($webhook->headers as $header => $value) {
                    $headers[] = $header . ': ' . $value;
                }
                $body = @file_get_contents("http://127.0.0.1:$port/tracking202/static/$page", false,
                    stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 20,
                        'header' => [...$headers, 'Content-Type: application/json'], 'content' => $webhook->body]]));
                $status = (int) (explode(' ', $http_response_header[0] ?? '')[1] ?? 0);
                $answer = json_decode((string) $body, true);
                self::assertIsArray($answer, (string) $body);

                return [$status, $answer['outcome'] ?? null];
            };

            self::assertSame([200, 'recorded'], $post('shopify.php',
                ShopifyAdapterTest::webhook('orders/paid', self::order())));
            self::assertSame([200, 'reversed'], $post('gpb.php',
                ShopifyAdapterTest::webhook('refunds/create', ShopifyAdapterTest::refund(['refund_line_items' => []]))));
            self::assertSame(['59.00000', '-20.00000'], array_column(self::conversions(), 'click_payout'));

            $pending = ShopifyAdapterTest::refund(['id' => 9100004, 'transactions' => [[
                'id' => 71004, 'kind' => 'refund', 'status' => 'pending', 'amount' => '5.00', 'currency' => 'USD',
            ]]]);
            $settled = ['id' => 71004, 'order_id' => 5550001234567, 'kind' => 'refund',
                'status' => 'success', 'amount' => '5.00', 'currency' => 'USD'];
            self::assertSame([200, 'ignored'], $post('shopify.php', ShopifyAdapterTest::webhook('refunds/create', $pending)));
            self::assertSame([200, 'ignored'], $post('shopify.php', ShopifyAdapterTest::webhook('order_transactions/create', $settled)));
            self::assertSame(['59.00000', '-20.00000', '-5.00000'], array_column(self::conversions(), 'click_payout'));

            // A refund made just now of an order not on file is retried.
            $now = gmdate('Y-m-d\TH:i:s\Z');
            self::assertSame([404, 'nothing_to_reverse'], $post('shopify.php', ShopifyAdapterTest::webhook(
                'refunds/create',
                ShopifyAdapterTest::refund(['id' => 9100009, 'order_id' => 5550009999999, 'created_at' => $now,
                    'processed_at' => $now,
                    'refund_line_items' => [['id' => 1, 'line_item' => self::order()['line_items'][0]]]])
            )));
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
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
