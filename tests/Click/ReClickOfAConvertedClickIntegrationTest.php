<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\RotatorRoute;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;

/**
 * A redirector re-click of a click that converted, onto another campaign, on
 * a real server through the real conversion writer (the legacy pixel's,
 * p202RecordLegacyConversion()). The re-click's row carries the click's lead
 * and value as the ledger's recompute left every row of it
 * (MysqlClickRepository::recordReClick()), so the click, rolled up once
 * under its latest visit, still counts its lead and income; and the
 * once-per-click rule an id-less conversion is held to, which reads the
 * latest visit's lead, still refuses a second one. A re-click's row written
 * as no lead gave the click no lead in the reports and let one more
 * conversion through.
 *
 * @group integration
 */
final class ReClickOfAConvertedClickIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990306;
    private const FIRST_CLICK = 99030601;
    /** The campaign the click converted on, at 3.00, and the one it is re-clicked onto, at 7.00. */
    private const CONVERTED = 990361;
    private const RE_CLICKED = 990362;

    private const CLICK_TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_rotator',
        '202_clicks_record', '202_clicks_site', '202_attribution_rollup_dirty_clicks', '202_dataengine',
    ];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
        self::cleanUp();
        foreach ([self::CONVERTED => '3.00', self::RE_CLICKED => '7.00'] as $campaign => $payout) {
            self::q("INSERT INTO 202_aff_campaigns SET aff_campaign_id = $campaign, user_id = " . self::USER
                . ", aff_network_id = 1, aff_campaign_name = 'c$campaign', aff_campaign_url = 'http://x',"
                . " aff_campaign_payout = $payout, aff_campaign_time = 1, aff_campaign_foreign_payout = $payout,"
                . " payout_mode = 'replace'");
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

    private static function cleanUp(): void
    {
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 9);
        self::q("DELETE FROM 202_attribution_pending WHERE conv_id IN"
            . " (SELECT conv_id FROM 202_conversion_logs WHERE click_id $range)");
        foreach ([...self::CLICK_TABLES, '202_conversion_logs'] as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
        self::q('DELETE FROM 202_aff_campaigns WHERE aff_campaign_id IN (' . self::CONVERTED . ', '
            . self::RE_CLICKED . ')');
    }

    private static function repo(): MysqlClickRepository
    {
        return new MysqlClickRepository(new Connection(self::requireScratch()));
    }

    /** A redirector click on the converted campaign, ten minutes ago, converted by an id-less pixel. */
    private static function convertedClick(int $clickId): void
    {
        self::repo()->recordClick(
            new ClickRecord(
                clickId: $clickId,
                userId: self::USER,
                clickTime: time() - 600,
                affCampaignId: self::CONVERTED,
                ppcAccountId: 3,
                clickCpc: '0.25',
                clickPayout: '3.00',
                recordsCampaignRows: false,
                rotator: new RotatorRoute(7, 8, 9),
            ),
            static fn (): int => 51
        );
        $converted = p202RecordLegacyConversion(self::requireScratch(), $clickId, 1, ['user_id' => self::USER]);
        self::assertTrue($converted['recorded'], (string) json_encode($converted));
    }

    /** A re-click through a redirector rule sending the visitor to the other campaign: its time. */
    private static function reClickOntoTheOtherCampaign(int $clickId): int
    {
        $at = time() + 60;
        self::repo()->recordReClick(
            new ClickRecord(
                clickId: $clickId,
                userId: self::USER,
                clickTime: $at,
                affCampaignId: self::RE_CLICKED,
                ppcAccountId: 13,
                clickCpc: '0.75',
                clickPayout: '7.00',
                recordsCampaignRows: false,
                clickOut: 1,
                rotator: new RotatorRoute(17, 18, 19),
            ),
            static fn (): int => 52
        );

        return $at;
    }

    /** @return list<array<string, string>> */
    private static function rows(string $sql): array
    {
        $result = self::requireScratch()->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error . ' in ' . $sql);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function testAConvertedClickReClickedOntoAnotherCampaignCountsItsLeadOnceUnderItsLatestVisit(): void
    {
        $clickId = self::FIRST_CLICK;
        self::convertedClick($clickId);

        $at = self::reClickOntoTheOtherCampaign($clickId);
        ClickRollupSql::rollUpClick(new Connection(self::requireScratch()), $clickId);

        self::assertSame(
            [['aff_campaign_id' => (string) self::RE_CLICKED, 'click_lead' => '1', 'click_payout' => '3.00000']],
            self::rows("SELECT aff_campaign_id, click_lead, click_payout FROM 202_clicks WHERE click_id = $clickId"
                . " AND click_time = $at"),
            'the re-click\'s row: the latest visit\'s campaign, the click\'s lead and value'
        );
        self::assertSame(
            [[
                'click_time' => (string) $at, 'aff_campaign_id' => (string) self::RE_CLICKED, 'clicks' => '1',
                'leads' => '1', 'payout' => '3.00', 'income' => '3.00000',
            ]],
            self::rows("SELECT click_time, aff_campaign_id, clicks, leads, payout, income FROM 202_dataengine"
                . " WHERE click_id = $clickId"),
            'one report row, at the latest visit, with the lead and its income'
        );
    }

    public function testASecondIdLessConversionIsRefusedAfterTheReClickAsBefore(): void
    {
        $clickId = self::FIRST_CLICK + 1;
        self::convertedClick($clickId);
        $again = p202RecordLegacyConversion(self::requireScratch(), $clickId, 1, ['user_id' => self::USER]);
        self::assertSame('already_lead', $again['reason'], 'before the re-click');

        self::reClickOntoTheOtherCampaign($clickId);
        $again = p202RecordLegacyConversion(self::requireScratch(), $clickId, 1, ['user_id' => self::USER]);

        self::assertSame('already_lead', $again['reason'], 'after it: ' . json_encode($again));
        self::assertSame(
            [['n' => '1']],
            self::rows("SELECT COUNT(*) AS n FROM 202_conversion_logs WHERE click_id = $clickId AND deleted = 0")
        );
    }
}
