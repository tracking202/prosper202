<?php

declare(strict_types=1);

namespace Tests\Update;

use Api\V3\Controllers\UpdateController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Update\SubidBatch;
use Prosper202\Update\SubidLinesRefused;

/**
 * Update Subids records a line's payout, transaction id and time through the
 * ledger, the page's default payout where a line has none, and refuses a
 * list with an unreadable line before writing any of it. POST
 * /conversions/subids runs the same code.
 *
 * @group integration
 */
final class SubidLinesIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990261;
    private const CLICKS = [99026101, 99026102, 99026103, 99026104, 99026105];

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
        self::user($u, 'America/New_York');
        $network = self::insert("INSERT INTO 202_aff_networks SET user_id = $u, aff_network_name = 'lines', aff_network_time = 0");
        self::$campaign = self::insert("INSERT INTO 202_aff_campaigns SET user_id = $u, aff_network_id = $network"
            . ", aff_campaign_name = 'lines', aff_campaign_url = 'https://lines.example', aff_campaign_payout = 3, aff_campaign_foreign_payout = 0, aff_campaign_time = 0");
        $time = time() - 86400 * 3;
        foreach (self::CLICKS as $click) {
            self::q("INSERT INTO 202_clicks SET click_id = $click, user_id = $u, aff_campaign_id = " . self::$campaign
                . ", ppc_account_id = 0, landing_page_id = 0, click_cpc = 0, click_payout = 3, click_lead = 0, click_time = $time");
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
        $clicks = implode(',', self::CLICKS);
        foreach (['202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_record', '202_dataengine', '202_click_latest_visits'] as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        self::$db->query("DELETE FROM 202_attribution_pending WHERE conv_id IN (SELECT conv_id FROM 202_conversion_logs WHERE click_id IN ($clicks))");
        foreach (['202_conversion_logs', '202_revenue_events', '202_dirty_hours', '202_attribution_rollup_dirty_clicks', '202_aff_campaigns', '202_aff_networks', '202_users'] as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id = " . self::USER);
        }
    }

    /** @return list<array{payout: string, tx: ?string, time: int}> */
    private static function rows(int $click): array
    {
        $out = [];
        $r = self::$db->query("SELECT click_payout, transaction_id, conv_time FROM 202_conversion_logs WHERE click_id = $click AND deleted = 0 ORDER BY conv_id");
        foreach ($r->fetch_all(MYSQLI_ASSOC) as $row) {
            $out[] = ['payout' => (string) (float) $row['click_payout'], 'tx' => $row['transaction_id'], 'time' => (int) $row['conv_time']];
        }

        return $out;
    }

    public function testEachFieldIsRecordedAndARepeatChangesNothing(): void
    {
        self::requireScratch();
        [$a, $b, $c] = self::CLICKS;
        $zone = new \DateTimeZone('America/New_York');
        $when = (new \DateTimeImmutable('-2 days', $zone))->setTime(14, 30);
        $lines = [
            "$a, 12.50, order-1, " . $when->format('m/d/Y g:i:s A'),
            "$a, 7, order-2",
            "$b",
            "$c,,,"  . $when->format('Y-m-d H:i:s'),
        ];

        $batch = new SubidBatch(new Connection(self::$db));
        $preview = $batch->markPreview(self::USER, $lines, '4.25', $zone);
        self::assertSame(4, $preview['would_mark']);

        $result = $batch->mark(self::USER, $lines, '4.25', $zone);
        self::assertSame(4, $result['marked']);
        $rowsA = self::rows($a);
        self::assertSame([['12.5', 'order-1'], ['7', 'order-2']], array_map(static fn (array $r): array => [$r['payout'], $r['tx']], $rowsA), 'two sales on one click, each at its own payout');
        self::assertSame($when->getTimestamp(), $rowsA[0]['time'], 'at the line\'s time');
        self::assertGreaterThanOrEqual(time() - 60, $rowsA[1]['time'], 'a line without a time is recorded now');
        self::assertSame('4.25', self::rows($b)[0]['payout'], 'a line without a payout takes the default');
        self::assertNull(self::rows($b)[0]['tx']);
        self::assertSame($when->getTimestamp(), self::rows($c)[0]['time'], 'the time is read in the zone given');

        $again = $batch->mark(self::USER, $lines, '4.25', $zone);
        self::assertSame(0, $again['marked']);
        self::assertSame(array_fill(0, 4, SubidBatch::ALREADY_CONVERTED), array_column($again['lines'], 'status'));
        self::assertCount(2, self::rows($a), 'the same transaction ids are not recorded again');

        $preview = $batch->markPreview(self::USER, ["$a, 1, order-1", "$a, 1, order-3"], null, $zone);
        self::assertSame([SubidBatch::ALREADY_CONVERTED, SubidBatch::WOULD_MARK], array_column($preview['lines'], 'status'));
    }

    public function testALineThatCannotBeReadStopsTheWholeList(): void
    {
        self::requireScratch();
        $d = self::CLICKS[3];
        try {
            (new SubidBatch(new Connection(self::$db)))->mark(self::USER, ["$d, 5", "$d, five"], null, new \DateTimeZone('UTC'));
            self::fail('the list was recorded');
        } catch (SubidLinesRefused $e) {
            self::assertSame([2], array_column($e->lines, 'line'));
        }
        self::assertSame([], self::rows($d), 'nothing was written, not even the readable line');

        try {
            (new SubidBatch(new Connection(self::$db)))->mark(self::USER, ["$d,,,100000000"], null, new \DateTimeZone('UTC'));
            self::fail('a time before the click was recorded');
        } catch (SubidLinesRefused $e) {
            self::assertStringContainsString('before the click', $e->lines[0]['error']);
        }
        self::assertSame([], self::rows($d));
    }

    public function testTheApiReadsTheSameLinesInTheAccountsZone(): void
    {
        self::requireScratch();
        $e = self::CLICKS[4];
        $controller = new UpdateController(self::$db, self::USER);

        $refused = [
            [['subids' => ["$e"], 'payout' => '$5'], 'payout'],
            [['subids' => ["$e"], 'timezone' => 'Mars/Olympus'], 'timezone'],
            [['subids' => ["$e", "$e, 1, tx, someday"]], 'subids.1'],
        ];
        foreach ($refused as [$body, $field]) {
            try {
                $controller->markSubids($body, false);
                self::fail('accepted ' . json_encode($body));
            } catch (ValidationException $ex) {
                self::assertArrayHasKey($field, $ex->getFieldErrors(), json_encode($body));
            }
        }
        try {
            $controller->markSubids(['subids' => ["$e, five"]], true);
            self::fail('the dry run accepted an unreadable line');
        } catch (ValidationException $ex) {
            self::assertArrayHasKey('subids.0', $ex->getFieldErrors(), 'a dry run refuses it as the write does');
        }
        self::assertSame([], self::rows($e), 'a refused request writes nothing');

        $when = (new \DateTimeImmutable('-1 day', new \DateTimeZone('America/New_York')))->setTime(9, 0);
        $answer = $controller->markSubids(['subids' => ["$e,,api-1," . $when->format('Y-m-d H:i')], 'payout' => 2.5], false);
        self::assertSame(1, $answer['data']['marked']);
        self::assertSame([['payout' => '2.5', 'tx' => 'api-1', 'time' => $when->getTimestamp()]], self::rows($e), 'read in the account\'s zone');
    }
}
