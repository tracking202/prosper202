<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Outcome;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Receiver;
use Prosper202\PaymentNotification\Response;
use Prosper202\PaymentNotification\Zaxaa\ZaxaaAdapter;
use Prosper202\PaymentNotification\Zaxaa\ZaxaaSignature;

/**
 * Zaxaa's ZPN end to end on a real server: the key read from
 * 202_users_pref, the hash_key check, the processor and the real conversion writer
 * (p202RecordLegacyConversion()), and the rows it leaves. The endpoint itself is a few lines around Receiver;
 * the live pass posts to it (see the pull request).
 *
 * @group integration
 */
final class ZaxaaZpnIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ACCOUNT = 991401;
    private const OTHER = 991402;
    private const SHARING = 991403;
    /** An account deleted in Account › Users, whose preferences row still holds its key. */
    private const DELETED = 991405;
    private const CLICK = 99140101;
    private const SECOND_CLICK = 99140102;
    private const OTHER_CLICK = 99140111;
    private const SHARING_CLICK = 99140121;
    private const DELETED_CLICK = 99140141;
    private const CAMPAIGN = 991461;
    private const OTHER_CAMPAIGN = 991462;
    private const SHARING_CAMPAIGN = 991463;
    private const DELETED_CAMPAIGN = 991465;

    private const KEY = 'zaxaa-ACCOUNT-sig-1';
    private const OTHER_KEY = 'zaxaa-OTHER-sig-77';

    private const RECEIPT = 'ZX-50001';

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
            self::DELETED => self::DELETED_CAMPAIGN,
        ];
        foreach ($campaigns as $user => $campaign) {
            self::user($user);
            self::q("INSERT INTO 202_users_pref SET user_id = $user, user_account_currency = 'USD'");
            self::q("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $campaign, user_id = $user,"
                . " aff_network_id = 1, aff_campaign_name = 'zx$campaign',"
                . " aff_campaign_url = 'https://seller.zaxaa.com/order/9c1a05b0?custom=[[subid]]',"
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
        self::q('DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs'
            . ' WHERE click_id BETWEEN ' . self::CLICK . ' AND ' . (self::CLICK + 99) . ')');
        self::q('DELETE FROM 202_conversion_logs WHERE click_id BETWEEN ' . self::CLICK . ' AND ' . (self::CLICK + 99));
        self::key(self::ACCOUNT, self::KEY);
        self::key(self::OTHER, self::OTHER_KEY);
        self::key(self::SHARING, null);
        self::key(self::DELETED, null);
        $repo = new MysqlClickRepository(new Connection($db));
        $clicks = [
            self::CLICK => [self::ACCOUNT, self::CAMPAIGN],
            self::SECOND_CLICK => [self::ACCOUNT, self::CAMPAIGN],
            self::OTHER_CLICK => [self::OTHER, self::OTHER_CAMPAIGN],
            self::SHARING_CLICK => [self::SHARING, self::SHARING_CAMPAIGN],
            self::DELETED_CLICK => [self::DELETED, self::DELETED_CAMPAIGN],
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
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-zx-log');
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
        $users = self::ACCOUNT . ', ' . self::OTHER . ', ' . self::SHARING . ', ' . self::DELETED;
        self::q("DELETE FROM 202_aff_campaigns WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users_pref WHERE user_id IN ($users)");
        self::q("DELETE FROM 202_users WHERE user_id IN ($users)");
    }

    private static function key(int $user, ?string $key): void
    {
        $value = $key === null ? 'NULL' : self::str($key);
        self::q('UPDATE 202_users_pref SET ' . ZaxaaSignature::COLUMN . ' = ' . $value . " WHERE user_id = $user");
    }

    /**
     * A sale on a click (CLICK unless named), every documented ZPN variable present.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function fields(array $overrides = [], ?int $click = self::CLICK): array
    {
        $fields = ZaxaaAdapterTest::sale($overrides + [
            'trans_receipt' => self::RECEIPT, 'trans_date' => gmdate('Y-m-d H:i:s') . ' UTC',
        ]);
        if (!array_key_exists('products', $overrides)) {
            $fields['products'][0]['custom'] = $click === null ? '' : (string) $click;
        }

        return $fields;
    }

    /** @param array<string, mixed> $fields */
    private function post(array $fields, string $signature): Outcome
    {
        return $this->deliver(ZaxaaAdapterTest::signed($fields, $signature));
    }

    /** @param array<array-key, mixed> $body */
    private function deliver(array $body, string $method = 'POST'): Outcome
    {
        $type = 'application/x-www-form-urlencoded';
        $request = new InboundRequest($method, $type, $body, '203.0.113.9', '203.0.113.9', 'Zaxaa ZPN');

        return (new Receiver(new ZaxaaAdapter(), self::requireScratch()))->receive($request);
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

    public function testASaleIsRecordedOnTheCustomClickUnderItsReceiptAtItsAmount(): void
    {
        $outcome = $this->post(self::fields(), self::KEY);

        self::assertOutcome(OutcomeKind::RECORDED, 200, $outcome);
        self::assertSame('Recorded transaction ' . self::RECEIPT . ' on click ' . self::CLICK . ' at 47.00.', $outcome->message);
        self::assertSame(self::ACCOUNT, $outcome->accountId);
        $row = self::conversions()[0];
        unset($row['conv_id']);
        self::assertSame([
            'click_id' => (string) self::CLICK, 'user_id' => (string) self::ACCOUNT, 'transaction_id' => self::RECEIPT,
            'click_payout' => '47.00000', 'source' => 'zaxaa', 'pixel_type' => '2', 'reverses_conv_id' => null,
            'deleted' => '0', 'ip' => '203.0.113.9',
        ], $row);
        $log = (string) file_get_contents($this->log);
        self::assertStringContainsString('Zaxaa notification: recorded (200)', $log);
        foreach (['buyer@example.com', self::KEY] as $private) {
            self::assertStringNotContainsString($private, $log);
        }
    }

    public function testAReplayIsADuplicateAndARebillIsAnotherSale(): void
    {
        $this->post(self::fields(), self::KEY);
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->post(self::fields(), self::KEY));

        $rebill = self::fields(['trans_type' => 'REBILL', 'trans_receipt' => 'ZX-50002', 'trans_amount' => '10.00']);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->post($rebill, self::KEY));
        self::assertSame(['47.00000', '10.00000'], array_column(self::conversions(), 'click_payout'));
    }

    /** hash_key signs no click: the same signed sale aimed at another click of the account records nothing. */
    public function testASignedSaleReAimedAtAnotherClickIsRefused(): void
    {
        $sale = ZaxaaAdapterTest::signed(self::fields(), self::KEY);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->deliver($sale));

        $sale['products'][0]['custom'] = (string) self::SECOND_CLICK;
        $outcome = $this->deliver($sale);

        self::assertOutcome(OutcomeKind::CONFLICT, 409, $outcome);
        self::assertStringContainsString('not a new payment', $outcome->message);
        self::assertSame([(string) self::CLICK], array_column(self::conversions(), 'click_id'));
        self::assertStringContainsString('refused as a re-aimed copy', (string) file_get_contents($this->log));
    }

    /** hash_key joins receipt and amount with no separator: digits moved across the join record nothing. */
    public function testDigitsMovedBetweenTheReceiptAndTheAmountAreRefused(): void
    {
        $sale = ZaxaaAdapterTest::signed(self::fields(), self::KEY);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->deliver($sale));

        $longer = ['trans_receipt' => self::RECEIPT . '4', 'trans_amount' => '7.00'] + $sale;
        $shorter = ['trans_receipt' => 'ZX-5000', 'trans_amount' => '147.00'] + $sale;
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $this->deliver($longer));
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $this->deliver($shorter));
        $shorter['products'][0]['custom'] = (string) self::SECOND_CLICK;
        self::assertOutcome(OutcomeKind::CONFLICT, 409, $this->deliver($shorter));
        self::assertSame([self::RECEIPT], array_column(self::conversions(), 'transaction_id'));
    }

    /** Only a copy of a signed sale is refused: other sales of the account, and another account's, still record. */
    public function testOtherSalesOnOtherClicksStillRecord(): void
    {
        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->post(self::fields(), self::KEY));

        $next = self::fields(['trans_receipt' => 'ZX-50003'], self::SECOND_CLICK);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->post($next, self::KEY));
        $prefixed = self::fields(['trans_receipt' => self::RECEIPT . '7', 'trans_amount' => '47.00'], self::SECOND_CLICK);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->post($prefixed, self::KEY));
        $other = self::fields([], self::OTHER_CLICK);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $this->post($other, self::OTHER_KEY));

        self::assertSame(
            [self::RECEIPT, 'ZX-50003', self::RECEIPT . '7', self::RECEIPT],
            array_column(self::conversions(), 'transaction_id')
        );
    }

    public function testARefundReversesTheSaleItsReceiptNames(): void
    {
        $this->post(self::fields(), self::KEY);
        $sale = self::conversions()[0]['conv_id'];

        $refund = $this->post(self::fields(['trans_type' => 'REFUND']), self::KEY);
        self::assertOutcome(OutcomeKind::REVERSED, 200, $refund);
        $rows = self::conversions();
        self::assertSame(['-47.00000', $sale, 'zaxaa'], [$rows[1]['click_payout'], $rows[1]['reverses_conv_id'], $rows[1]['source']]);
        self::assertOutcome(OutcomeKind::DUPLICATE, 200, $this->post(self::fields(['trans_type' => 'REFUND']), self::KEY));

        $never = $this->post(self::fields(['trans_type' => 'REFUND', 'trans_receipt' => 'NEVER1']), self::KEY);
        self::assertOutcome(OutcomeKind::NOTHING_TO_REVERSE, 404, $never);
        self::assertCount(2, self::conversions());
    }

    public function testNothingIsWrittenForAnUnsignedOrForgedNotification(): void
    {
        $forged = $this->post(self::fields(), 'a-signature-nobody-stored');
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $forged);
        self::assertStringContainsString('hash_key', $forged->message);

        $signed = ZaxaaAdapterTest::signed(self::fields(), self::KEY);
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $this->deliver(['trans_amount' => '4700.00'] + $signed));
        unset($signed['hash_key']);
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $this->deliver($signed));
        $get = $this->deliver(ZaxaaAdapterTest::signed(self::fields(), self::KEY), 'GET');
        self::assertOutcome(OutcomeKind::METHOD_NOT_ALLOWED, 405, $get);
        self::assertSame([], self::conversions());
    }

    public function testTheAccountIsTheOneWhoseSignatureVerifiesNeverADeletedOnes(): void
    {
        $other = $this->post(self::fields(['trans_receipt' => 'O1'], self::OTHER_CLICK), self::OTHER_KEY);
        self::assertOutcome(OutcomeKind::RECORDED, 200, $other);
        self::assertSame(self::OTHER, $other->accountId);
        // Another account's click under this account's signature is not recorded.
        $cross = $this->post(self::fields(['trans_receipt' => 'O2'], self::OTHER_CLICK), self::KEY);
        self::assertNotSame(OutcomeKind::RECORDED, $cross->kind);

        self::key(self::DELETED, 'zaxaa-DELETED-sig-9');
        $deleted = $this->post(self::fields(['trans_receipt' => 'D1'], self::DELETED_CLICK), 'zaxaa-DELETED-sig-9');
        self::assertOutcome(OutcomeKind::UNVERIFIED, 403, $deleted);
        self::assertCount(1, self::conversions());
    }

    public function testTestsCancellationsAndNoClickRecordNothing(): void
    {
        foreach ([['trans_testmode' => '1'], ['trans_type' => 'CANCELED']] as $overrides) {
            $outcome = $this->post(self::fields($overrides), self::KEY);
            self::assertSame(200, Response::status($outcome->kind), $outcome->message);
        }
        $noClick = $this->post(self::fields([], null), self::KEY);
        self::assertOutcome(OutcomeKind::NO_CLICK, 200, $noClick);
        self::assertStringContainsString('custom=[[subid]]', $noClick->message);
        self::assertSame([], self::conversions());
    }
}
