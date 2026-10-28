<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\ClickBank\ClickBankAdapter;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Outcome;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Receiver;
use Prosper202\PaymentNotification\Response;

/**
 * ClickBank's INS end to end on a real server, for more than one account:
 * each account's key read from 202_users_pref.cb_key, the decryption, the
 * processor and the real conversion writer, and the rows they leave.
 * cb202.php is the Receiver and a Slack notice; the live pass posts to it.
 *
 * @group integration
 */
final class ClickBankInsIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const FIRST = 990501;
    private const SECOND = 990502;
    private const SHARING = 990503;
    private const EUROS = 990504;
    /** An account deleted in Account › Users, whose preferences row still holds its key. */
    private const DELETED = 990506;
    private const CLICK = 99050101;
    private const SECOND_CLICK = 99050111;
    private const SHARING_CLICK = 99050121;
    private const EUROS_CLICK = 99050131;
    private const DELETED_CLICK = 99050151;

    private const FIRST_KEY = 'FIRSTACCOUNT0001';
    private const SECOND_KEY = 'SECONDACCOUNT001';
    private const EUROS_KEY = 'EUROACCOUNT00001';

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
        $currencies = [
            self::FIRST => 'USD', self::SECOND => 'USD', self::SHARING => 'USD', self::EUROS => 'EUR',
            self::DELETED => 'USD',
        ];
        foreach ($currencies as $user => $currency) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_account_currency = '$currency'");
            self::q("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $user, user_id = $user, aff_network_id = 1,"
                . " aff_campaign_name = 'cb$user',"
                . " aff_campaign_url = 'https://x.vendor.hop.clickbank.net/?tid=[[subid]]',"
                . ' aff_campaign_payout = 30, aff_campaign_time = 1, aff_campaign_foreign_payout = 30,'
                . " payout_mode = 'accumulate'");
        }
        self::q('UPDATE 202_users SET user_deleted = 1 WHERE user_id = ' . self::DELETED);
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
        self::key(self::FIRST, self::FIRST_KEY);
        self::key(self::SECOND, self::SECOND_KEY);
        self::key(self::SHARING, null);
        self::key(self::EUROS, self::EUROS_KEY);
        self::key(self::DELETED, null);
        self::q('UPDATE 202_users_pref SET cb_verified = 0 WHERE user_id IN (' . self::users() . ')');
        $repo = new MysqlClickRepository(new Connection($db));
        $clicks = [
            self::CLICK => self::FIRST, self::SECOND_CLICK => self::SECOND, self::SHARING_CLICK => self::SHARING,
            self::EUROS_CLICK => self::EUROS, self::DELETED_CLICK => self::DELETED,
        ];
        foreach ($clicks as $click => $user) {
            $repo->recordClick(new ClickRecord(
                clickId: $click,
                userId: $user,
                clickTime: time() - 600,
                affCampaignId: $user,
                clickPayout: '30.00',
                recordsCampaignRows: false,
            ));
        }
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-cb-log');
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

    private static function users(): string
    {
        return implode(', ', [self::FIRST, self::SECOND, self::SHARING, self::EUROS]);
    }

    private static function cleanUp(): void
    {
        $range = 'BETWEEN ' . self::CLICK . ' AND ' . (self::CLICK + 99);
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN'
            . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id $range)");
        foreach ([...self::CLICK_TABLES, '202_conversion_logs'] as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        $users = self::users() . ', ' . self::DELETED;
        self::q("DELETE FROM 202_aff_campaigns WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users_pref WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users WHERE user_id IN ($users)");
    }

    private static function key(int $user, ?string $key): void
    {
        $value = $key === null ? 'NULL' : self::str($key);
        self::q("UPDATE 202_users_pref SET cb_key = $value WHERE user_id = $user");
    }

    /** @param array<string, mixed> $overrides */
    private function post(array $overrides, string $key): Outcome
    {
        $order = ClickBankAdapterTest::sale($overrides + [
            'trackingCodes' => [(string) self::CLICK],
            'transactionTime' => self::stated(time() - 60),
        ]);

        return $this->deliver(ClickBankAdapterTest::body($order, $key));
    }

    /** A Unix time as ClickBank states it, in a zone of its own. */
    private static function stated(int $time): string
    {
        return (new \DateTimeImmutable('@' . $time))->setTimezone(new \DateTimeZone('-06:00'))->format('Y-m-d\TH:i:sP');
    }

    private function deliver(string $body, string $method = 'POST'): Outcome
    {
        $peer = '203.0.113.9';
        $request = new InboundRequest($method, 'application/json', [], $peer, $peer, 'ClickBank', $body);

        return (new Receiver(new ClickBankAdapter(), self::requireScratch()))->receive($request);
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

    /** @return array<int, string> */
    private static function verifiedFlags(): array
    {
        $result = self::requireScratch()->query('SELECT user_id, cb_verified FROM 202_users_pref WHERE user_id IN ('
            . self::users() . ') ORDER BY user_id');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'cb_verified', 'user_id');
    }

    private static function assertOutcome(OutcomeKind $kind, int $status, Outcome $outcome): void
    {
        self::assertSame($kind, $outcome->kind, $outcome->message);
        self::assertSame($status, Response::status($outcome->kind));
    }

    public function testEachAccountsSaleIsRecordedWithItsOwnKey(): void
    {
        $first = $this->post([], self::FIRST_KEY);
        $second = $this->post(['receipt' => 'SECONDR1', 'trackingCodes' => [(string) self::SECOND_CLICK],
            'totalAccountAmount' => 12.5], self::SECOND_KEY);

        self::assertOutcome(OutcomeKind::RECORDED, 200, $first);
        self::assertSame('Recorded transaction CWOGBZLN on click ' . self::CLICK . ' at 37.62.', $first->message);
        self::assertSame(self::FIRST, $first->accountId);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $second);
        self::assertSame(self::SECOND, $second->accountId);
        self::assertSame([
            [
                'click_id' => (string) self::CLICK, 'user_id' => (string) self::FIRST, 'transaction_id' => 'CWOGBZLN',
                'click_payout' => '37.62000', 'source' => 'clickbank', 'pixel_type' => '2', 'reverses_conv_id' => null,
                'deleted' => '0', 'ip' => '203.0.113.9',
            ],
            [
                'click_id' => (string) self::SECOND_CLICK, 'user_id' => (string) self::SECOND,
                'transaction_id' => 'SECONDR1', 'click_payout' => '12.50000', 'source' => 'clickbank',
                'pixel_type' => '2', 'reverses_conv_id' => null, 'deleted' => '0', 'ip' => '203.0.113.9',
            ],
        ], self::conversions());

        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('ClickBank notification: recorded (200), SALE (sale), click ' . self::CLICK
            . ', transaction CWOGBZLN, account ' . self::FIRST, $log);
        self::assertStringContainsString('ClickBank notification: recorded (200), SALE (sale), click '
            . self::SECOND_CLICK . ', transaction SECONDR1, account ' . self::SECOND, $log);
        foreach (['buyer@example.com', 'Test Guy', self::FIRST_KEY, self::SECOND_KEY] as $private) {
            self::assertStringNotContainsString($private, $log);
        }
    }

    /** A notification ClickBank sent late is dated when the sale happened, read with its offset. */
    public function testASaleIsDatedWhenClickBankSaysItHappened(): void
    {
        $clickTime = (int) self::scalar(
            'SELECT click_time FROM 202_clicks WHERE click_id = ' . self::CLICK . ' LIMIT 1'
        );

        $stated = $this->post(['transactionTime' => self::stated($clickTime + 120)], self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $stated);
        self::assertSame(
            (string) ($clickTime + 120),
            self::scalar("SELECT conv_time FROM 202_conversion_logs WHERE transaction_id = 'CWOGBZLN'")
        );

        $before = time();
        $order = ClickBankAdapterTest::sale(['receipt' => 'NOTIMEGIVEN', 'trackingCodes' => [(string) self::CLICK]]);
        unset($order['transactionTime']);
        $untimed = $this->deliver(ClickBankAdapterTest::body($order, self::FIRST_KEY));
        self::assertOutcome(OutcomeKind::RECORDED, 200, $untimed);
        $arrived = (int) self::scalar("SELECT conv_time FROM 202_conversion_logs WHERE transaction_id = 'NOTIMEGIVEN'");
        self::assertGreaterThanOrEqual($before, $arrived, 'no time stated: dated on arrival');

        $badTime = ['receipt' => 'BADTIME1', 'transactionTime' => '2026-13-01T00:00:00-06:00'];
        $bad = $this->post($badTime, self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::MALFORMED, 400, $bad);
        self::assertStringStartsWith('transactionTime is "2026-13-01T00:00:00-06:00"', $bad->message);
        self::assertCount(2, self::conversions());
    }

    private static function scalar(string $sql): ?string
    {
        $result = self::requireScratch()->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_row();

        return $row === null || $row[0] === null ? null : (string) $row[0];
    }

    public function testOneAccountsKeyOnAnotherAccountsClickReadsAsNoClick(): void
    {
        $foreign = $this->post(['trackingCodes' => [(string) self::SECOND_CLICK]], self::FIRST_KEY);
        $missing = $this->post(['trackingCodes' => [(string) (self::CLICK + 98)]], self::FIRST_KEY);

        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $foreign);
        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $missing);
        self::assertSame(
            str_replace((string) self::SECOND_CLICK, 'N', $foreign->message),
            str_replace((string) (self::CLICK + 98), 'N', $missing->message),
            'a click of another account answers as one that does not exist'
        );
        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $this->post([], self::SECOND_KEY));
        self::assertSame([], self::conversions());
    }

    public function testATestMarksOnlyTheAccountWhoseKeyDecryptsItVerified(): void
    {
        $test = $this->post(['transactionType' => 'TEST', 'receipt' => '********'], self::SECOND_KEY);

        self::assertOutcome(OutcomeKind::TEST, 200, $test);
        self::assertSame(self::SECOND, $test->accountId);
        self::assertSame(
            [self::FIRST => '0', self::SECOND => '1', self::SHARING => '0', self::EUROS => '0'],
            self::verifiedFlags()
        );
        self::assertSame([], self::conversions());
        self::assertStringContainsString(
            'ClickBank notification: test (200), TEST (test), account ' . self::SECOND,
            (string) file_get_contents($this->log)
        );
    }

    public function testATestThatCannotBeMarkedVerifiedFailsSoClickBankShowsIt(): void
    {
        self::q('ALTER TABLE 202_users_pref CHANGE cb_verified cb_verified_gone tinyint(1) NOT NULL DEFAULT 0');
        try {
            $test = $this->post(['transactionType' => 'TEST'], self::FIRST_KEY);
        } finally {
            self::q('ALTER TABLE 202_users_pref CHANGE cb_verified_gone cb_verified tinyint(1) NOT NULL DEFAULT 0');
        }

        self::assertOutcome(OutcomeKind::FAILED, 500, $test);
        self::assertStringContainsString('could not mark the key verified', $test->message);
        self::assertStringContainsString(
            'ClickBank notification: the TEST verified for account ' . self::FIRST
            . ', which could not be marked verified',
            (string) file_get_contents($this->log)
        );
    }

    public function testAKeyTwoAccountsStoreIsRefusedAndRecordsNothing(): void
    {
        self::key(self::SHARING, self::FIRST_KEY);

        $sale = $this->post([], self::FIRST_KEY);
        $test = $this->post(['transactionType' => 'TEST'], self::FIRST_KEY);

        self::assertOutcome(OutcomeKind::AMBIGUOUS_ACCOUNT, 409, $sale);
        self::assertOutcome(OutcomeKind::AMBIGUOUS_ACCOUNT, 409, $test);
        self::assertSame([], self::conversions());
        self::assertSame(
            [self::FIRST => '0', self::SECOND => '0', self::SHARING => '0', self::EUROS => '0'],
            self::verifiedFlags()
        );
        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('ClickBank notification: accounts ' . self::FIRST . ', ' . self::SHARING
            . ' store the secret key it decrypts with, so it is refused', $log);
        self::assertStringContainsString('ClickBank notification from 203.0.113.9: ambiguous_account (409)', $log);
        // The other account's own key is not affected.
        $second = $this->post(['trackingCodes' => [(string) self::SECOND_CLICK]], self::SECOND_KEY);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $second);
    }

    /**
     * A deleted account owns no key: the live account that stores its key
     * now is the only one the key names (a notification was refused as a key
     * two accounts store, with nothing in the UI to clear the deleted
     * account's copy), and a key only it stores decrypts nothing. An account
     * switched off in Account › Users still owns its key.
     */
    public function testADeletedAccountsStoredKeyIsNoSecondAccount(): void
    {
        self::key(self::DELETED, self::FIRST_KEY);

        $sale = $this->post([], self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $sale);
        self::assertSame(self::FIRST, $sale->accountId);
        self::assertOutcome(OutcomeKind::TEST, 200, $this->post(['transactionType' => 'TEST'], self::FIRST_KEY));
        self::assertSame('1', self::verifiedFlags()[self::FIRST]);
        self::assertSame('0', (string) self::scalar(
            'SELECT cb_verified FROM 202_users_pref WHERE user_id = ' . self::DELETED
        ));

        self::key(self::DELETED, 'DELETEDACCOUNT01');
        $onlyDeleted = ['trackingCodes' => [(string) self::DELETED_CLICK], 'receipt' => 'DELETEDR1'];
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $this->post($onlyDeleted, 'DELETEDACCOUNT01'));
        self::assertSame(
            [[(string) self::CLICK, (string) self::FIRST]],
            array_map(static fn (array $r): array => [$r['click_id'], $r['user_id']], self::conversions())
        );

        self::q('UPDATE 202_users SET user_active = 0 WHERE user_id = ' . self::SECOND);
        try {
            $off = $this->post(
                ['trackingCodes' => [(string) self::SECOND_CLICK], 'receipt' => 'SECONDOFF'],
                self::SECOND_KEY
            );
        } finally {
            self::q('UPDATE 202_users SET user_active = 1 WHERE user_id = ' . self::SECOND);
        }
        self::assertOutcome(OutcomeKind::RECORDED, 200, $off);
        self::assertSame(self::SECOND, $off->accountId);
    }

    public function testAReplayIsADuplicateAndAnotherAmountUnderItsReceiptIsRefused(): void
    {
        $this->post([], self::FIRST_KEY);

        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->post([], self::FIRST_KEY));
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->post(['totalAccountAmount' => 37.62], self::FIRST_KEY));
        $changed = $this->post(['totalAccountAmount' => '40.00'], self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $changed);
        self::assertSame('Transaction CWOGBZLN is already recorded on click ' . self::CLICK
            . ' at 37.62; this notification states 40.00. Nothing was changed.', $changed->message);
        self::assertCount(1, self::conversions());
    }

    public function testWhatNoStoredKeyDecryptsIsRefusedAndWritesNothing(): void
    {
        $stranger = $this->post([], 'NOBODYSTORESTHIS');
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $stranger);
        self::assertSame('The notification\'s encryption does not verify with any ClickBank secret key stored in'
            . ' Prosper202 (Account > API integrations > ClickBank); nothing was recorded.', $stranger->message);

        // The key a sharing account would hold, before it holds it.
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $this->post(
            ['trackingCodes' => [(string) self::SHARING_CLICK]],
            'SHARINGACCOUNT01'
        ));
        $get = $this->deliver(ClickBankAdapterTest::body(ClickBankAdapterTest::sale(), self::FIRST_KEY), 'GET');
        self::assertOutcome(OutcomeKind::METHOD_NOT_ALLOWED, 405, $get);
        self::assertOutcome(OutcomeKind::MALFORMED, 400, $this->deliver('{"notification":"x"}'));
        self::assertSame([], self::conversions());
        self::assertStringContainsString(
            'ClickBank notification from 203.0.113.9: unverified (403)',
            (string) file_get_contents($this->log)
        );
    }

    public function testAKeyStoredWithSpaceAroundItStillDecrypts(): void
    {
        self::key(self::FIRST, '  ' . self::FIRST_KEY . "\n");

        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->post([], self::FIRST_KEY));
    }

    public function testASaleWithNoTrackingCodeRecordsNothingAndSaysWhy(): void
    {
        $outcome = $this->post(['trackingCodes' => []], self::FIRST_KEY);

        self::assertOutcome(OutcomeKind::NO_CLICK, 200, $outcome);
        self::assertSame('Verified. The SALE of receipt CWOGBZLN carries no tracking code (the HopLink had no'
            . ' tid=[[subid]]), so it names no click; nothing was recorded.', $outcome->message);
        self::assertSame([], self::conversions());
        self::assertStringContainsString(
            'ClickBank notification from 203.0.113.9: no_click (200): Verified. The SALE of receipt CWOGBZLN',
            (string) file_get_contents($this->log)
        );
        self::assertOutcome(
            OutcomeKind::MALFORMED,
            400,
            $this->post(['trackingCodes' => [self::CLICK . '.9']], self::FIRST_KEY)
        );
    }

    /** ClickBank's amounts are USD; an account that counts in another currency is not credited dollars as its own. */
    public function testAnAccountThatCountsInAnotherCurrencyIsRefused(): void
    {
        $outcome = $this->post(['trackingCodes' => [(string) self::EUROS_CLICK]], self::EUROS_KEY);

        self::assertOutcome(OutcomeKind::CURRENCY_MISMATCH, 422, $outcome);
        self::assertSame('The amount is in USD and this account counts in EUR; Prosper202 does not convert it,'
            . ' so nothing was recorded.', $outcome->message);
        self::assertSame([], self::conversions());
    }

    public function testOtherTransactionTypesAreAcknowledgedAndLogged(): void
    {
        $this->post([], self::FIRST_KEY);
        foreach (['CANCEL-REBILL', 'UNCANCEL-REBILL', 'SUBSCRIPTION-CHG', 'ABANDONED_ORDER'] as $type) {
            $outcome = $this->post(['transactionType' => $type], self::FIRST_KEY);
            self::assertOutcome(OutcomeKind::IGNORED, 200, $outcome);
            self::assertSame(self::FIRST, $outcome->accountId);
            self::assertStringContainsString(
                'ClickBank notification: ignored (200), ' . $type . ' (ignored), account ' . self::FIRST,
                (string) file_get_contents($this->log)
            );
        }
        self::assertSame(['37.62000'], array_column(self::conversions(), 'click_payout'));
    }

    public function testATestVersionOfAnotherTypeIsATest(): void
    {
        $outcome = $this->post(['transactionType' => 'TEST_SALE'], self::FIRST_KEY);

        self::assertOutcome(OutcomeKind::TEST, 200, $outcome);
        self::assertSame([], self::conversions());
        self::assertSame('1', self::verifiedFlags()[self::FIRST]);
    }

    public function testATypeClickBankDoesNotListIsRefusedByName(): void
    {
        $outcome = $this->post(['transactionType' => 'RFND-RB'], self::FIRST_KEY);

        self::assertOutcome(OutcomeKind::MALFORMED, 400, $outcome);
        self::assertSame('transactionType is "RFND-RB", which ClickBank\'s Instant Notification Service article does'
            . ' not list; nothing was recorded.', $outcome->message);
        self::assertSame([], self::conversions());
    }

    public function testARebillIsAnotherConversionUnderItsOwnReceipt(): void
    {
        $this->post([], self::FIRST_KEY);
        $rebill = ['transactionType' => 'BILL', 'receipt' => 'CWOGBZLN-0001', 'totalAccountAmount' => '9.95'];

        $outcome = $this->post($rebill, self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $outcome);
        self::assertSame('Recorded transaction CWOGBZLN-0001 on click ' . self::CLICK . ' at 9.95.', $outcome->message);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->post(
            ['receipt' => 'CWOGBZLN-0002', 'totalAccountAmount' => 9.95] + $rebill,
            self::FIRST_KEY
        ));
        self::assertSame(
            [['CWOGBZLN', '37.62000'], ['CWOGBZLN-0001', '9.95000'], ['CWOGBZLN-0002', '9.95000']],
            array_map(static fn (array $r): array => [$r['transaction_id'], $r['click_payout']], self::conversions())
        );
        self::assertSame(['clickbank'], array_values(array_unique(array_column(self::conversions(), 'source'))));
        // The campaign adds conversions up: the click is worth the sale and both rebills.
        self::assertSame('57.52000', self::clickPayout());

        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->post($rebill, self::FIRST_KEY));
        $changed = $this->post(['totalAccountAmount' => '19.95'] + $rebill, self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $changed);
        self::assertCount(3, self::conversions());
        self::assertStringContainsString(
            'ClickBank notification: recorded (200), BILL (rebill), click ' . self::CLICK
            . ', transaction CWOGBZLN-0001, account ' . self::FIRST,
            (string) file_get_contents($this->log)
        );
    }

    public function testARefundReversesTheConversionItsReceiptNamesOnce(): void
    {
        $this->post([], self::FIRST_KEY);
        $sale = self::conversions()[0];
        $refund = [
            'transactionType' => 'RFND', 'totalAccountAmount' => '-37.62', 'transactionTime' => self::stated(time()),
        ];

        $outcome = $this->post($refund, self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::REVERSED, 200, $outcome);
        self::assertSame('Reversed transaction CWOGBZLN on click ' . self::CLICK . ' by 37.62.', $outcome->message);
        $rows = self::conversions();
        self::assertCount(2, $rows);
        self::assertSame(
            ['CWOGBZLN', '-37.62000', 'clickbank', (string) self::FIRST],
            [$rows[1]['transaction_id'], $rows[1]['click_payout'], $rows[1]['source'], $rows[1]['user_id']]
        );
        self::assertNotNull($rows[1]['reverses_conv_id']);
        self::assertSame('0.00000', self::clickPayout());

        // The same refund again: ClickBank's retries of it, its order with the attempt count raised.
        foreach ([2, 3] as $attempt) {
            $again = $this->post(['attemptCount' => $attempt] + $refund, self::FIRST_KEY);
            self::assertOutcome(OutcomeKind::DUPLICATE, 200, $again);
        }
        // Any other reversal of the sale, a chargeback of the same amount included, is refused:
        // a sale is reversed once.
        $reversal = (int) self::conversionIds()[1];
        $chargeback = $this->post(['transactionType' => 'CGBK'] + $refund, self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $chargeback);
        $other = $this->post(['totalAccountAmount' => '-10.00'] + $refund, self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $other);
        self::assertSame('Transaction CWOGBZLN on click ' . self::CLICK . ' is already reversed by 37.62 in conversion '
            . $reversal . '; this RFND is another notification than that reversal\'s and states 10.00, and a sale is'
            . ' reversed once. Nothing was changed.', $other->message);
        self::assertCount(2, self::conversions());
        self::assertSame($sale['transaction_id'], self::conversions()[1]['transaction_id']);
    }

    public function testARefundIsDatedWhenClickBankSaysItHappened(): void
    {
        $clickTime = (int) self::scalar(
            'SELECT click_time FROM 202_clicks WHERE click_id = ' . self::CLICK . ' LIMIT 1'
        );
        $this->post(['transactionTime' => self::stated($clickTime + 60)], self::FIRST_KEY);

        $refund = $this->post([
            'transactionType' => 'RFND', 'totalAccountAmount' => '-37.62',
            'transactionTime' => self::stated($clickTime + 120),
        ], self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::REVERSED, 200, $refund);
        $times = self::requireScratch()->query('SELECT conv_time FROM 202_conversion_logs WHERE click_id = '
            . self::CLICK . ' ORDER BY conv_id');
        self::assertInstanceOf(\mysqli_result::class, $times);
        self::assertSame(
            [(string) ($clickTime + 60), (string) ($clickTime + 120)],
            array_column($times->fetch_all(MYSQLI_ASSOC), 'conv_time')
        );
        $bad = $this->post(['transactionType' => 'CGBK', 'transactionTime' => '20261301T000000-0600'], self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::MALFORMED, 400, $bad);
    }

    public function testAPartialRefundTakesBackWhatTheAccountLost(): void
    {
        $this->post([], self::FIRST_KEY);

        $partial = $this->post(['transactionType' => 'RFND', 'totalAccountAmount' => '-10.00'], self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::REVERSED, 200, $partial);
        self::assertSame(['37.62000', '-10.00000'], array_column(self::conversions(), 'click_payout'));
        self::assertSame('27.62000', self::clickPayout());
        // The rest, afterwards, is not reversed a second time; it says what is still credited.
        $rest = $this->post(['transactionType' => 'RFND', 'totalAccountAmount' => '0'], self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $rest);
        self::assertStringContainsString('27.62 of its 37.62 is still credited', $rest->message);
    }

    /**
     * ClickBank's retry of a notification is its order again, so a refund
     * whose order differs from the one a standing reversal came from is
     * another refund, whatever it states: a second partial refund of the
     * same amount is refused where ClickBank's log shows it, not answered
     * 200 as the first one sent again while the account lost it too.
     */
    public function testAnotherRefundOfTheSameAmountIsRefusedNotAnsweredAsTheFirst(): void
    {
        $this->post([], self::FIRST_KEY);
        $first = [
            'transactionType' => 'RFND', 'totalAccountAmount' => '-10.00',
            'transactionTime' => '2026-10-24T09:00:00-06:00',
        ];
        self::assertOutcome(OutcomeKind::REVERSED, 200, $this->post($first, self::FIRST_KEY));
        $reversal = (int) self::conversionIds()[1];

        $second = $this->post(['transactionTime' => '2026-10-25T09:00:00-06:00'] + $first, self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $second);
        self::assertSame(
            'Transaction CWOGBZLN on click ' . self::CLICK . ' is already reversed by 10 in conversion '
            . $reversal . ', and 27.62 of its 37.62 is still credited; this RFND is another notification than'
            . ' that reversal\'s and states 10.00, and a sale is reversed once. Nothing was changed.',
            $second->message
        );
        $chargeback = $this->post(['transactionType' => 'CGBK'] + $first, self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $chargeback);
        self::assertStringContainsString(
            'this CGBK is another notification than that reversal\'s',
            $chargeback->message
        );

        // ClickBank's own retry of the first is still that refund.
        $retry = $this->post(['attemptCount' => 2] + $first, self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $retry);
        self::assertSame(['37.62000', '-10.00000'], array_column(self::conversions(), 'click_payout'));
        self::assertSame('27.62000', self::clickPayout());
    }

    public function testAChargebackAndAReturnedEcheckReverseTheWholeSaleWhenTheyStateNoAmount(): void
    {
        $this->post([], self::FIRST_KEY);
        $this->post(['receipt' => 'SECONDSL', 'totalAccountAmount' => '20.00'], self::FIRST_KEY);

        $chargeback = $this->post(['transactionType' => 'CGBK', 'totalAccountAmount' => '0.00'], self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::REVERSED, 200, $chargeback);
        self::assertSame(
            'Reversed transaction CWOGBZLN on click ' . self::CLICK . ' by its full amount.',
            $chargeback->message
        );
        $bounced = ClickBankAdapterTest::sale(
            ['transactionType' => 'INSF', 'receipt' => 'SECONDSL', 'trackingCodes' => [(string) self::CLICK]]
        );
        unset($bounced['totalAccountAmount']);
        $insf = $this->deliver(ClickBankAdapterTest::body($bounced, self::FIRST_KEY));
        self::assertOutcome(OutcomeKind::REVERSED, 200, $insf);
        self::assertSame(
            ['37.62000', '20.00000', '-37.62000', '-20.00000'],
            array_column(self::conversions(), 'click_payout')
        );
        self::assertSame('0.00000', self::clickPayout());
        self::assertStringContainsString(
            'ClickBank notification: reversed (200), INSF (chargeback), click ' . self::CLICK
            . ', transaction SECONDSL, account ' . self::FIRST,
            (string) file_get_contents($this->log)
        );
    }

    public function testARefundOfARebillTakesBackOnlyTheRebill(): void
    {
        $this->post([], self::FIRST_KEY);
        $rebill = ['transactionType' => 'BILL', 'receipt' => 'CWOGBZLN-0001', 'totalAccountAmount' => '9.95'];
        $this->post($rebill, self::FIRST_KEY);

        $refund = $this->post(
            ['transactionType' => 'RFND', 'receipt' => 'CWOGBZLN-0001', 'totalAccountAmount' => '-9.95'],
            self::FIRST_KEY
        );
        self::assertOutcome(OutcomeKind::REVERSED, 200, $refund);
        self::assertSame(['37.62000', '9.95000', '-9.95000'], array_column(self::conversions(), 'click_payout'));
        self::assertSame('37.62000', self::clickPayout());
    }

    /** A sale a refund took back is not counted, so the SALE sent again is no duplicate. */
    public function testASaleSentAgainAfterItsRefundIsRefusedNamingTheReversalToDelete(): void
    {
        $this->post([], self::FIRST_KEY);
        $this->post(['transactionType' => 'RFND', 'totalAccountAmount' => '-37.62'], self::FIRST_KEY);
        $reversal = (int) self::conversionIds()[1];

        $again = $this->post(['attemptCount' => 2], self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $again);
        self::assertSame('Transaction CWOGBZLN on click ' . self::CLICK . ' is recorded and reversed by conversion '
            . $reversal . '; this SALE states the sale again. Nothing was changed: if the refund was undone, delete'
            . ' conversion ' . $reversal . ' to count the sale.', $again->message);

        self::conversionRepository()->softDelete($reversal, self::FIRST);
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->post([], self::FIRST_KEY));
    }

    /**
     * A reversal deleted (the refund undone): the same refund, however often
     * ClickBank sends it again, changes nothing, and a later chargeback of
     * the sale reverses it again. ClickBank's order is not its form fields,
     * so the adapter says what one notification is: the order without the
     * attempt count, which a retry raises.
     */
    public function testAReversalAfterTheOneOnFileWasDeletedReversesTheSaleAgain(): void
    {
        $this->post([], self::FIRST_KEY);
        $refund = [
            'transactionType' => 'RFND', 'totalAccountAmount' => '-37.62',
            'transactionTime' => '2026-10-24T09:00:00-06:00',
        ];
        $this->post($refund, self::FIRST_KEY);
        $deleted = (int) self::conversionIds()[1];
        self::conversionRepository()->softDelete($deleted, self::FIRST);

        $retry = $this->post(['attemptCount' => 2] + $refund, self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $retry);
        self::assertSame('Transaction CWOGBZLN on click ' . self::CLICK . ' was reversed by conversion ' . $deleted
            . ', since deleted; this RFND is that refund sent again, so nothing was changed.', $retry->message);

        $chargeback = [
            'transactionType' => 'CGBK', 'totalAccountAmount' => '-37.62',
            'transactionTime' => '2026-11-02T09:00:00-06:00',
        ];
        self::assertOutcome(OutcomeKind::REVERSED, 200, $this->post($chargeback, self::FIRST_KEY));
        self::assertSame(
            [['37.62000', '0'], ['-37.62000', '1'], ['-37.62000', '0']],
            array_map(static fn (array $r): array => [$r['click_payout'], $r['deleted']], self::conversions())
        );
        $chargebackAgain = $this->post(['attemptCount' => 3] + $chargeback, self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $chargebackAgain);
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->post($refund, self::FIRST_KEY));
        self::assertCount(3, self::conversions());
    }

    public function testAReversalOfNothingOnFileOrOfAnotherAccountsClickWritesNothing(): void
    {
        $missing = $this->post(['transactionType' => 'RFND', 'receipt' => 'NEVERSOLD'], self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::NOTHING_TO_REVERSE, 404, $missing);
        self::assertSame('There is no conversion with transaction id "NEVERSOLD" on click ' . self::CLICK
            . ' to reverse. Nothing was changed.', $missing->message);

        $this->post(['trackingCodes' => [(string) self::SECOND_CLICK], 'receipt' => 'SECONDR1'], self::SECOND_KEY);
        $foreign = $this->post(
            ['transactionType' => 'CGBK', 'receipt' => 'SECONDR1', 'trackingCodes' => [(string) self::SECOND_CLICK]],
            self::FIRST_KEY
        );
        self::assertOutcome(OutcomeKind::UNKNOWN_CLICK, 404, $foreign);
        self::assertSame(['37.62000'], array_column(self::conversions(), 'click_payout'));

        $noClick = $this->post(['transactionType' => 'RFND', 'trackingCodes' => []], self::FIRST_KEY);
        self::assertOutcome(OutcomeKind::NO_CLICK, 200, $noClick);
        self::assertCount(1, self::conversions());
    }

    private static function conversionRepository(): MysqlConversionRepository
    {
        return new MysqlConversionRepository(new Connection(self::requireScratch()));
    }

    /** @return list<string> */
    private static function conversionIds(): array
    {
        $result = self::requireScratch()->query('SELECT conv_id FROM 202_conversion_logs WHERE click_id = '
            . self::CLICK . ' ORDER BY conv_id');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'conv_id');
    }

    private static function clickPayout(): string
    {
        $result = self::requireScratch()->query('SELECT click_payout FROM 202_clicks WHERE click_id = ' . self::CLICK
            . ' ORDER BY click_time DESC LIMIT 1');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (string) ($result->fetch_assoc()['click_payout'] ?? '');
    }
}
