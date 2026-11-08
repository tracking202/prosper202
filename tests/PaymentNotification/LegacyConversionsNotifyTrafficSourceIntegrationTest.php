<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartAdapter;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartKeys;
use Prosper202\PaymentNotification\Receiver;

/**
 * The legacy conversion paths tell the click's traffic source, as gpb.php
 * does (QZ3M): pb.php and the payment notifications (ClickBank, JVZoo,
 * PayKickstart, Shopify, Zaxaa) record through p202RecordLegacyConversion(),
 * which queued no server postback, so a sale they recorded never reached the
 * ad network that bought the click. They queue the click's type-4 postbacks
 * with the row now, scoped to the click's own account; px.php, a browser
 * pixel like gpx.php, does not, and a reversal announces nothing.
 *
 * @group integration
 */
final class LegacyConversionsNotifyTrafficSourceIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ACCOUNT = 990601;
    private const CAMPAIGN = 990661;
    private const PPC = 990671;
    private const CLICK = 99060101;
    private const KEY = 'qz3m-campaign-KEY-0001';
    private const PIXEL = 'https://ads.example/pb?s=[[subid]]&tx=[[transactionid]]&p=[[payout]]';

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
        self::cleanUp();
        self::user(self::ACCOUNT);
        self::q('INSERT INTO 202_users_pref SET user_id = ' . self::ACCOUNT . ", user_account_currency = 'USD'");
        self::q('INSERT INTO 202_aff_campaigns SET aff_campaign_id = ' . self::CAMPAIGN . ', user_id = ' . self::ACCOUNT . ','
            . " aff_network_id = 1, aff_campaign_name = 'qz3m', aff_campaign_url = 'https://shop.example/?subid=[[subid]]',"
            . " aff_campaign_payout = 30, aff_campaign_time = 1, aff_campaign_foreign_payout = 30, payout_mode = 'accumulate'");
        self::row('202_ppc_accounts', ['ppc_account_id' => self::PPC, 'user_id' => self::ACCOUNT, 'ppc_network_id' => 0,
            'ppc_account_name' => 'search ads', 'ppc_account_time' => time()]);
        self::row('202_ppc_account_pixels', ['ppc_account_id' => self::PPC, 'pixel_type_id' => 4, 'pixel_code' => self::PIXEL]);
        self::q('UPDATE 202_users_pref SET ' . PayKickstartKeys::COLUMN . ' = ' . self::str(self::KEY)
            . ' WHERE user_id = ' . self::ACCOUNT);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    protected function setUp(): void
    {
        $db = self::requireScratch();
        self::clearClick();
        (new MysqlClickRepository(new Connection($db)))->recordClick(new ClickRecord(
            clickId: self::CLICK,
            userId: self::ACCOUNT,
            clickTime: time() - 600,
            affCampaignId: self::CAMPAIGN,
            clickPayout: '30.00',
            recordsCampaignRows: false,
            ppcAccountId: self::PPC,
        ));
    }

    private static function clearClick(): void
    {
        $click = self::CLICK;
        self::q("DELETE FROM 202_notification_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id = $click)");
        self::q("DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id = $click)");
        foreach (['202_conversion_logs', '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking',
            '202_clicks_rotator', '202_clicks_record', '202_clicks_site', '202_attribution_rollup_dirty_clicks',
            '202_dataengine', '202_click_latest_visits'] as $table) {
            self::q("DELETE FROM $table WHERE click_id = $click");
        }
    }

    private static function cleanUp(): void
    {
        self::clearClick();
        self::q('DELETE FROM 202_ppc_account_pixels WHERE ppc_account_id = ' . self::PPC);
        self::q('DELETE FROM 202_ppc_accounts WHERE ppc_account_id = ' . self::PPC);
        foreach (['202_aff_campaigns', '202_users_pref', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = " . self::ACCOUNT);
        }
    }

    /** @return list<string> the URLs queued for the click's conversions, oldest first */
    private static function queued(): array
    {
        $result = self::requireScratch()->query('SELECT n.url FROM 202_notification_pending n'
            . ' JOIN 202_conversion_logs c USING (conv_id) WHERE c.click_id = ' . self::CLICK
            . " AND n.kind = 'reached' ORDER BY n.notification_id");
        self::assertInstanceOf(\mysqli_result::class, $result, (string) self::requireScratch()->error);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'url');
    }

    /** @param array<string, string> $overrides */
    private static function payKickstart(array $overrides = []): \Prosper202\PaymentNotification\Outcome
    {
        $fields = $overrides + [
            'event' => 'sales', 'mode' => 'live', 'payment_processor' => 'stripe', 'is_rebill' => '0', 'amount' => '47.00',
            'quantity' => '1', 'buyer_email' => 'buyer@example.com', 'transaction_id' => 'QZ3M-TX-1',
            'invoice_id' => 'QZ3M-INV-1', 'transaction_time' => (string) time(), 'product_id' => '1', 'campaign_id' => '1',
            'custom_subid' => (string) self::CLICK, 'affiliate_commission_amount' => '0',
        ];
        $request = new InboundRequest('POST', 'application/x-www-form-urlencoded',
            PayKickstartAdapterTest::signed($fields, self::KEY), '203.0.113.9', '203.0.113.9', 'PayKickstart IPN');

        return (new Receiver(new PayKickstartAdapter(), self::requireScratch()))->receive($request);
    }

    public function testAPaymentNotificationSaleQueuesTheClicksTrafficSourcePostbackAndARefundDoesNot(): void
    {
        $sale = self::payKickstart();
        self::assertSame(OutcomeKind::RECORDED, $sale->kind, $sale->message);
        self::assertSame(['https://ads.example/pb?s=' . self::CLICK . '&tx=QZ3M-TX-1&p=47.00'], self::queued(),
            'the sale is announced to the ad network that bought the click, once');

        self::assertSame(OutcomeKind::DUPLICATE, self::payKickstart()->kind);
        $refund = self::payKickstart(['event' => 'refund', 'amount' => '']);
        self::assertSame(OutcomeKind::REVERSED, $refund->kind, $refund->message);
        self::assertCount(1, self::queued(), 'a replay and a refund announce nothing more');
    }

    public function testTheLegacyPostbackAsksAndTheLegacyPixelDoesNot(): void
    {
        $pixel = p202RecordLegacyConversion(self::requireScratch(), self::CLICK, 1, MatchMethod::CLICK_ID, [
            'user_id' => self::ACCOUNT, 'transaction_id' => 'QZ3M-PX',
        ]);
        self::assertTrue($pixel['recorded']);
        self::assertSame([], self::queued(), 'px.php, a browser pixel like gpx.php, queues nothing');

        $postback = p202RecordLegacyConversion(self::requireScratch(), self::CLICK, 2, MatchMethod::CLICK_ID, [
            'campaign_id' => self::CAMPAIGN, 'transaction_id' => 'QZ3M-PB', 'notify_traffic_source' => true,
        ]);
        self::assertTrue($postback['recorded']);
        self::assertSame(['https://ads.example/pb?s=' . self::CLICK . '&tx=QZ3M-PB&p=30.00'], self::queued(),
            'pb.php\'s, as gpb.php\'s, with this conversion\'s amount');
    }

    public function testPbPhpAsksForIt(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/tracking202/static/pb.php');
        self::assertStringContainsString("'notify_traffic_source' => true,", $code);
    }
}
