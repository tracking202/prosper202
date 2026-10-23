<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\JVZoo\JVZooAdapter;
use Prosper202\PaymentNotification\JVZoo\JVZooKey;
use Prosper202\PaymentNotification\JVZoo\JVZooRole;
use Prosper202\PaymentNotification\Outcome;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Receiver;
use Prosper202\PaymentNotification\Response;

/**
 * JVZoo's JVZIPN end to end on a real server: the key read from
 * 202_users_pref, the cverify, the processor and the real conversion writer
 * (p202RecordLegacyConversion()), and the rows it leaves, for an affiliate's
 * URL and a vendor's. The endpoint itself is a few lines around Receiver;
 * the live pass posts to it (see the pull request).
 *
 * @group integration
 */
final class JVZooIpnIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ACCOUNT = 990501;
    private const OTHER = 990502;
    private const SHARING = 990503;
    private const CLICK = 99050101;
    private const SECOND_CLICK = 99050102;
    private const OTHER_CLICK = 99050111;
    private const SHARING_CLICK = 99050121;
    private const CAMPAIGN = 990561;
    private const OTHER_CAMPAIGN = 990562;
    private const SHARING_CAMPAIGN = 990563;

    private const KEY = 'jvzipn-ACCOUNT-key-1';
    private const OTHER_KEY = 'jvzipn-OTHER-key-77';

    private const RECEIPT = '5CA16795AA047184W';

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
                . " aff_network_id = 1, aff_campaign_name = 'jvz$campaign',"
                . " aff_campaign_url = 'https://www.jvzoo.com/c/17/414851/?tid=[[subid]]',"
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
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs'
            . ' WHERE click_id BETWEEN ' . self::CLICK . ' AND ' . (self::CLICK + 99) . ')');
        self::q('DELETE FROM 202_conversion_logs WHERE click_id BETWEEN ' . self::CLICK . ' AND ' . (self::CLICK + 99));
        self::key(self::ACCOUNT, self::KEY);
        self::key(self::OTHER, self::OTHER_KEY);
        self::key(self::SHARING, null);
        $repo = new MysqlClickRepository(new Connection($db));
        $clicks = [
            self::CLICK => [self::ACCOUNT, self::CAMPAIGN],
            self::SECOND_CLICK => [self::ACCOUNT, self::CAMPAIGN],
            self::OTHER_CLICK => [self::OTHER, self::OTHER_CAMPAIGN],
            self::SHARING_CLICK => [self::SHARING, self::SHARING_CAMPAIGN],
        ];
        foreach ($clicks as $click => [$user, $campaign]) {
            $repo->recordClick(new ClickRecord(
                clickId: $click,
                userId: $user,
                clickTime: time() - 600,
                affCampaignId: $campaign,
                clickPayout: '30.00',
                recordsCampaignRows: false,
            ));
        }
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-jvz-log');
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

    private static function key(int $user, ?string $key): void
    {
        $value = $key === null ? 'NULL' : self::str($key);
        self::q('UPDATE 202_users_pref SET ' . JVZooKey::COLUMN . ' = ' . $value . " WHERE user_id = $user");
    }

    /**
     * An affiliate's notification on CLICK, every JVZIPN field present.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function fields(array $overrides = []): array
    {
        return JVZooAdapterTest::sale($overrides + [
            'caffitid' => (string) self::CLICK, 'ctransreceipt' => self::RECEIPT, 'ctranstime' => (string) time(),
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function post(array $fields, string $key, ?JVZooRole $role = JVZooRole::AFFILIATE): Outcome
    {
        return $this->deliver(JVZooAdapterTest::signed($fields, $key), $role);
    }

    /** @param array<array-key, mixed> $body */
    private function deliver(array $body, ?JVZooRole $role = JVZooRole::AFFILIATE, string $method = 'POST'): Outcome
    {
        $type = 'application/x-www-form-urlencoded';
        $request = new InboundRequest($method, $type, $body, '203.0.113.9', '203.0.113.9', 'JVZoo IPN');

        return (new Receiver(new JVZooAdapter($role), self::requireScratch()))->receive($request);
    }

    /** @return list<array<string, string|null>> */
    private static function conversions(): array
    {
        $result = self::requireScratch()->query('SELECT conv_id, click_id, user_id, transaction_id, click_payout,'
            . ' source, pixel_type, reverses_conv_id, deleted, ip FROM 202_conversion_logs WHERE click_id BETWEEN '
            . self::CLICK . ' AND ' . (self::CLICK + 99) . ' ORDER BY conv_id');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    private static function assertOutcome(OutcomeKind $kind, int $status, Outcome $outcome): void
    {
        self::assertSame($kind, $outcome->kind, $outcome->message);
        self::assertSame($status, Response::status($outcome->kind));
    }

    public function testAnAffiliatesSaleIsRecordedOnTheTidsClickUnderItsReceiptAtItsAmount(): void
    {
        $outcome = $this->post(self::fields(['cvendthru' => 'subid=' . self::SECOND_CLICK]), self::KEY);

        self::assertOutcome(OutcomeKind::RECORDED, 200, $outcome);
        self::assertSame(
            'Recorded transaction ' . self::RECEIPT . ' on click ' . self::CLICK . ' at 47.00.',
            $outcome->message
        );
        self::assertSame(self::ACCOUNT, $outcome->accountId);
        $row = self::conversions()[0];
        unset($row['conv_id']);
        self::assertSame([
            'click_id' => (string) self::CLICK, 'user_id' => (string) self::ACCOUNT, 'transaction_id' => self::RECEIPT,
            'click_payout' => '47.00000', 'source' => 'jvzoo', 'pixel_type' => '2', 'reverses_conv_id' => null,
            'deleted' => '0', 'ip' => '203.0.113.9',
        ], $row);
        self::assertCount(1, self::conversions(), 'the vendor\'s subid is not the affiliate\'s click');

        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('JVZoo notification: recorded (200), SALE (sale), click ' . self::CLICK
            . ', transaction ' . self::RECEIPT . ', account ' . self::ACCOUNT, $log);
        foreach (['buyer@example.com', 'Joe Buyer', self::KEY] as $private) {
            self::assertStringNotContainsString($private, $log);
        }
    }

    public function testAVendorsSaleIsRecordedOnTheBuyLinksSubidNeverOnTheAffiliatesTid(): void
    {
        // An affiliate's tid that happens to name one of the vendor's clicks.
        $fields = self::fields(['caffitid' => (string) self::SECOND_CLICK, 'cvendthru' => 'subid=' . self::CLICK]);
        $outcome = $this->post($fields, self::KEY, JVZooRole::VENDOR);

        self::assertOutcome(OutcomeKind::RECORDED, 200, $outcome);
        self::assertSame([(string) self::CLICK], array_column(self::conversions(), 'click_id'));
        // Without the vendor's subid, the affiliate's tid credits nothing.
        $tidOnly = self::fields(['caffitid' => (string) self::SECOND_CLICK, 'ctransreceipt' => 'R2']);
        $noSubid = $this->post($tidOnly, self::KEY, JVZooRole::VENDOR);
        self::assertOutcome(OutcomeKind::NO_CLICK, 200, $noSubid);
        self::assertCount(1, self::conversions());
    }

    public function testAReplayIsADuplicateAndAnotherAmountUnderItsReceiptIsRefused(): void
    {
        $this->post(self::fields(), self::KEY);

        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->post(self::fields(), self::KEY));
        $changed = $this->post(self::fields(['ctransamount' => '48.00']), self::KEY);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $changed);
        self::assertSame('Transaction ' . self::RECEIPT . ' is already recorded on click ' . self::CLICK
            . ' at 47; this notification states 48.00. Nothing was changed.', $changed->message);
        self::assertCount(1, self::conversions());
    }

    public function testARebillIsAnotherSaleUnderItsOwnReceipt(): void
    {
        $this->post(self::fields(), self::KEY);
        $bill = ['ctransaction' => 'BILL', 'cprodtype' => 'RECURRING', 'ctransreceipt' => self::RECEIPT . '-B002',
            'ctransamount' => '10.00'];

        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->post(self::fields($bill), self::KEY));
        self::assertSame(['47.00000', '10.00000'], array_column(self::conversions(), 'click_payout'));
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->post(self::fields($bill), self::KEY));
    }

    public function testARefundReversesTheWholeSaleItsReceiptNamesOnce(): void
    {
        $this->post(self::fields(), self::KEY);
        $sale = self::conversions()[0]['conv_id'];

        // JVZoo refunds a payment whole: whatever amount the refund states.
        $refund = $this->post(self::fields(['ctransaction' => 'RFND', 'ctransamount' => '-47.00']), self::KEY);
        self::assertOutcome(OutcomeKind::REVERSED, 200, $refund);
        self::assertSame('Reversed transaction ' . self::RECEIPT . ' on click ' . self::CLICK
            . ' by its full amount.', $refund->message);
        $rows = self::conversions();
        self::assertCount(2, $rows);
        self::assertSame(
            ['-47.00000', $sale, 'jvzoo'],
            [$rows[1]['click_payout'], $rows[1]['reverses_conv_id'], $rows[1]['source']]
        );

        $again = $this->post(self::fields(['ctransaction' => 'RFND']), self::KEY);
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $again);
        // A chargeback after the refund takes back what the refund took.
        $chargeback = $this->post(self::fields(['ctransaction' => 'CGBK']), self::KEY);
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $chargeback);
        self::assertCount(2, self::conversions());
    }

    public function testAChargebackAndAnEcheckChargebackReverseTheirSales(): void
    {
        $this->post(self::fields(), self::KEY);
        $this->post(self::fields(['ctransreceipt' => 'ECHECK0001', 'ctransamount' => '20.00']), self::KEY);

        $chargeback = $this->post(self::fields(['ctransaction' => 'CGBK']), self::KEY);
        self::assertOutcome(OutcomeKind::REVERSED, 200, $chargeback);
        $insf = $this->post(self::fields(['ctransaction' => 'INSF', 'ctransreceipt' => 'ECHECK0001']), self::KEY);
        self::assertOutcome(OutcomeKind::REVERSED, 200, $insf);
        self::assertSame(
            ['47.00000', '20.00000', '-47.00000', '-20.00000'],
            array_column(self::conversions(), 'click_payout')
        );
    }

    public function testARefundOfASaleNotOnFileIsA404SayingSo(): void
    {
        $refund = $this->post(self::fields(['ctransaction' => 'RFND', 'ctransreceipt' => 'NEVER0001']), self::KEY);

        self::assertOutcome(OutcomeKind::NOTHING_TO_REVERSE, 404, $refund);
        self::assertSame('There is no conversion with transaction id "NEVER0001" on click ' . self::CLICK
            . ' to reverse. Nothing was changed.', $refund->message);
        self::assertSame([], self::conversions());
    }

    /** JVZoo sends RFND, then SALE again under the receipt, when a refund is undone. */
    public function testASaleSentAgainAfterItsRefundIsNotAnsweredAsADuplicate(): void
    {
        $this->post(self::fields(), self::KEY);
        $this->post(self::fields(['ctransaction' => 'RFND']), self::KEY);
        $reversal = (int) self::conversions()[1]['conv_id'];

        $again = $this->post(self::fields(), self::KEY);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $again);
        self::assertSame('Transaction ' . self::RECEIPT . ' on click ' . self::CLICK . ' is recorded and reversed by'
            . ' conversion ' . $reversal . '; this SALE states the sale again. Nothing was changed: if the refund was'
            . ' undone, delete conversion ' . $reversal . ' to count the sale.', $again->message);
        self::assertCount(2, self::conversions());

        (new MysqlConversionRepository(new Connection(self::requireScratch())))->softDelete($reversal, self::ACCOUNT);
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->post(self::fields(), self::KEY));
    }

    public function testNothingIsWrittenForANotificationTheStoredKeyDoesNotVerify(): void
    {
        $forged = $this->post(self::fields(), 'a-key-nobody-stored');
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $forged);

        $signed = JVZooAdapterTest::signed(self::fields(), self::KEY);
        $tampered = $this->deliver(['ctransamount' => '4700.00'] + $signed);
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $tampered);
        self::assertSame($forged->message, $tampered->message);
        $moved = $this->deliver(['caffitid' => (string) self::SECOND_CLICK] + $signed);
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $moved);

        unset($signed['cverify']);
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $this->deliver($signed));
        $get = $this->deliver(JVZooAdapterTest::signed(self::fields(), self::KEY), JVZooRole::AFFILIATE, 'GET');
        self::assertOutcome(OutcomeKind::METHOD_NOT_ALLOWED, 405, $get);
        self::assertSame([], self::conversions());
        self::assertStringContainsString(
            'JVZoo notification from 203.0.113.9: unverified (403)',
            (string) file_get_contents($this->log)
        );
    }

    public function testAnotherAccountsClickReadsAsNoClick(): void
    {
        // The other account's key on this account's click, and this
        // account's key on the other account's click.
        $foreign = $this->post(self::fields(), self::OTHER_KEY);
        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $foreign);
        $reverse = $this->post(self::fields(['caffitid' => (string) self::OTHER_CLICK]), self::KEY);
        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $reverse);
        $missing = $this->post(self::fields(['caffitid' => (string) (self::CLICK + 98)]), self::KEY);
        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $missing);
        self::assertSame(
            str_replace((string) self::OTHER_CLICK, 'N', $reverse->message),
            str_replace((string) (self::CLICK + 98), 'N', $missing->message),
            'a click of another account answers as one that does not exist'
        );
        $vendorFields = self::fields(['cvendthru' => 'subid=' . self::OTHER_CLICK]);
        $vendor = $this->post($vendorFields, self::KEY, JVZooRole::VENDOR);
        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $vendor);
        $refund = $this->post(self::fields(['ctransaction' => 'RFND']), self::OTHER_KEY);
        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $refund);
        self::assertSame([], self::conversions());
    }

    public function testAccountsSharingAJvzooKeyTheClickDecides(): void
    {
        self::key(self::SHARING, self::KEY);

        $theirClick = ['caffitid' => (string) self::SHARING_CLICK, 'ctransreceipt' => 'S1'];
        $theirs = $this->post(self::fields($theirClick), self::KEY);
        $ours = $this->post(self::fields(['ctransreceipt' => 'S2']), self::KEY);

        self::assertOutcome(OutcomeKind::RECORDED, 200, $theirs);
        self::assertSame(self::SHARING, $theirs->accountId);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $ours);
        self::assertSame(self::ACCOUNT, $ours->accountId);
    }

    public function testTypesThatMoveNoMoneyANotificationWithNoClickAndAUrlWithNoRoleRecordNothing(): void
    {
        $nothing = [
            'CANCEL-REBILL' => OutcomeKind::IGNORED, 'UNCANCEL-REBILL' => OutcomeKind::IGNORED,
            'TEST' => OutcomeKind::TEST,
        ];
        foreach ($nothing as $type => $kind) {
            self::assertOutcome($kind, 200, $this->post(self::fields(['ctransaction' => $type]), self::KEY));
        }
        $noClick = $this->post(self::fields(['caffitid' => '']), self::KEY);
        self::assertOutcome(OutcomeKind::NO_CLICK, 200, $noClick);
        self::assertSame('Verified. The SALE of receipt ' . self::RECEIPT . ' carries no tid (the affiliate link had'
            . ' no ?tid=[[subid]]), so it names no click; nothing was recorded.', $noClick->message);
        self::assertOutcome(OutcomeKind::MALFORMED, 400, $this->post(self::fields(), self::KEY, null));
        $pennies = $this->post(self::fields(['ctransamount' => '4700']), self::KEY);
        self::assertOutcome(OutcomeKind::MALFORMED, 400, $pennies);
        self::assertSame([], self::conversions());
    }

    public function testAStoredEntryThatIsNotAKeyVerifiesNothing(): void
    {
        self::key(self::ACCOUNT, "jvzipn-ACCOUNT\nkey-1");

        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $this->post(self::fields(), "jvzipn-ACCOUNT\nkey-1"));
        self::assertStringContainsString(
            'account ' . self::ACCOUNT . '\'s stored JVZIPN secret key is not a key',
            (string) file_get_contents($this->log)
        );
        // Stored with spaces around it, it is the key it was (the page and the API trim).
        self::key(self::ACCOUNT, ' ' . self::KEY . ' ');
        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->post(self::fields(), self::KEY));
    }

    /**
     * The files deployed and the upgrade not run: JVZoo needs nothing 1.9.79
     * adds, but any failure while the schema is behind names the upgrade
     * (here the key's column made unreadable), as the Receiver answers it.
     */
    public function testAFailureWhileTheUpgradeIsPendingNamesTheUpgrade(): void
    {
        $versions = array_column(
            self::requireScratch()->query('SELECT version FROM 202_version')->fetch_all(MYSQLI_ASSOC),
            'version'
        );
        self::q('DELETE FROM 202_version');
        self::q("INSERT INTO 202_version SET version = '1.9.78'");
        self::q('ALTER TABLE 202_users_pref CHANGE jvzoo_ipn_secret_key jvzoo_key_gone varchar(250) DEFAULT NULL');
        try {
            $outcome = $this->post(self::fields(), self::KEY);
        } finally {
            self::q('ALTER TABLE 202_users_pref CHANGE jvzoo_key_gone jvzoo_ipn_secret_key varchar(250) DEFAULT NULL');
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
        self::q('ALTER TABLE 202_users_pref CHANGE jvzoo_ipn_secret_key jvzoo_key_gone varchar(250) DEFAULT NULL');
        try {
            $outcome = $this->post(self::fields(), self::KEY);
        } finally {
            self::q('ALTER TABLE 202_users_pref CHANGE jvzoo_key_gone jvzoo_ipn_secret_key varchar(250) DEFAULT NULL');
        }
        self::assertOutcome(OutcomeKind::FAILED, 500, $outcome);
    }
}
