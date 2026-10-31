<?php

declare(strict_types=1);

namespace Tests\Update;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;
use Prosper202\Update\CpcUpdate;

/**
 * Update CPC on a real server, with redirector re-clicks: a re-click gives a
 * click another 202_clicks row at its own visit's time, carrying the first
 * visit's traffic source and cost; the reports read the latest row's copy and
 * the pixels the first row's. A click is chosen by its first visit and every
 * row of it is written. A click with no latest-visit record is counted at
 * each of its rows by every report until the 1.9.79 backfill reaches it, and
 * Update CPC takes each of its rows as a first visit too.
 *
 * The account's clicks, all from traffic source account A at 0.25:
 *  - R: first visited on day 1, re-clicked on day 2 (recorded);
 *  - S: one visit on day 1, off the redirector;
 *  - Q: first visited on day 1, re-clicked on day 2 before 1.9.79 (the
 *    later row carries the link's account L and cost, and no latest-visit
 *    record: every row counts until the backfill reaches it);
 *  - O: arrived on day 0 and re-clicked on day 1 (recorded).
 *
 * @group integration
 */
final class CpcUpdateReClickIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990111;
    private const OTHER = 990112;
    private const R = 99011101;
    private const S = 99011102;
    private const Q = 99011103;
    private const O = 99011104;
    private const THEIRS = 99011105;
    private const CLICKS = [self::R, self::S, self::Q, self::O, self::THEIRS];
    private const CLICK_TABLES = ['202_clicks', '202_clicks_spy', '202_clicks_rotator', '202_click_latest_visits',
        '202_dataengine'];

    private const DAY0 = 1_789_948_800;
    private const DAY1 = self::DAY0 + 86_400;
    private const DAY2 = self::DAY1 + 86_400;

    private static int $accountA = 0;
    private static int $accountL = 0;
    private static int $campaign1 = 0;
    private static int $campaign2 = 0;

    private string $timezone = 'UTC';

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        $u = self::USER;
        $network = self::insert("INSERT INTO 202_ppc_networks SET user_id = $u, ppc_network_name = 'cpc',"
            . ' ppc_network_time = 0');
        foreach (['A', 'L'] as $name) {
            $id = self::insert("INSERT INTO 202_ppc_accounts SET user_id = $u, ppc_network_id = $network,"
                . " ppc_account_name = '$name', ppc_account_time = 0");
            $name === 'A' ? self::$accountA = $id : self::$accountL = $id;
        }
        $aff = self::insert("INSERT INTO 202_aff_networks SET user_id = $u, aff_network_name = 'cpc',"
            . ' aff_network_time = 0');
        foreach ([1, 2] as $n) {
            $id = self::insert("INSERT INTO 202_aff_campaigns SET user_id = $u, aff_network_id = $aff,"
                . " aff_campaign_name = 'c$n', aff_campaign_url = 'https://c$n.example', aff_campaign_payout = 1,"
                . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 0');
            $n === 1 ? self::$campaign1 = $id : self::$campaign2 = $id;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $ids = implode(',', self::CLICKS);
        foreach (self::CLICK_TABLES as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($ids)");
        }
        foreach (['202_dirty_hours', '202_attribution_rollup_dirty'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN (" . self::USER . ',' . self::OTHER . ')');
        }
        [$a, $l, $c1, $c2] = [self::$accountA, self::$accountL, self::$campaign1, self::$campaign2];
        self::visit(self::R, self::DAY1 + 3600, $a, '0.25', $c1, 7);
        self::visit(self::R, self::DAY2 + 3600, $a, '0.25', $c2, 7);
        self::visit(self::S, self::DAY1 + 7200, $a, '0.25', $c1, 0);
        self::visit(self::Q, self::DAY1 + 10800, $a, '0.25', $c1, 7);
        self::visit(self::Q, self::DAY2 + 7200, $l, '0.90', $c2, 7);
        self::visit(self::O, self::DAY0 + 3600, $a, '0.25', $c1, 0);
        self::visit(self::O, self::DAY1 + 14400, $a, '0.25', $c2, 7);
        self::visit(self::THEIRS, self::DAY1 + 3600, $a, '0.25', 0, 0, self::OTHER);
        $conn = new Connection(self::$db);
        foreach ([self::R, self::O] as $click) {
            LatestVisit::refresh($conn, $click);
        }
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    private static function cleanUp(): void
    {
        $ids = implode(',', self::CLICKS);
        foreach (self::CLICK_TABLES as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id IN ($ids)");
        }
        $tables = ['202_dirty_hours', '202_attribution_rollup_dirty', '202_aff_campaigns', '202_aff_networks',
            '202_ppc_accounts', '202_ppc_networks'];
        foreach ($tables as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id IN (" . self::USER . ',' . self::OTHER . ')');
        }
    }

    /** One visit's 202_clicks row and its 202_clicks_spy copy, as the click writer stores them. */
    private static function visit(
        int $click,
        int $time,
        int $account,
        string $cpc,
        int $campaign,
        int $rotator,
        int $user = self::USER
    ): void {
        $columns = "click_id = $click, user_id = $user, aff_campaign_id = $campaign, landing_page_id = 0,"
            . " ppc_account_id = $account, click_cpc = $cpc, click_payout = 1, click_lead = 0, click_time = $time";
        self::q("INSERT INTO 202_clicks SET $columns, rotator_id = $rotator, rule_id = " . ($rotator > 0 ? 3 : 0));
        self::q("INSERT INTO 202_clicks_spy SET $columns");
        if ($rotator > 0) {
            self::q("INSERT INTO 202_clicks_rotator SET click_id = $click, rotator_id = $rotator, rule_id = 3,"
                . ' rule_redirect_id = 3'
                . ' ON DUPLICATE KEY UPDATE rotator_id = VALUES(rotator_id)');
        }
    }

    /** @return array<string, string> the filter for traffic source account A */
    private static function fromAccountA(): array
    {
        return ['ppc_account_id' => (string) self::$accountA];
    }

    /**
     * Update CPC's values for a range of days, as the page and the API read them.
     *
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private static function values(int $firstDay, int $lastDay, string $cpc, array $filters = []): array
    {
        $parsed = CpcUpdate::parse(
            ['from' => date('Y-m-d', $firstDay), 'to' => date('Y-m-d', $lastDay), 'cpc' => $cpc] + $filters
        );
        self::assertSame([], $parsed['errors']);

        return $parsed['values'];
    }

    /**
     * The check and the confirm of what it counted.
     *
     * @param array<string, mixed> $values
     * @return array{matching: int, through_click_id: int, updated: int|null}
     */
    private static function checkAndUpdate(array $values): array
    {
        $conn = new Connection(self::$db);
        $check = CpcUpdate::preview($conn, $values, self::USER);
        $confirmed = ['count' => $check['matching'], 'through' => $check['through_click_id']];
        $updated = CpcUpdate::apply($conn, $values, self::USER, $confirmed);

        return $check + ['updated' => $updated];
    }

    /** @return array<int, list<string>> click => the CPC of each of its rows, oldest first */
    private static function costs(string $table = '202_clicks'): array
    {
        $rows = self::$db->query("SELECT click_id, click_cpc FROM $table"
            . ' WHERE click_id IN (' . implode(',', self::CLICKS) . ')'
            . ' ORDER BY click_id, click_time')->fetch_all(MYSQLI_ASSOC);
        $costs = [];
        foreach ($rows as $row) {
            $costs[(int) $row['click_id']][] = (string) $row['click_cpc'];
        }

        return $costs;
    }

    public function testTheFirstVisitsDayChoosesTheClickAndEveryVisitOfItIsWritten(): void
    {
        $result = self::checkAndUpdate(self::values(self::DAY1, self::DAY1, '1.00', self::fromAccountA()));

        self::assertSame(3, $result['matching'], 'R, S and Q arrived on day 1; O arrived on day 0');
        self::assertSame(self::Q, $result['through_click_id']);
        self::assertSame(3, $result['updated']);
        self::assertSame([
            self::R => ['1.00000', '1.00000'],
            self::S => ['1.00000'],
            self::Q => ['1.00000', '1.00000'],
            self::O => ['0.25000', '0.25000'],
            self::THEIRS => ['0.25000'],
        ], self::costs(), 'every visit of a chosen click, whatever its own day or account;'
            . ' O, re-clicked on day 1, keeps its cost');
        self::assertSame([
            self::R => ['1.00', '1.00'],
            self::S => ['1.00'],
            self::Q => ['1.00', '1.00'],
            self::O => ['0.25', '0.25'],
            self::THEIRS => ['0.25'],
        ], self::costs('202_clicks_spy'), 'and their 202_clicks_spy copies');
    }

    public function testARangeHoldingOnlyARecordedReClickChoosesNothing(): void
    {
        $result = self::checkAndUpdate(self::values(self::DAY2, self::DAY2, '2.00', self::fromAccountA()));

        self::assertSame(0, $result['matching'], 'R was re-clicked on day 2 and arrived on day 1');
        self::assertSame(0, $result['updated']);
        self::assertSame([
            self::R => ['0.25000', '0.25000'],
            self::S => ['0.25000'],
            self::Q => ['0.25000', '0.90000'],
            self::O => ['0.25000', '0.25000'],
            self::THEIRS => ['0.25000'],
        ], self::costs());
    }

    public function testTheCheckCountsTheClicksTheUpdateWrites(): void
    {
        $byFirstCampaign = self::checkAndUpdate(
            self::values(self::DAY0, self::DAY2, '0.30', ['aff_campaign_id' => (string) self::$campaign1])
        );
        self::assertSame(4, $byFirstCampaign['matching'], 'R, S, Q and O arrived on campaign 1');
        self::assertSame(4, $byFirstCampaign['updated']);
        $ours = array_merge(...array_values(array_diff_key(self::costs(), [self::THEIRS => 1])));
        self::assertSame(['0.30000'], array_values(array_unique($ours)));

        $byLaterCampaign = self::checkAndUpdate(
            self::values(self::DAY0, self::DAY2, '0.40', ['aff_campaign_id' => (string) self::$campaign2])
        );
        self::assertSame(1, $byLaterCampaign['matching'], 'campaign 2 is only ever a re-click\'s: of those, Q\'s,'
            . ' which has no record, is a first visit of its own');
        self::assertSame(1, $byLaterCampaign['updated']);
        self::assertSame(['0.30000', '0.40000'], self::costs()[self::Q]);
    }

    public function testARecordedReClickIsChosenByItsFirstVisitAndAnUnrecordedOneAtEachRow(): void
    {
        $reClickDay = self::checkAndUpdate(self::values(self::DAY2, self::DAY2, '2.00'));
        self::assertSame(1, $reClickDay['matching'], 'not R, recorded; Q at its day-2 row, as the reports count it');
        self::assertSame(1, $reClickDay['updated']);
        self::assertSame(['0.25000', '0.25000'], self::costs()[self::R]);
        self::assertSame(['0.25000', '2.00000'], self::costs()[self::Q], 'the row it was chosen at, none before');

        $bothDays = self::checkAndUpdate(self::values(self::DAY1, self::DAY2, '3.00'));
        self::assertSame(3, $bothDays['matching'], 'R, S and Q, each once: Q is chosen at both of its rows');
        self::assertSame(3, $bothDays['updated'], 'and written once, from its first row on');
        self::assertSame(['3.00000', '3.00000'], self::costs()[self::Q]);
        self::assertSame(['3.00000', '3.00000'], self::costs()[self::R]);
        self::assertSame(['0.25000', '0.25000'], self::costs()[self::O], 'O arrived on day 0');
    }

    public function testAClickWhoseEveryVisitAlreadyCostsThisMuchIsNotCountedAsChanged(): void
    {
        $values = self::values(self::DAY1, self::DAY1, '0.25');
        $result = self::checkAndUpdate($values);

        self::assertSame(3, $result['matching']);
        self::assertSame(1, $result['updated'], 'Q: its re-click row carried the link\'s 0.90');
        self::assertSame(['0.25000', '0.25000'], self::costs()[self::Q]);
        self::assertSame(0, self::checkAndUpdate($values)['updated']);
    }

    public function testAConfirmThatNoLongerCountsTheSelectionWritesNothing(): void
    {
        $values = self::values(self::DAY1, self::DAY1, '1.00');
        $conn = new Connection(self::$db);
        $before = self::costs();
        foreach ([2, 4] as $confirmed) {
            $snapshot = ['count' => $confirmed, 'through' => self::Q];
            self::assertNull(CpcUpdate::apply($conn, $values, self::USER, $snapshot), "$confirmed confirmed, 3 match");
            self::assertSame($before, self::costs(), 'rolled back');
            $queued = self::$db->query('SELECT COUNT(*) FROM 202_dirty_hours WHERE user_id = ' . self::USER);
            self::assertSame(0, (int) $queued->fetch_row()[0]);
        }
    }

    public function testTheSpyCopyTakesWhatItsColumnHolds(): void
    {
        $result = self::checkAndUpdate(self::values(self::DAY1, self::DAY1, '99.99999', self::fromAccountA()));

        self::assertSame(3, $result['updated']);
        self::assertSame(['99.99999', '99.99999'], self::costs()[self::R]);
        self::assertSame(
            ['99.99', '99.99'],
            self::costs('202_clicks_spy')[self::R],
            'DECIMAL(4,2): the most it holds, not a refusal'
        );
    }

    /** The Spy view keeps a day of visits: the copies it still holds are written, from its oldest on. */
    public function testTheSpyViewsCopiesFromItsOldestOnAreWritten(): void
    {
        self::q('DELETE FROM 202_clicks_spy WHERE click_time < ' . (self::DAY1 + 7200) . ' AND click_id IN ('
            . implode(',', self::CLICKS) . ')');

        self::checkAndUpdate(self::values(self::DAY1, self::DAY1, '0.50', self::fromAccountA()));

        self::assertSame([
            self::R => ['0.50'],
            self::S => ['0.50'],
            self::Q => ['0.50', '0.50'],
            self::O => ['0.25'],
        ], self::costs('202_clicks_spy'), 'S\'s copy is the oldest the view holds');
    }

    public function testTheReportsAreRebuiltWhereEachVisitIsCounted(): void
    {
        $conn = new Connection(self::$db);
        foreach ([self::R, self::S, self::Q, self::O] as $click) {
            ClickRollupSql::rollUpClick($conn, $click);
        }
        self::checkAndUpdate(self::values(self::DAY1, self::DAY1, '1.00', self::fromAccountA()));

        $queued = self::$db->query('SELECT ppc_account_id, aff_campaign_id, click_time_from, click_time_to'
            . ' FROM 202_dirty_hours'
            . ' WHERE user_id = ' . self::USER . ' ORDER BY click_time_from')->fetch_all(MYSQLI_NUM);
        self::assertSame([
            [(string) self::$accountA, '0', (string) self::DAY1, (string) (self::DAY1 + 86_399)],
            [(string) self::$accountA, '0', (string) (self::DAY2 + 3600), (string) (self::DAY2 + 7199)],
            [(string) self::$accountL, '0', (string) (self::DAY2 + 7200), (string) (self::DAY2 + 10_799)],
        ], $queued, 'the range for the account asked for, and the hour of each later visit for its own account');
        $marked = self::$db->query('SELECT hour_from, hour_to FROM 202_attribution_rollup_dirty'
            . ' WHERE user_id = ' . self::USER
            . ' ORDER BY hour_from')->fetch_all(MYSQLI_NUM);
        self::assertSame([
            [(string) intdiv(self::DAY1, 3600), (string) intdiv(self::DAY1 + 86_399, 3600)],
            [(string) intdiv(self::DAY2 + 3600, 3600), (string) intdiv(self::DAY2 + 7200, 3600)],
        ], $marked, 'and the attribution rollup\'s hours, the later visits\' with them');

        [$out, $err] = self::runDirtyHours();
        self::assertSame('RETURNED true', $out, $err);
        $report = self::$db->query('SELECT click_id, click_time, cost FROM 202_dataengine WHERE click_id IN ('
            . implode(',', self::CLICKS) . ') ORDER BY click_id, click_time')->fetch_all(MYSQLI_NUM);
        self::assertSame([
            [(string) self::R, (string) (self::DAY2 + 3600), '1.00000'],
            [(string) self::S, (string) (self::DAY1 + 7200), '1.00000'],
            [(string) self::Q, (string) (self::DAY1 + 10800), '1.00000'],
            [(string) self::Q, (string) (self::DAY2 + 7200), '1.00000'],
            [(string) self::O, (string) (self::DAY1 + 14400), '0.25000'],
        ], $report, 'R under its latest visit, Q (no record yet) under each, O untouched');
    }

    /** @return array{string, string} what the data engine's runner printed, and what it logged */
    private static function runDirtyHours(): array
    {
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=',
                dirname(__DIR__) . '/DataEngine/fixtures/rollup-runner.php', 'full', 'dirty-hours',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv()
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");

        return [trim($out), $err];
    }
}
