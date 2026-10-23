<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
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
    private const CLICK = 99050101;
    private const SECOND_CLICK = 99050111;
    private const SHARING_CLICK = 99050121;
    private const EUROS_CLICK = 99050131;

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
        $currencies = [self::FIRST => 'USD', self::SECOND => 'USD', self::SHARING => 'USD', self::EUROS => 'EUR'];
        foreach ($currencies as $user => $currency) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_account_currency = '$currency'");
            self::q("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $user, user_id = $user, aff_network_id = 1,"
                . " aff_campaign_name = 'cb$user',"
                . " aff_campaign_url = 'https://x.vendor.hop.clickbank.net/?tid=[[subid]]',"
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
        self::key(self::FIRST, self::FIRST_KEY);
        self::key(self::SECOND, self::SECOND_KEY);
        self::key(self::SHARING, null);
        self::key(self::EUROS, self::EUROS_KEY);
        self::q('UPDATE 202_users_pref SET cb_verified = 0 WHERE user_id IN (' . self::users() . ')');
        $repo = new MysqlClickRepository(new Connection($db));
        $clicks = [
            self::CLICK => self::FIRST, self::SECOND_CLICK => self::SECOND, self::SHARING_CLICK => self::SHARING,
            self::EUROS_CLICK => self::EUROS,
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
        self::q('DELETE FROM 202_aff_campaigns WHERE user_id IN (' . self::users() . ')');
        self::q('DELETE FROM 202_users_pref WHERE user_id IN (' . self::users() . ')');
        self::q('DELETE FROM 202_users WHERE user_id IN (' . self::users() . ')');
    }

    private static function key(int $user, ?string $key): void
    {
        $value = $key === null ? 'NULL' : self::str($key);
        self::q("UPDATE 202_users_pref SET cb_key = $value WHERE user_id = $user");
    }

    /** @param array<string, mixed> $overrides */
    private function post(array $overrides, string $key): Outcome
    {
        $order = ClickBankAdapterTest::sale($overrides + ['trackingCodes' => [(string) self::CLICK]]);

        return $this->deliver(ClickBankAdapterTest::body($order, $key));
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
        self::assertSame('The notification does not verify with any ClickBank secret key stored in Prosper202'
            . ' (Account > API integrations > ClickBank); nothing was recorded.', $stranger->message);

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
        self::assertSame([], self::conversions());
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
        $outcome = $this->post(['transactionType' => 'ABANDONED_ORDER'], self::FIRST_KEY);

        self::assertOutcome(OutcomeKind::IGNORED, 200, $outcome);
        self::assertSame(self::FIRST, $outcome->accountId);
        self::assertSame([], self::conversions());
        self::assertStringContainsString(
            'ClickBank notification: ignored (200), ABANDONED_ORDER (ignored), account ' . self::FIRST,
            (string) file_get_contents($this->log)
        );
    }
}
