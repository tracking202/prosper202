<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\JVZoo\JVZooAdapter;
use Prosper202\PaymentNotification\JVZoo\JVZooKey;
use Prosper202\PaymentNotification\JVZoo\JVZooRole;
use Prosper202\PaymentNotification\Outcome;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Receiver;
use Prosper202\PaymentNotification\Zaxaa\ZaxaaAdapter;
use Prosper202\PaymentNotification\Zaxaa\ZaxaaSignature;

/**
 * Two payment platforms' sales on one click under the same transaction id
 * (X5TX): a Zaxaa ZPN and a JVZoo JVZIPN, through the receivers and the real
 * writer. Each platform's sale is its own, a retry of either is that sale's
 * duplicate, and each refund takes back its own platform's sale; a
 * platform's notification and a postback for the same sale are still one
 * sale. Before, the second platform's sale was the first's duplicate (the
 * same amount, dropped) or a conflict (another), and its refund took back
 * the first platform's sale.
 *
 * @group integration
 */
final class TwoPlatformsShareATransactionIdIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ACCOUNT = 991501;
    private const CLICK = 99150101;
    private const CAMPAIGN = 991561;
    private const ZAXAA_KEY = 'zaxaa-X5TX-sig-1';
    private const JVZOO_KEY = 'jvzipn-X5TX-key-1';
    /** A receipt both platforms' formats accept. */
    private const RECEIPT = 'AB12345678CD';

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
        self::user(self::ACCOUNT);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::ACCOUNT . ", user_account_currency = 'USD',"
            . ' ' . ZaxaaSignature::COLUMN . ' = ' . self::str(self::ZAXAA_KEY) . ','
            . ' ' . JVZooKey::COLUMN . ' = ' . self::str(self::JVZOO_KEY) . ','
            . ' ' . JVZooRole::ROLELESS_COLUMN . " = 'affiliate'");
        self::q('INSERT INTO 202_aff_campaigns SET aff_campaign_id = ' . self::CAMPAIGN . ', user_id = ' . self::ACCOUNT . ','
            . " aff_network_id = 1, aff_campaign_name = 'x5tx', aff_campaign_url = 'https://example.com/?s=[[subid]]',"
            . " aff_campaign_payout = 30, aff_campaign_time = 1, aff_campaign_foreign_payout = 30, payout_mode = 'accumulate'");
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
        self::clearClicks();
        (new MysqlClickRepository(new Connection($db)))->recordClick(new ClickRecord(
            clickId: self::CLICK,
            userId: self::ACCOUNT,
            clickTime: time() - 600,
            affCampaignId: self::CAMPAIGN,
            clickPayout: '30.00',
            recordsCampaignRows: false,
        ));
        $this->log = (string) tempnam(sys_get_temp_dir(), 'p202-x5tx-log');
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

    public function testEachPlatformsSaleIsItsOwnAndARetryOfEitherIsItsDuplicate(): void
    {
        self::assertOutcome(OutcomeKind::RECORDED, $this->zaxaa('SALE', '47.00'));
        self::assertOutcome(OutcomeKind::RECORDED, $this->jvzoo('SALE', '47.00'));

        self::assertOutcome(OutcomeKind::DUPLICATE, $this->zaxaa('SALE', '47.00'));
        self::assertOutcome(OutcomeKind::DUPLICATE, $this->jvzoo('SALE', '47.00'));
        // Each is compared with its own sale: another amount is refused naming it.
        $changed = $this->jvzoo('SALE', '50.00');
        self::assertOutcome(OutcomeKind::CONFLICT, $changed);
        self::assertStringContainsString('at 47; this notification states 50.00', $changed->message);

        self::assertSame([
            ['source' => 'zaxaa', 'dedupe_key' => 'tx:' . self::RECEIPT, 'click_payout' => '47.00000', 'reverses' => null],
            ['source' => 'jvzoo', 'dedupe_key' => 'ptx:jvzoo:' . self::RECEIPT, 'click_payout' => '47.00000', 'reverses' => null],
        ], array_map(static function (array $row): array {
            unset($row['conv_id']);

            return $row;
        }, self::rows()));
        self::assertSame('94.00000', self::clickPayout());
    }

    public function testASecondPlatformsSaleOfAnotherAmountIsRecorded(): void
    {
        self::assertOutcome(OutcomeKind::RECORDED, $this->jvzoo('SALE', '47.00'));
        self::assertOutcome(OutcomeKind::RECORDED, $this->zaxaa('SALE', '30.00'));

        self::assertSame(['47.00000', '30.00000'], array_column(self::rows(), 'click_payout'));
        self::assertSame(['tx:' . self::RECEIPT, 'ptx:zaxaa:' . self::RECEIPT], array_column(self::rows(), 'dedupe_key'));
    }

    public function testEachRefundTakesBackItsOwnPlatformsSale(): void
    {
        $this->zaxaa('SALE', '47.00');
        $this->jvzoo('SALE', '30.00');
        [$zaxaaSale, $jvzooSale] = array_column(self::rows(), 'conv_id');

        self::assertOutcome(OutcomeKind::REVERSED, $this->jvzoo('RFND', '-30.00'));
        $rows = self::rows();
        self::assertSame($jvzooSale, $rows[2]['reverses']);
        self::assertSame(['jvzoo', '-30.00000'], [$rows[2]['source'], $rows[2]['click_payout']]);
        self::assertSame('47.00000', self::clickPayout());
        self::assertOutcome(OutcomeKind::DUPLICATE, $this->jvzoo('RFND', '-30.00'));

        self::assertOutcome(OutcomeKind::REVERSED, $this->zaxaa('REFUND', '47.00'));
        $rows = self::rows();
        self::assertSame($zaxaaSale, $rows[3]['reverses']);
        self::assertSame(['zaxaa', '-47.00000'], [$rows[3]['source'], $rows[3]['click_payout']]);
        self::assertSame('0.00000', self::clickPayout());
    }

    /** A refund stating more than its sale is capped at its own platform's sale, not the other's. */
    public function testARefundIsCappedAtItsOwnPlatformsSale(): void
    {
        $this->jvzoo('SALE', '47.00');
        $this->zaxaa('SALE', '30.00');

        $refund = $this->zaxaa('REFUND', '40.00');
        self::assertOutcome(OutcomeKind::REVERSED, $refund);
        self::assertStringContainsString('by 30, the whole sale: this REFUND states 40.00', $refund->message);
        self::assertSame('-30.00000', self::rows()[2]['click_payout']);
        self::assertSame('47.00000', self::clickPayout());
    }

    /** The first platform's refund, sent before the second platform's sale, still names its own. */
    public function testARefundOfTheSecondPlatformsSaleLeavesTheFirstStanding(): void
    {
        $this->zaxaa('SALE', '47.00');
        $this->jvzoo('SALE', '47.00');
        self::assertOutcome(OutcomeKind::REVERSED, $this->jvzoo('RFND', '-47.00'));
        // The Zaxaa sale is not reversed, so its own refund still takes it back.
        self::assertOutcome(OutcomeKind::REVERSED, $this->zaxaa('REFUND', '47.00'));
        self::assertSame('0.00000', self::clickPayout());
        self::assertCount(4, self::rows());
    }

    /** A platform's notification and a postback for its sale are still one sale. */
    public function testAPostbackAfterTheNotificationIsItsDuplicate(): void
    {
        self::assertOutcome(OutcomeKind::RECORDED, $this->jvzoo('SALE', '47.00'));
        self::assertSame('duplicate', self::postback('47.00')['reason']);
        self::assertCount(1, self::rows());
    }

    public function testANotificationAfterThePostbackIsItsDuplicateAndItsRefundTakesItBack(): void
    {
        self::assertTrue(self::postback('47.00')['recorded']);
        self::assertOutcome(OutcomeKind::DUPLICATE, $this->zaxaa('SALE', '47.00'));
        // Its refund takes back the postback's row, the sale it merged with.
        self::assertOutcome(OutcomeKind::REVERSED, $this->zaxaa('REFUND', '47.00'));

        $rows = self::rows();
        self::assertSame(['postback', 'zaxaa'], array_column($rows, 'source'));
        self::assertSame($rows[0]['conv_id'], $rows[1]['reverses']);
    }

    private function zaxaa(string $type, string $amount): Outcome
    {
        $fields = ZaxaaAdapterTest::sale([
            'trans_type' => $type, 'trans_receipt' => self::RECEIPT, 'trans_amount' => $amount,
            'trans_date' => gmdate('Y-m-d H:i:s') . ' UTC',
        ]);
        $fields['products'][0]['custom'] = (string) self::CLICK;
        $fields['products'][0]['amount'] = $amount;
        $body = ZaxaaAdapterTest::signed($fields, self::ZAXAA_KEY);
        $request = new InboundRequest('POST', 'application/x-www-form-urlencoded', $body, '203.0.113.9', '203.0.113.9', 'Zaxaa ZPN');

        return (new Receiver(new ZaxaaAdapter(), self::requireScratch()))->receive($request);
    }

    private function jvzoo(string $type, string $amount): Outcome
    {
        $fields = JVZooAdapterTest::sale([
            'ctransaction' => $type, 'caffitid' => (string) self::CLICK, 'ctransreceipt' => self::RECEIPT,
            'ctransamount' => $amount, 'ctranstime' => (string) time(),
        ]);
        $body = JVZooAdapterTest::signed($fields, self::JVZOO_KEY);
        $request = new InboundRequest('POST', 'application/x-www-form-urlencoded', $body, '203.0.113.9', '203.0.113.9', 'JVZoo IPN');

        return (new Receiver(new JVZooAdapter(JVZooRole::AFFILIATE), self::requireScratch()))->receive($request);
    }

    /**
     * A network's postback for the sale: names the id, no platform.
     *
     * @return array<string, mixed>
     */
    private static function postback(string $amount): array
    {
        return p202RecordLegacyConversion(self::requireScratch(), self::CLICK, 2, MatchMethod::CLICK_ID, [
            'user_id' => self::ACCOUNT, 'source' => 'postback', 'transaction_id' => self::RECEIPT,
            'use_pixel_payout' => true, 'payout' => $amount,
        ]);
    }

    /** @return list<array<string, string|null>> */
    private static function rows(): array
    {
        $result = self::requireScratch()->query('SELECT conv_id, source, dedupe_key, click_payout,'
            . ' reverses_conv_id AS reverses FROM 202_conversion_logs WHERE click_id = ' . self::CLICK . ' ORDER BY conv_id');
        self::assertInstanceOf(\mysqli_result::class, $result);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    private static function clickPayout(): string
    {
        $result = self::requireScratch()->query('SELECT click_payout FROM 202_clicks WHERE click_id = ' . self::CLICK
            . ' ORDER BY click_time DESC LIMIT 1');
        self::assertInstanceOf(\mysqli_result::class, $result);

        return (string) $result->fetch_row()[0];
    }

    private static function assertOutcome(OutcomeKind $kind, Outcome $outcome): void
    {
        self::assertSame($kind, $outcome->kind, $outcome->message);
    }

    private static function clearClicks(): void
    {
        $click = self::CLICK;
        self::q("DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id = $click)");
        self::q("DELETE FROM 202_conversion_logs WHERE click_id = $click");
        foreach (self::CLICK_TABLES as $table) {
            self::q("DELETE FROM $table WHERE click_id = $click");
        }
    }

    private static function cleanUp(): void
    {
        self::clearClicks();
        self::q('DELETE FROM 202_aff_campaigns WHERE user_id = ' . self::ACCOUNT);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::ACCOUNT);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::ACCOUNT);
    }
}
