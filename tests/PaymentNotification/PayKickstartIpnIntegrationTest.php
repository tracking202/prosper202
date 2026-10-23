<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Notification;
use Prosper202\PaymentNotification\NotificationKind;
use Prosper202\PaymentNotification\Outcome;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartAdapter;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartKeys;
use Prosper202\PaymentNotification\Processor;
use Prosper202\PaymentNotification\Receiver;
use Prosper202\PaymentNotification\Response;
use Prosper202\PaymentNotification\Verification;

/**
 * PayKickstart's product IPN end to end on a real server: the keys read
 * from 202_users_pref, the signature, the processor and the real
 * conversion writer (p202RecordLegacyConversion()), and the rows it leaves.
 * The endpoint itself is three lines around Receiver; the live pass posts to
 * it (see the pull request).
 *
 * @group integration
 */
final class PayKickstartIpnIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ACCOUNT = 990401;
    private const OTHER = 990402;
    private const SHARING = 990403;
    private const CLICK = 99040101;
    private const OTHER_CLICK = 99040111;
    private const SHARING_CLICK = 99040121;
    private const CAMPAIGN = 990461;
    private const OTHER_CAMPAIGN = 990462;
    private const SHARING_CAMPAIGN = 990463;

    private const KEY_1 = 'campaign-one-KEY-0001';
    private const KEY_2 = 'campaign-two-KEY-0002';
    private const OTHER_KEY = 'other-account-KEY-77';

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
        $campaigns = [
            self::ACCOUNT => self::CAMPAIGN,
            self::OTHER => self::OTHER_CAMPAIGN,
            self::SHARING => self::SHARING_CAMPAIGN,
        ];
        foreach ($campaigns as $user => $campaign) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_account_currency = 'USD'");
            self::q("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $campaign, user_id = $user,"
                . " aff_network_id = 1, aff_campaign_name = 'pk$campaign',"
                . " aff_campaign_url = 'https://app.paykickstart.com/checkout/1?subid=[[subid]]',"
                . ' aff_campaign_payout = 30, aff_campaign_time = 1, aff_campaign_foreign_payout = 30,'
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
        foreach (self::CLICK_TABLES as $table) {
            self::q("DELETE FROM $table WHERE click_id BETWEEN " . self::CLICK . ' AND ' . (self::CLICK + 99));
        }
        self::q('DELETE FROM 202_conversion_logs WHERE click_id BETWEEN ' . self::CLICK . ' AND ' . (self::CLICK + 99));
        self::keys(self::ACCOUNT, self::KEY_1 . "\n" . self::KEY_2);
        self::keys(self::OTHER, self::OTHER_KEY);
        self::keys(self::SHARING, null);
        $repo = new MysqlClickRepository(new Connection($db));
        foreach (
            [self::CLICK => [self::ACCOUNT, self::CAMPAIGN], self::OTHER_CLICK => [self::OTHER, self::OTHER_CAMPAIGN],
            self::SHARING_CLICK => [self::SHARING, self::SHARING_CAMPAIGN]] as $click => [$user, $campaign]
        ) {
            $repo->recordClick(new ClickRecord(
                clickId: $click,
                userId: $user,
                clickTime: time() - 600,
                affCampaignId: $campaign,
                clickPayout: '30.00',
                recordsCampaignRows: false,
            ));
        }
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-pk-log');
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
        $users = self::ACCOUNT . ', ' . self::OTHER . ', ' . self::SHARING;
        self::q("DELETE FROM 202_aff_campaigns WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users_pref WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users WHERE user_id IN ($users)");
    }

    private static function keys(int $user, ?string $keys): void
    {
        $value = $keys === null ? 'NULL' : self::str($keys);
        self::q('UPDATE 202_users_pref SET ' . PayKickstartKeys::COLUMN . ' = ' . $value . " WHERE user_id = $user");
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function fields(array $overrides = []): array
    {
        return $overrides + [
            'event' => 'sales', 'mode' => 'live', 'payment_processor' => 'stripe', 'is_rebill' => '0',
            'amount' => '47.00',
            'quantity' => '1', 'buyer_first_name' => 'Ruggero', 'buyer_email' => 'buyer@example.com',
            'transaction_id' => 'PK-TX-0001', 'invoice_id' => 'PK-INV-0001', 'transaction_time' => (string) time(),
            'product_id' => '2354', 'campaign_id' => '215', 'custom_subid' => (string) self::CLICK,
            'affiliate_commission_amount' => '0', 'licenses' => ['LIC-1', 'LIC-2'],
        ];
    }

    /** @param array<string, mixed> $fields */
    private function post(array $fields, string $key): Outcome
    {
        return $this->deliver(PayKickstartAdapterTest::signed($fields, $key));
    }

    /** @param array<array-key, mixed> $body */
    private function deliver(array $body, string $method = 'POST'): Outcome
    {
        $type = 'application/x-www-form-urlencoded';
        $request = new InboundRequest($method, $type, $body, '203.0.113.9', '203.0.113.9', 'PayKickstart IPN');

        return (new Receiver(new PayKickstartAdapter(), self::requireScratch()))->receive($request);
    }

    /** @return list<array<string, string|null>> */
    private static function conversions(): array
    {
        $result = self::requireScratch()->query('SELECT click_id, user_id, transaction_id, click_payout, source,'
            . ' pixel_type, reverses_conv_id, deleted, ip FROM 202_conversion_logs WHERE click_id BETWEEN '
            . self::CLICK . ' AND ' . (self::CLICK + 99) . ' ORDER BY conv_id');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /** @return list<string> */
    private static function conversionIds(): array
    {
        $result = self::requireScratch()->query('SELECT conv_id FROM 202_conversion_logs WHERE click_id = '
            . self::CLICK . ' ORDER BY conv_id');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'conv_id');
    }

    private static function assertOutcome(OutcomeKind $kind, int $status, Outcome $outcome): void
    {
        self::assertSame($kind, $outcome->kind, $outcome->message);
        self::assertSame($status, Response::status($outcome->kind));
    }

    public function testASaleIsRecordedOnItsClickUnderItsTransactionIdAtItsAmount(): void
    {
        $outcome = $this->post(self::fields(), self::KEY_2);

        self::assertOutcome(OutcomeKind::RECORDED, 200, $outcome);
        self::assertSame('Recorded transaction PK-TX-0001 on click ' . self::CLICK . ' at 47.00.', $outcome->message);
        self::assertSame(self::ACCOUNT, $outcome->accountId);
        self::assertSame([[
            'click_id' => (string) self::CLICK, 'user_id' => (string) self::ACCOUNT, 'transaction_id' => 'PK-TX-0001',
            'click_payout' => '47.00000', 'source' => 'paykickstart', 'pixel_type' => '2', 'reverses_conv_id' => null,
            'deleted' => '0', 'ip' => '203.0.113.9',
        ]], self::conversions());

        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('PayKickstart notification: recorded (200), sales (sale), click ' . self::CLICK
            . ', transaction PK-TX-0001, account ' . self::ACCOUNT, $log);
        foreach (['buyer@example.com', 'Ruggero', self::KEY_1, self::KEY_2] as $private) {
            self::assertStringNotContainsString($private, $log);
        }
    }

    public function testAReplayIsADuplicateAndAnotherAmountUnderItsIdIsRefused(): void
    {
        $this->post(self::fields(), self::KEY_1);

        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->post(self::fields(), self::KEY_1));
        $changed = $this->post(self::fields(['amount' => '48.00']), self::KEY_1);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $changed);
        self::assertSame('Transaction PK-TX-0001 is already recorded on click ' . self::CLICK
            . ' at 47; this notification states 48.00. Nothing was changed.', $changed->message);
        self::assertCount(1, self::conversions());
    }

    public function testARebillIsAnotherSaleOnTheClick(): void
    {
        $this->post(self::fields(), self::KEY_1);
        $rebill = ['is_rebill' => '1', 'transaction_id' => 'PK-TX-0002', 'amount' => '9'];
        $outcome = $this->post(self::fields($rebill), self::KEY_1);

        self::assertOutcome(OutcomeKind::RECORDED, 200, $outcome);
        self::assertSame(['47.00000', '9.00000'], array_column(self::conversions(), 'click_payout'));
        // subscription-payment fires for the same transaction: not recorded twice.
        $payment = $this->post(self::fields(['event' => 'subscription-payment'] + $rebill), self::KEY_1);
        self::assertOutcome(OutcomeKind::IGNORED, 200, $payment);
        self::assertCount(2, self::conversions());
    }

    public function testARefundReversesTheSaleItNamesOnce(): void
    {
        $this->post(self::fields(), self::KEY_1);
        $sale = (int) self::conversionIds()[0];

        $refund = $this->post(self::fields(['event' => 'refund', 'amount' => '']), self::KEY_1);
        self::assertOutcome(OutcomeKind::REVERSED, 200, $refund);
        self::assertSame(
            'Reversed transaction PK-TX-0001 on click ' . self::CLICK . ' by its full amount.',
            $refund->message
        );
        $rows = self::conversions();
        self::assertCount(2, $rows);
        self::assertSame(
            ['-47.00000', (string) $sale, 'paykickstart'],
            [$rows[1]['click_payout'], $rows[1]['reverses_conv_id'], $rows[1]['source']]
        );

        $again = $this->post(self::fields(['event' => 'refund', 'amount' => '']), self::KEY_1);
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $again);
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->post(self::fields(['event' => 'refund']), self::KEY_1));
        $second = $this->post(self::fields(['event' => 'refund', 'amount' => '10.00']), self::KEY_1);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $second);
        self::assertSame('Transaction PK-TX-0001 on click ' . self::CLICK . ' is already reversed by 47; this refund'
            . ' states 10.00, and a sale is reversed once. Nothing was changed.', $second->message);
        self::assertCount(2, self::conversions());
    }

    public function testAPartialRefundTakesBackItsAmount(): void
    {
        $this->post(self::fields(), self::KEY_1);

        $partial = $this->post(self::fields(['event' => 'refund', 'amount' => '-10.5']), self::KEY_1);
        self::assertOutcome(OutcomeKind::REVERSED, 200, $partial);
        self::assertSame(['47.00000', '-10.50000'], array_column(self::conversions(), 'click_payout'));

        // The same partial refund again, however its amount is spelled.
        foreach (['-10.5', '10.50', '10.50000'] as $amount) {
            $again = $this->post(self::fields(['event' => 'refund', 'amount' => $amount]), self::KEY_1);
            self::assertOutcome(OutcomeKind::DUPLICATE, 200, $again);
        }
        self::assertCount(2, self::conversions());
    }

    public function testAFullRefundAfterAPartialOneIsRefusedNamingWhatIsStillCredited(): void
    {
        $this->post(self::fields(), self::KEY_1);
        $this->post(self::fields(['event' => 'refund', 'amount' => '10.50']), self::KEY_1);

        // No amount, and 0, each state a full refund: not the reversal on file.
        foreach (['', '0', '0.00'] as $amount) {
            $full = $this->post(self::fields(['event' => 'refund', 'amount' => $amount]), self::KEY_1);
            self::assertOutcome(OutcomeKind::CONFLICT, 409, $full);
            self::assertSame('Transaction PK-TX-0001 on click ' . self::CLICK . ' is already reversed by 10.5, and'
                . ' 36.5 of its 47 is still credited; this refund states its full amount, 47, and a sale is reversed'
                . ' once. Nothing was changed.', $full->message);
        }
        $fields = self::fields(['event' => 'refund']);
        unset($fields['amount']);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $this->post($fields, self::KEY_1));
        self::assertSame(['47.00000', '-10.50000'], array_column(self::conversions(), 'click_payout'));
    }

    public function testARefundOfTheWholeSaleIsAFullRefundHoweverItIsStated(): void
    {
        $this->post(self::fields(), self::KEY_1);
        $this->post(self::fields(['event' => 'refund', 'amount' => '47']), self::KEY_1);

        foreach (['', '0', '47.00'] as $amount) {
            $full = $this->post(self::fields(['event' => 'refund', 'amount' => $amount]), self::KEY_1);
            self::assertOutcome(OutcomeKind::DUPLICATE, 200, $full);
        }
        $partial = $this->post(self::fields(['event' => 'refund', 'amount' => '5']), self::KEY_1);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $partial);
        self::assertSame('Transaction PK-TX-0001 on click ' . self::CLICK . ' is already reversed by 47; this refund'
            . ' states 5, and a sale is reversed once. Nothing was changed.', $partial->message);
        self::assertSame(['47.00000', '-47.00000'], array_column(self::conversions(), 'click_payout'));
    }

    public function testARefundOfASaleNotOnFileIsA404SayingSo(): void
    {
        $refund = $this->post(self::fields(['event' => 'refund', 'transaction_id' => 'PK-TX-NEVER']), self::KEY_1);

        self::assertOutcome(OutcomeKind::NOTHING_TO_REVERSE, 404, $refund);
        self::assertSame('There is no conversion with transaction id "PK-TX-NEVER" on click ' . self::CLICK
            . ' to reverse. Nothing was changed.', $refund->message);
        self::assertSame([], self::conversions());
    }

    public function testNothingIsWrittenForANotificationNoStoredKeyVerifies(): void
    {
        $forged = $this->post(self::fields(), 'a-key-nobody-stored');
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $forged);

        $signed = PayKickstartAdapterTest::signed(self::fields(), self::KEY_1);
        $tampered = $this->deliver(['amount' => '4700.00'] + $signed);
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $tampered);
        self::assertSame($forged->message, $tampered->message);

        unset($signed['hash']);
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $this->deliver($signed));
        $get = $this->deliver(PayKickstartAdapterTest::signed(self::fields(), self::KEY_1), 'GET');
        self::assertOutcome(OutcomeKind::METHOD_NOT_ALLOWED, 405, $get);
        self::assertSame([], self::conversions());
        self::assertStringContainsString(
            'PayKickstart notification from 203.0.113.9: unverified (403)',
            (string) file_get_contents($this->log)
        );
    }

    public function testAnotherAccountsClickReadsAsNoClick(): void
    {
        // The other account's key, on this account's click; and this
        // account's key on the other account's click.
        $foreign = $this->post(self::fields(), self::OTHER_KEY);
        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $foreign);
        $reverse = $this->post(self::fields(['custom_subid' => (string) self::OTHER_CLICK]), self::KEY_1);
        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $reverse);
        $missing = $this->post(self::fields(['custom_subid' => (string) (self::CLICK + 98)]), self::KEY_1);
        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $missing);
        self::assertSame(
            str_replace((string) self::OTHER_CLICK, 'N', $reverse->message),
            str_replace((string) (self::CLICK + 98), 'N', $missing->message),
            'a click of another account answers as one that does not exist'
        );
        // A refund too.
        $refund = $this->post(self::fields(['event' => 'refund']), self::OTHER_KEY);
        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $refund);
        self::assertSame([], self::conversions());
    }

    public function testAccountsSharingACampaignKeyTheClickDecides(): void
    {
        self::keys(self::SHARING, self::KEY_1);

        $theirClick = ['custom_subid' => (string) self::SHARING_CLICK, 'transaction_id' => 'PK-TX-S1'];
        $theirs = $this->post(self::fields($theirClick), self::KEY_1);
        $ours = $this->post(self::fields(['transaction_id' => 'PK-TX-S2']), self::KEY_1);

        self::assertOutcome(OutcomeKind::RECORDED, 200, $theirs);
        self::assertSame(self::SHARING, $theirs->accountId);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $ours);
        self::assertSame(self::ACCOUNT, $ours->accountId);
        self::assertSame(
            [(string) self::SHARING, (string) self::ACCOUNT],
            array_column(self::conversions(), 'user_id')
        );
    }

    public function testTestModeOtherEventsAndASaleWithNoClickRecordNothing(): void
    {
        self::assertOutcome(OutcomeKind::TEST, 200, $this->post(self::fields(['mode' => 'test']), self::KEY_1));
        $created = $this->post(self::fields(['event' => 'subscription-created']), self::KEY_1);
        self::assertOutcome(OutcomeKind::IGNORED, 200, $created);
        $noClick = $this->post(self::fields(['custom_subid' => '']), self::KEY_1);
        self::assertOutcome(OutcomeKind::NO_CLICK, 200, $noClick);
        self::assertSame('Verified. Transaction PK-TX-0001 names no click (the checkout link carried no subid),'
            . ' so nothing was recorded.', $noClick->message);
        self::assertOutcome(OutcomeKind::MALFORMED, 400, $this->post(self::fields(['amount' => '47,00']), self::KEY_1));
        self::assertSame([], self::conversions());
    }

    public function testAStoredEntryThatIsNotAKeyVerifiesNothing(): void
    {
        self::keys(self::ACCOUNT, "short\n" . self::KEY_2);

        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $this->post(self::fields(), 'short'));
        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->post(self::fields(), self::KEY_2));
        self::assertStringContainsString(
            'account ' . self::ACCOUNT . ' has 1 stored IPN secret key(s) that are not keys',
            (string) file_get_contents($this->log)
        );
    }

    /**
     * The files deployed, the upgrade not run: the keys' column is not there
     * yet, and the answer names the upgrade rather than a bare failure.
     */
    public function testAFailureWhileTheUpgradeIsPendingNamesTheUpgrade(): void
    {
        $versions = array_column(
            self::requireScratch()->query('SELECT version FROM 202_version')->fetch_all(MYSQLI_ASSOC),
            'version'
        );
        self::q('DELETE FROM 202_version');
        self::q("INSERT INTO 202_version SET version = '1.9.78'");
        self::q('ALTER TABLE 202_users_pref CHANGE paykickstart_ipn_secret_keys pk_keys_not_yet text DEFAULT NULL');
        try {
            $outcome = $this->post(self::fields(), self::KEY_1);
        } finally {
            self::q('ALTER TABLE 202_users_pref CHANGE pk_keys_not_yet paykickstart_ipn_secret_keys text DEFAULT NULL');
            self::q('DELETE FROM 202_version');
            foreach ($versions as $version) {
                self::q('INSERT INTO 202_version SET version = ' . self::str((string) $version));
            }
        }

        self::assertOutcome(OutcomeKind::UPGRADE_PENDING, 503, $outcome);
        self::assertStringStartsWith(
            'Nothing was recorded: the database needs an upgrade: its schema is version 1.9.78',
            $outcome->message
        );
        self::assertSame([], self::conversions());
        // Once the schema is this code's, the same failure is a plain one.
        self::q('ALTER TABLE 202_users_pref CHANGE paykickstart_ipn_secret_keys pk_keys_not_yet text DEFAULT NULL');
        try {
            $outcome = $this->post(self::fields(), self::KEY_1);
        } finally {
            self::q('ALTER TABLE 202_users_pref CHANGE pk_keys_not_yet paykickstart_ipn_secret_keys text DEFAULT NULL');
        }
        self::assertOutcome(OutcomeKind::FAILED, 500, $outcome);
    }

    /** No adapter sends a currency yet; the processor refuses one it would have to convert. */
    public function testAnAmountInAnotherCurrencyIsRefused(): void
    {
        $processor = new Processor(self::requireScratch());
        $request = new InboundRequest('POST', '', [], '203.0.113.9', '203.0.113.9', 'test');
        $sale = static fn (string $currency): Notification => new Notification(
            NotificationKind::SALE,
            'sales',
            'PK-CUR-' . $currency,
            null,
            '10',
            $currency,
            self::CLICK
        );

        $verified = new Verification([self::ACCOUNT => 0]);
        $eur = $processor->process($sale('EUR'), $verified, new PayKickstartAdapter(), $request);
        self::assertOutcome(OutcomeKind::CURRENCY_MISMATCH, 422, $eur);
        self::assertSame([], self::conversions());
        $usd = $processor->process($sale('usd'), $verified, new PayKickstartAdapter(), $request);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $usd);
    }
}
