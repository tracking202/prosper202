<?php

declare(strict_types=1);

namespace Tests\Update;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Update\SubidBatch;

/**
 * A reset of a category's or a campaign's sub-IDs counts clicks, not rows.
 *
 * A click visited again (a rotator re-click, rtr.php ?lpr=) has a second
 * 202_clicks row under the same click_id, and resetClicks() read the rows:
 * the preview (POST /update/subids/reset?dry_run=1) said 2 for one sale, and
 * reset() cleared the click twice, the second time finding it already
 * cleared, and answered 2 cleared. The second clear wrote nothing new, so
 * the count was the only thing wrong — and it is the number the page and the
 * API report.
 *
 * @group integration
 */
final class SubidResetIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990081;
    private const CLICK = 99008101;
    private const OTHER_CLICK = 99008102;

    private static int $network = 0;
    private static int $campaign = 0;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {} public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
        }
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        $u = self::USER;
        self::user($u);
        self::$network = self::insert("INSERT INTO 202_aff_networks SET user_id = $u, aff_network_name = 'reset', aff_network_time = 0");
        self::$campaign = self::insert("INSERT INTO 202_aff_campaigns SET user_id = $u, aff_network_id = " . self::$network
            . ", aff_campaign_name = 'reset', aff_campaign_url = 'https://reset.example', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0");
        // The click, then its re-click a minute later: one click_id, two rows.
        $now = time();
        foreach ([[self::CLICK, $now - 7200], [self::CLICK, $now - 7140], [self::OTHER_CLICK, $now - 3600]] as [$click, $time]) {
            self::q("INSERT INTO 202_clicks SET click_id = $click, user_id = $u, aff_campaign_id = " . self::$campaign
                . ", ppc_account_id = 0, landing_page_id = 0, click_cpc = 0, click_payout = 1, click_lead = 0, click_time = $time");
        }
        foreach ([self::CLICK, self::OTHER_CLICK] as $click) {
            $recorded = (new MysqlConversionRepository(new Connection(self::$db)))->record($u, [
                'click_id' => $click, 'transaction_id' => 'reset-' . $click, 'source' => 'api', 'payout' => '1.00',
            ]);
            self::assertTrue($recorded['clickFound']);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    private static function cleanUp(): void
    {
        $clicks = self::CLICK . ',' . self::OTHER_CLICK;
        foreach (['202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_record', '202_dataengine'] as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        self::$db->query("DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($clicks))");
        foreach (['202_conversion_logs', '202_revenue_events', '202_dirty_hours', '202_aff_campaigns', '202_aff_networks', '202_users'] as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id = " . self::USER);
        }
    }

    public function testARevisitedClickIsCountedAndClearedOnce(): void
    {
        self::requireScratch();
        self::assertSame(3, (int) self::$db->query('SELECT COUNT(*) FROM 202_clicks WHERE click_id IN (' . self::CLICK . ',' . self::OTHER_CLICK . ')')->fetch_row()[0], 'the re-click is a second row');

        $batch = new SubidBatch(new Connection(self::$db));
        $rows = $batch->resetClicks(self::USER, self::$network, self::$campaign);
        self::assertSame([self::CLICK, self::OTHER_CLICK], array_column($rows, 'click_id'), 'each click once, oldest first');
        $first = (int) self::$db->query('SELECT MIN(click_time) FROM 202_clicks WHERE click_id = ' . self::CLICK)->fetch_row()[0];
        self::assertSame($first, $rows[0]['click_time'], 'at the time of its first visit, where the report hours to rebuild begin');

        self::assertSame(2, $batch->reset(self::USER, self::$network, self::$campaign), 'two clicks cleared');
        self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM 202_conversion_logs WHERE deleted = 0 AND user_id = ' . self::USER)->fetch_row()[0], 'and their sales are gone');
    }
}
