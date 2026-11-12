<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\InboundLog\InboundLog;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Outcome;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Receiver;
use Prosper202\PaymentNotification\Shopify\RefundsToSettle;
use Prosper202\PaymentNotification\Shopify\ShopifyAdapter;
use Prosper202\PaymentNotification\Shopify\ShopifyWebhookSecret;
use Prosper202\PaymentNotification\Shopify\WebhookStatus;

/**
 * The Shopify refunds Setup › Inbound Log lists and settles, and the status
 * the Shopify card shows, on a real server through the real receiver and
 * conversion writer.
 *
 * @group integration
 */
final class ShopifyRefundsToSettleIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ACCOUNT = 990521;
    private const OTHER = 990522;
    private const CLICK = 99052101;
    private const CAMPAIGN = 990571;
    private const ORDER = '5550001234567';
    private const SECRET = 'demo-store-webhook-secret-0021';
    /** The refund fixtures' time. */
    private const REFUNDED = '2026-11-03T09:00:01-05:00';

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
        foreach ([self::ACCOUNT, self::OTHER] as $user) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_account_currency = 'USD'");
        }
        self::q('INSERT INTO 202_aff_campaigns SET aff_campaign_id = ' . self::CAMPAIGN . ', user_id = ' . self::ACCOUNT
            . ", aff_network_id = 1, aff_campaign_name = 'Demo coffee store',"
            . " aff_campaign_url = 'https://demo-coffee.example/products/beans',"
            . ' aff_campaign_payout = 0, aff_campaign_time = 1, aff_campaign_foreign_payout = 0,'
            . " payout_mode = 'accumulate'");
        self::q('UPDATE 202_users_pref SET ' . ShopifyWebhookSecret::COLUMN . ' = ' . self::str(self::SECRET)
            . ' WHERE user_id = ' . self::ACCOUNT);
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
        self::requireScratch();
        self::cleanRows();
        self::recordClick(self::CLICK);
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-shopify-settle-log');
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

    private static function cleanRows(): void
    {
        $users = self::ACCOUNT . ', ' . self::OTHER;
        $range = 'BETWEEN ' . self::CLICK . ' AND ' . (self::CLICK + 99);
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN'
            . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id $range)");
        foreach ([...self::CLICK_TABLES, '202_conversion_logs'] as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        self::q("DELETE FROM 202_shopify_pending_refunds WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_shopify_refund_transactions WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_held_payment_notifications WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_inbound_log WHERE endpoint = 'shopify' AND user_id IN (0, $users)");
    }

    private static function cleanUp(): void
    {
        self::cleanRows();
        $users = self::ACCOUNT . ', ' . self::OTHER;
        self::q("DELETE FROM 202_aff_campaigns WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users_pref WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users WHERE user_id IN ($users)");
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

    /** @return array<string, mixed> */
    private static function order(int $click = self::CLICK): array
    {
        return ShopifyAdapterTest::order(['line_items' => [[
            'id' => 13001, 'title' => 'Single-origin coffee beans, 1 kg', 'quantity' => 1, 'price' => '54.00',
            'properties' => [['name' => '_t202subid', 'value' => (string) $click]],
        ]]]);
    }

    /** @param array<string, mixed> $body */
    private function deliver(string $topic, array $body, ?string $secret = self::SECRET): Outcome
    {
        $db = self::requireScratch();

        $receiver = new Receiver(new ShopifyAdapter($db), $db);

        return $receiver->receive(ShopifyAdapterTest::webhook($topic, $body, $secret));
    }

    private function pending(int $refund, int $transaction, string $amount): Outcome
    {
        return $this->deliver('refunds/create', ShopifyAdapterTest::refund([
            'id' => $refund, 'refund_line_items' => [], 'transactions' => [
                ['id' => $transaction, 'kind' => 'refund', 'status' => 'pending', 'amount' => $amount,
                    'currency' => 'USD'],
            ],
        ]));
    }

    private function settle(int $transaction, string $amount): Outcome
    {
        return $this->deliver('order_transactions/create', ['id' => $transaction, 'order_id' => (int) self::ORDER,
            'kind' => 'refund', 'status' => 'success', 'amount' => $amount, 'currency' => 'USD']);
    }

    private static function desk(): RefundsToSettle
    {
        return new RefundsToSettle(self::requireScratch());
    }

    private static function byHand(): InboundRequest
    {
        return new InboundRequest('POST', '', [], '', '', 'Prosper202 Setup › Inbound Log');
    }

    /** @return list<string> */
    private static function payouts(): array
    {
        $result = self::requireScratch()->query('SELECT click_payout FROM 202_conversion_logs WHERE click_id BETWEEN '
            . self::CLICK . ' AND ' . (self::CLICK + 99) . ' AND deleted = 0 ORDER BY conv_id');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'click_payout');
    }

    public function testAWaitingRefundIsListedOnceItsOrderIsOnFileAndFlaggedAfterADay(): void
    {
        $this->pending(9100001, 71001, '10.00');
        // No sale is on file, so nothing is overstated.
        self::assertSame([], self::desk()->list(self::ACCOUNT)['refunds']);

        $this->deliver('orders/paid', self::order());
        $at = strtotime(self::REFUNDED);
        $fresh = self::desk()->list(self::ACCOUNT, $at + 3600);
        self::assertCount(1, $fresh['refunds']);
        self::assertSame([
            'refund_id' => '9100001', 'order_id' => self::ORDER, 'occurred_at' => $at, 'waiting' => true,
            'stale' => false, 'reason' => '', 'clicks' => [self::CLICK], 'amount' => '10', 'currency' => 'USD',
            'other_currency' => false,
        ], $fresh['refunds'][0]);
        self::assertFalse($fresh['transaction_webhook_seen']);

        $late = self::desk()->list(self::ACCOUNT, $at + RefundsToSettle::STALE_AFTER + 1);
        self::assertTrue($late['refunds'][0]['stale']);
        self::assertSame([], self::desk()->list(self::OTHER)['refunds']);
    }

    public function testARefundRefusedBeforeItsOrderArrivedIsListedOnceTheOrderIsRecorded(): void
    {
        $this->pending(9100001, 71002, '10.00');
        self::assertSame(OutcomeKind::CONFLICT, $this->settle(71002, '9.00')->kind);
        self::assertSame([], self::desk()->list(self::ACCOUNT)['refunds']);

        $this->deliver('orders/paid', self::order());
        $listed = self::desk()->list(self::ACCOUNT)['refunds'];
        self::assertCount(1, $listed);
        self::assertFalse($listed[0]['waiting']);
        self::assertSame(['59.00000'], self::payouts());
    }

    public function testAWaitingRefundRecordedByHandIsNotRecordedAgainWhenItSettles(): void
    {
        $this->deliver('orders/paid', self::order());
        $this->pending(9100001, 71001, '10.00');

        $outcome = self::desk()->takeBack(self::ACCOUNT, '9100001', '10.00', null, self::byHand());
        self::assertSame(OutcomeKind::REVERSED, $outcome->kind, $outcome->message);
        self::assertSame(['59.00000', '-10.00000'], self::payouts());
        self::assertSame([], self::desk()->list(self::ACCOUNT)['refunds']);

        // The button pressed twice, and Shopify settling it later, add nothing.
        $again = self::desk()->takeBack(self::ACCOUNT, '9100001', '10.00', null, self::byHand());
        self::assertSame(OutcomeKind::NOTHING_TO_REVERSE, $again->kind, $again->message);
        $this->settle(71001, '10.00');
        self::assertSame(['59.00000', '-10.00000'], self::payouts());
        self::assertTrue(self::desk()->list(self::ACCOUNT)['transaction_webhook_seen']);
    }

    public function testARefusedRefundOfAnOrderAlreadyRefundedIsRecordedByHandUpToWhatIsLeft(): void
    {
        $this->deliver('orders/paid', self::order());
        self::assertSame(OutcomeKind::REVERSED, $this->deliver('refunds/create', ShopifyAdapterTest::refund([
            'id' => 9100009, 'refund_line_items' => [],
        ]))->kind);
        $this->pending(9100001, 71002, '50.00');
        $refused = $this->settle(71002, '9.00');
        self::assertSame(OutcomeKind::CONFLICT, $refused->kind, $refused->message);
        self::assertStringContainsString('Setup › Inbound Log', $refused->message);

        $listed = self::desk()->list(self::ACCOUNT)['refunds'];
        self::assertCount(1, $listed);
        self::assertFalse($listed[0]['waiting']);
        self::assertSame('9100001', $listed[0]['refund_id']);
        self::assertStringContainsString('does not match', $listed[0]['reason']);

        foreach (['0', '12abc'] as $amount) {
            $unread = self::desk()->takeBack(self::ACCOUNT, '9100001', $amount, null, self::byHand());
            self::assertSame(OutcomeKind::MALFORMED, $unread->kind, $amount);
        }
        self::assertSame(
            OutcomeKind::NOTHING_TO_REVERSE,
            self::desk()->takeBack(self::OTHER, '9100001', '9.00', null, self::byHand())->kind
        );
        self::assertSame(['59.00000', '-20.00000'], self::payouts());

        // 59 sold, 20 refunded: a refund stating 50 takes back the 39 that is left.
        $outcome = self::desk()->takeBack(self::ACCOUNT, '9100001', '50.00', null, self::byHand());
        self::assertSame(OutcomeKind::REVERSED, $outcome->kind, $outcome->message);
        self::assertSame(['59.00000', '-20.00000', '-39.00000'], self::payouts());
        self::assertSame([], self::desk()->list(self::ACCOUNT)['refunds']);
    }

    public function testARefundRefusedOutrightForAnOrderOnTwoClicksIsListedAndTakesTheChosenClick(): void
    {
        $this->deliver('orders/paid', self::order());
        self::recordClick(self::CLICK + 1);
        $this->deliver('orders/paid', self::order(self::CLICK + 1));
        $refused = $this->deliver('refunds/create', ShopifyAdapterTest::refund(['refund_line_items' => []]));
        self::assertSame(OutcomeKind::CONFLICT, $refused->kind, $refused->message);

        $listed = self::desk()->list(self::ACCOUNT)['refunds'];
        self::assertCount(1, $listed);
        self::assertSame([self::CLICK, self::CLICK + 1], $listed[0]['clicks']);
        self::assertSame('20', $listed[0]['amount']);
        self::assertStringContainsString('more than one click', $listed[0]['reason']);

        foreach ([null, self::CLICK + 50] as $click) {
            $unchosen = self::desk()->takeBack(self::ACCOUNT, '9100001', '20.00', $click, self::byHand());
            self::assertSame(OutcomeKind::MALFORMED, $unchosen->kind, $unchosen->message);
        }
        $outcome = self::desk()->takeBack(self::ACCOUNT, '9100001', '20.00', self::CLICK + 1, self::byHand());
        self::assertSame(OutcomeKind::REVERSED, $outcome->kind, $outcome->message);
        self::assertSame(['59.00000', '59.00000', '-20.00000'], self::payouts());
        $result = self::requireScratch()->query('SELECT click_id FROM 202_conversion_logs WHERE click_payout < 0'
            . ' AND user_id = ' . self::ACCOUNT);
        self::assertInstanceOf(\mysqli_result::class, $result);
        self::assertSame([['click_id' => (string) (self::CLICK + 1)]], $result->fetch_all(MYSQLI_ASSOC));
        self::assertSame([], self::desk()->list(self::ACCOUNT)['refunds']);
    }

    public function testARefundClosedOrRecordedByHandStaysSoWhenShopifySendsItAgain(): void
    {
        $this->deliver('orders/paid', self::order());
        self::recordClick(self::CLICK + 1);
        $this->deliver('orders/paid', self::order(self::CLICK + 1));
        $refund = ShopifyAdapterTest::refund(['refund_line_items' => []]);
        $this->deliver('refunds/create', $refund);
        self::assertCount(1, self::desk()->list(self::ACCOUNT)['refunds']);

        // Sent again while still refused, it is the one refund, still listed.
        $this->deliver('refunds/create', $refund);
        self::assertCount(1, self::desk()->list(self::ACCOUNT)['refunds']);

        self::assertTrue(self::desk()->close(self::ACCOUNT, '9100001'));
        $again = $this->deliver('refunds/create', $refund);
        self::assertSame(OutcomeKind::CONFLICT, $again->kind, $again->message);
        self::assertSame([], self::desk()->list(self::ACCOUNT)['refunds'], 'a closed refund stays closed');

        // Another refund of the order, recorded by hand, is not listed again either.
        $other = ShopifyAdapterTest::refund(['id' => 9100002, 'refund_line_items' => []]);
        $this->deliver('refunds/create', $other);
        $outcome = self::desk()->takeBack(self::ACCOUNT, '9100002', '20.00', self::CLICK, self::byHand());
        self::assertSame(OutcomeKind::REVERSED, $outcome->kind, $outcome->message);
        $this->deliver('refunds/create', $other);
        self::assertSame([], self::desk()->list(self::ACCOUNT)['refunds'], 'a recorded refund stays recorded');
        self::assertSame(['59.00000', '59.00000', '-20.00000'], self::payouts());
    }

    public function testARefundInAnotherCurrencyIsNeverRecordedAtShopifysFigure(): void
    {
        $this->deliver('orders/paid', self::order());
        $refused = $this->deliver('refunds/create', ShopifyAdapterTest::refund([
            'refund_line_items' => [], 'transactions' => [
                ['id' => 77001, 'kind' => 'refund', 'status' => 'success', 'amount' => '10.00', 'currency' => 'EUR'],
            ],
        ]));
        self::assertSame(OutcomeKind::CURRENCY_MISMATCH, $refused->kind, $refused->message);

        $listed = self::desk()->list(self::ACCOUNT);
        self::assertSame('USD', $listed['account_currency']);
        self::assertCount(1, $listed['refunds']);
        self::assertSame(['10', 'EUR', true], [
            $listed['refunds'][0]['amount'], $listed['refunds'][0]['currency'], $listed['refunds'][0]['other_currency'],
        ]);

        // The stated figure handed back as it is, or an amount in the refund's own currency, records nothing.
        foreach ([null, 'EUR'] as $currency) {
            $outcome = self::desk()->takeBack(
                self::ACCOUNT,
                '9100001',
                '10.00',
                null,
                self::byHand(),
                RefundsToSettle::THE_PAGE,
                $currency
            );
            self::assertSame(OutcomeKind::CURRENCY_MISMATCH, $outcome->kind, $outcome->message);
            self::assertStringContainsString('USD', $outcome->message);
        }
        self::assertSame(['59.00000'], self::payouts());
        self::assertCount(1, self::desk()->list(self::ACCOUNT)['refunds']);

        // Stated in the account's currency, it is the operator's own conversion.
        $outcome = self::desk()->takeBack(
            self::ACCOUNT,
            '9100001',
            '11.60',
            null,
            self::byHand(),
            RefundsToSettle::THE_PAGE,
            'usd'
        );
        self::assertSame(OutcomeKind::REVERSED, $outcome->kind, $outcome->message);
        self::assertSame(['59.00000', '-11.60000'], self::payouts());
    }

    public function testAWaitingRefundRefusedOutrightLaterIsListedAsRefusedWithItsReason(): void
    {
        $this->deliver('orders/paid', self::order());
        $this->pending(9100001, 71001, '10.00');
        self::assertTrue(self::desk()->list(self::ACCOUNT)['refunds'][0]['waiting']);

        $refused = $this->deliver('refunds/create', self::refundIn('EUR'));
        self::assertSame(OutcomeKind::CURRENCY_MISMATCH, $refused->kind, $refused->message);
        $listed = self::desk()->list(self::ACCOUNT)['refunds'];
        self::assertCount(1, $listed);
        self::assertFalse($listed[0]['waiting']);
        self::assertStringContainsString('EUR', $listed[0]['reason']);
    }

    /** @return array<string, mixed> refund 9100001: one successful transaction of 10.00 in $currency */
    private static function refundIn(string $currency): array
    {
        return ShopifyAdapterTest::refund(['refund_line_items' => [], 'transactions' => [
            ['id' => 77001, 'kind' => 'refund', 'status' => 'success', 'amount' => '10.00', 'currency' => $currency],
        ]]);
    }

    public function testARefusalTheProcessorMadeThatCannotBeKeptIsAnsweredAsAFailureToo(): void
    {
        $db = self::requireScratch();
        $this->deliver('orders/paid', self::order());
        self::q('ALTER TABLE 202_shopify_pending_refunds RENAME COLUMN refused_reason TO refused_reason_away');
        try {
            $receiver = new Receiver(new ShopifyAdapter($db), $db);
            $webhook = ShopifyAdapterTest::webhook('refunds/create', self::refundIn('EUR'), self::SECRET);
            $unkept = $receiver->receive($webhook);
            self::assertSame(OutcomeKind::FAILED, $unkept->kind, $unkept->message);
            self::assertSame(500, $receiver->status($unkept));
        } finally {
            self::q('ALTER TABLE 202_shopify_pending_refunds RENAME COLUMN refused_reason_away TO refused_reason');
        }
        self::assertSame(['59.00000'], self::payouts());
    }

    public function testARefundInTheAccountsCurrencyRefusesAnAmountStatedInAnother(): void
    {
        $this->deliver('orders/paid', self::order());
        $this->pending(9100001, 71001, '10.00');
        $outcome = self::desk()->takeBack(
            self::ACCOUNT,
            '9100001',
            '10.00',
            null,
            self::byHand(),
            RefundsToSettle::THE_PAGE,
            'EUR'
        );
        self::assertSame(OutcomeKind::CURRENCY_MISMATCH, $outcome->kind, $outcome->message);
        self::assertFalse(self::desk()->list(self::ACCOUNT)['refunds'][0]['other_currency']);
        self::assertSame(['59.00000'], self::payouts());
    }

    public function testARefusedRefundThatCannotBeKeptIsAnsweredAsAFailureSoShopifySendsItAgain(): void
    {
        $db = self::requireScratch();
        $this->deliver('orders/paid', self::order());
        self::recordClick(self::CLICK + 1);
        $this->deliver('orders/paid', self::order(self::CLICK + 1));
        $refund = ShopifyAdapterTest::refund(['refund_line_items' => []]);

        self::q('ALTER TABLE 202_shopify_pending_refunds RENAME COLUMN refused_reason TO refused_reason_away');
        try {
            $receiver = new Receiver(new ShopifyAdapter($db), $db);
            $unkept = $receiver->receive(ShopifyAdapterTest::webhook('refunds/create', $refund, self::SECRET));
            self::assertSame(OutcomeKind::FAILED, $unkept->kind, $unkept->message);
            self::assertSame(500, $receiver->status($unkept));
            self::assertStringContainsString('more than one click', $unkept->message);
        } finally {
            self::q('ALTER TABLE 202_shopify_pending_refunds RENAME COLUMN refused_reason_away TO refused_reason');
        }

        // The retry is refused as before, and kept this time.
        $receiver = new Receiver(new ShopifyAdapter($db), $db);
        $kept = $receiver->receive(ShopifyAdapterTest::webhook('refunds/create', $refund, self::SECRET));
        self::assertSame(OutcomeKind::CONFLICT, $kept->kind, $kept->message);
        self::assertSame(200, $receiver->status($kept));
        self::assertCount(1, self::desk()->list(self::ACCOUNT)['refunds']);
        self::assertSame(['59.00000', '59.00000'], self::payouts());
    }

    public function testClosingARefundRecordsNothingAndOnlyItsAccountCan(): void
    {
        $this->deliver('orders/paid', self::order());
        $this->pending(9100001, 71001, '10.00');

        self::assertFalse(self::desk()->close(self::OTHER, '9100001'));
        self::assertFalse(self::desk()->close(self::ACCOUNT, '12abc'));
        self::assertCount(1, self::desk()->list(self::ACCOUNT)['refunds']);

        self::assertTrue(self::desk()->close(self::ACCOUNT, '9100001'));
        self::assertFalse(self::desk()->close(self::ACCOUNT, '9100001'));
        self::assertSame([], self::desk()->list(self::ACCOUNT)['refunds']);
        $this->settle(71001, '10.00');
        self::assertSame(['59.00000'], self::payouts());
    }

    public function testARefundOnFileSentAgainAtAnotherAmountIsNotListedAsNotRecorded(): void
    {
        $this->deliver('orders/paid', self::order());
        $first = $this->deliver('refunds/create', self::refundIn('USD'));
        self::assertSame(OutcomeKind::REVERSED, $first->kind, $first->message);

        $again = $this->deliver('refunds/create', ShopifyAdapterTest::refund(['refund_line_items' => [], 'transactions' => [
            ['id' => 77001, 'kind' => 'refund', 'status' => 'success', 'amount' => '9.00', 'currency' => 'USD'],
        ]]));

        self::assertSame(OutcomeKind::CONFLICT, $again->kind, $again->message);
        self::assertSame([], self::desk()->list(self::ACCOUNT)['refunds'], 'it is recorded: nothing to settle');
        self::assertSame(['59.00000', '-10.00000'], self::payouts());
    }

    public function testARefundWaitingForItsOrderCannotBeClosedAndIsRecordedWhenTheSaleArrives(): void
    {
        $this->pending(9100001, 71001, '10.00');
        self::assertSame([], self::desk()->list(self::ACCOUNT)['refunds'], 'no sale on file: not listed');

        self::assertFalse(self::desk()->close(self::ACCOUNT, '9100001'), 'so it cannot be closed either');
        self::assertStringContainsString('recorded when the sale arrives', (string) self::desk()->closeRefusal(self::ACCOUNT, '9100001'));
        $record = self::desk()->takeBack(self::ACCOUNT, '9100001', '10.00', null, self::byHand());
        self::assertSame(OutcomeKind::NOTHING_TO_REVERSE, $record->kind);
        self::assertStringContainsString('recorded when its sale arrives', $record->message, 'never "close it instead"');

        $this->deliver('orders/paid', self::order());
        $this->settle(71001, '10.00');
        self::assertSame(['59.00000', '-10.00000'], self::payouts());
    }

    public function testARefundWhoseSaleIsGoneCanBeClosedAsItsAnswerSays(): void
    {
        $this->deliver('orders/paid', self::order());
        $this->pending(9100001, 71001, '10.00');
        self::requireScratch()->query('UPDATE 202_conversion_logs SET deleted = 1 WHERE click_id = ' . self::CLICK);

        $record = self::desk()->takeBack(self::ACCOUNT, '9100001', '10.00', null, self::byHand());
        self::assertSame(OutcomeKind::NOTHING_TO_REVERSE, $record->kind);
        self::assertStringContainsString('Close the refund instead', $record->message);
        self::assertNull(self::desk()->closeRefusal(self::ACCOUNT, '9100001'));
        self::assertTrue(self::desk()->close(self::ACCOUNT, '9100001'), 'nothing to settle: it closes, as the answer said');
    }

    public function testARefundClosedByHandAndSentAgainIsNotRecordedWhenItsHeldCopyIsReplayed(): void
    {
        $db = self::requireScratch();
        $this->deliver('orders/paid', self::order());
        $this->deliver('refunds/create', self::refundIn('EUR'));
        self::assertTrue(self::desk()->close(self::ACCOUNT, '9100001'));
        // Shopify sends it again: refused for its currency, and held again.
        $again = $this->deliver('refunds/create', self::refundIn('EUR'));
        self::assertSame(OutcomeKind::CURRENCY_MISMATCH, $again->kind, $again->message);
        self::assertSame([], self::desk()->list(self::ACCOUNT)['refunds'], 'closed: not listed again');
        $db->query("UPDATE 202_users_pref SET user_account_currency = 'EUR' WHERE user_id = " . self::ACCOUNT);
        try {
            $replayed = (new \Prosper202\PaymentNotification\HeldReplay($db))->replay(self::ACCOUNT, 'shopify');

            self::assertSame(0, $replayed['recorded'], 'the operator closed it with nothing recorded');
            self::assertSame(['59.00000'], self::payouts());
            self::assertSame('0', (string) $db->query('SELECT COUNT(*) FROM 202_held_payment_notifications WHERE user_id = '
                . self::ACCOUNT)->fetch_row()[0], 'and its held copy is gone');
        } finally {
            $db->query("UPDATE 202_users_pref SET user_account_currency = 'USD' WHERE user_id = " . self::ACCOUNT);
        }
    }

    public function testAHeldRefundRecordedOnReplayIsNoLongerListed(): void
    {
        $db = self::requireScratch();
        $this->deliver('orders/paid', self::order());
        // An EUR sale, so its EUR refund nets with it once the account counts in EUR.
        $db->query("UPDATE 202_conversion_logs SET currency = 'EUR' WHERE click_id = " . self::CLICK);
        $this->deliver('refunds/create', self::refundIn('EUR'));
        self::assertCount(1, self::desk()->list(self::ACCOUNT)['refunds']);
        $db->query("UPDATE 202_users_pref SET user_account_currency = 'EUR' WHERE user_id = " . self::ACCOUNT);
        try {
            $replayed = (new \Prosper202\PaymentNotification\HeldReplay($db))->replay(self::ACCOUNT, 'shopify');

            self::assertSame(1, $replayed['recorded']);
            self::assertSame(['59.00000', '-10.00000'], self::payouts());
            self::assertSame([], self::desk()->list(self::ACCOUNT)['refunds'], 'recorded: nothing left to settle by hand');
        } finally {
            $db->query("UPDATE 202_users_pref SET user_account_currency = 'USD' WHERE user_id = " . self::ACCOUNT);
        }
    }

    public function testARefundRecordedByHandWhoseTransactionSettlesAtAnotherAmountIsNotListedAsRefused(): void
    {
        $this->deliver('orders/paid', self::order());
        $this->pending(9100001, 71001, '10.00');
        self::assertSame(OutcomeKind::REVERSED, self::desk()->takeBack(self::ACCOUNT, '9100001', '5.00', null, self::byHand())->kind);
        // Its row left open, as after a failure to close it once the reversal landed.
        self::requireScratch()->query('UPDATE 202_shopify_pending_refunds SET completed_at = NULL, refused_reason = NULL WHERE user_id = '
            . self::ACCOUNT . " AND refund_id = '9100001'");

        $this->settle(71001, '10.00');

        self::assertSame([], self::desk()->list(self::ACCOUNT)['refunds'], 'it is recorded, not refused');
        self::assertSame(['59.00000', '-5.00000'], self::payouts());
    }

    public function testARefundRecordedOrClosedByHandLeavesNoHeldCopyToBeRecordedLater(): void
    {
        $db = self::requireScratch();
        $held = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM 202_held_payment_notifications WHERE user_id = '
            . self::ACCOUNT . " AND source = 'shopify' AND kind = 'refund'")->fetch_row()[0];
        $this->deliver('orders/paid', self::order());
        $refused = $this->deliver('refunds/create', self::refundIn('EUR'));
        self::assertSame(OutcomeKind::CURRENCY_MISMATCH, $refused->kind, $refused->message);
        self::assertSame(1, $held(), 'held for the account counting in EUR, and listed');
        self::assertCount(1, self::desk()->list(self::ACCOUNT)['refunds']);

        $outcome = self::desk()->takeBack(self::ACCOUNT, '9100001', '11.60', null, self::byHand(), RefundsToSettle::THE_PAGE, 'USD');

        self::assertSame(OutcomeKind::REVERSED, $outcome->kind, $outcome->message);
        self::assertSame(0, $held(), 'recorded by hand: the held copy goes');

        // Closed by hand instead: the same.
        self::cleanRows();
        self::recordClick(self::CLICK);
        $this->deliver('orders/paid', self::order());
        $this->deliver('refunds/create', self::refundIn('EUR'));
        self::assertSame(1, $held());
        self::assertTrue(self::desk()->close(self::ACCOUNT, '9100001'));
        self::assertSame(0, $held(), 'closed by hand: the held copy goes');
    }

    public function testTheStatusSaysWhatArrivedAndWhatDidNotVerify(): void
    {
        $db = self::requireScratch();
        $none = WebhookStatus::of($db, self::ACCOUNT);
        self::assertNull($none->last);
        self::assertNull($none->lastOrderAt);
        self::assertNull($none->unverifiedAt);

        $unverified = $this->deliver('orders/paid', self::order(), 'a-secret-the-store-does-not-have');
        self::assertSame(OutcomeKind::UNVERIFIED, $unverified->kind);
        $failing = WebhookStatus::of($db, self::ACCOUNT);
        self::assertNull($failing->last);
        self::assertNotNull($failing->unverifiedAt);
        $later = WebhookStatus::of($db, self::ACCOUNT, time() + WebhookStatus::UNVERIFIED_WINDOW + 60);
        self::assertNull($later->unverifiedAt);

        $this->deliver('orders/paid', self::order());
        $receiving = WebhookStatus::of($db, self::ACCOUNT);
        self::assertSame('recorded', $receiving->last['outcome'] ?? null);
        self::assertSame('orders/paid', $receiving->last['topic'] ?? null);
        self::assertNotNull($receiving->lastOrderAt);
        self::assertNull($receiving->unverifiedAt, 'a webhook that verified since is the newer news');

        // A refund settled by hand is not a webhook arriving.
        $this->pending(9100001, 71001, '10.00');
        self::desk()->takeBack(self::ACCOUNT, '9100001', '10.00', null, self::byHand());
        self::assertSame('refunds/create', WebhookStatus::of($db, self::ACCOUNT)->last['topic'] ?? null);
    }

    public function testAnAccountWithASecretSeesUnverifiedShopifyWebhooksWithoutTheirFields(): void
    {
        $db = self::requireScratch();
        $this->deliver('orders/paid', self::order(), 'a-secret-the-store-does-not-have');
        $log = new InboundLog($db);

        self::assertSame([], $log->list(self::ACCOUNT, false, ['endpoint' => 'shopify']));
        $seen = $log->list(self::ACCOUNT, false, ['endpoint' => 'shopify'], 50, true);
        self::assertCount(1, $seen);
        self::assertSame('unverified', $seen[0]['outcome']);
        self::assertNull($seen[0]['user_id']);
        self::assertNull($seen[0]['fields']);

        $admin = $log->list(self::ACCOUNT, true, ['endpoint' => 'shopify']);
        self::assertSame(['topic' => 'orders/paid'], $admin[0]['fields']);
    }
}
