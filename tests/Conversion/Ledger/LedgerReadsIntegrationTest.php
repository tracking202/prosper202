<?php

declare(strict_types=1);

namespace Tests\Conversion\Ledger;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\ClickBreakdown;
use Prosper202\Conversion\Ledger\ClickValueCalculator;
use Prosper202\Conversion\Ledger\LedgerReportSql;
use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Conversion\Ledger\SourceRef;
use Prosper202\Conversion\Ledger\SupersededReason;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Conversion\RevenueUploadImporter;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;

/**
 * The breakdown reads against a real MySQL/MariaDB, with every row written
 * through the real repository: the per-click breakdown (ClickBreakdown) and
 * the reports' SQL view of "which rows count" (LedgerReportSql).
 *
 * The SQL has to agree with ClickValueCalculator, the one definition, on
 * every click — so one fixture builds a click in each shape the rules name
 * (replace, accumulate, an upload batch replacing a postback and an older
 * batch, a reversal of a counted sale, of a superseded sale and of a
 * pre-ledger sale, a soft-deleted row, an unpaid goal outcome, a goal row
 * superseded by a replay) and the test compares the two sets click by
 * click, plus the part that carries each click's clicks and leads.
 *
 * Skips unless P202_TEST_DB_HOST (and friends) name a scratch database.
 *
 * @group integration
 */
final class LedgerReadsIntegrationTest extends TestCase
{
    private static ?\mysqli $db = null;
    private MysqlConversionRepository $repo;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {} public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        foreach (['202_conversion_logs', '202_clicks', '202_clicks_spy', '202_aff_campaigns', '202_attribution_pending',
            '202_conversion_uploads', '202_dataengine', '202_goals', '202_api_keys'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        $this->repo = new MysqlConversionRepository(new Connection(self::$db));
    }

    private static function fixture(string $sql): void
    {
        self::$db->query("SET SESSION sql_mode=''");
        try {
            if (self::$db->query($sql) !== true) {
                throw new \RuntimeException('fixture failed: ' . self::$db->error);
            }
        } finally {
            self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        }
    }

    private function campaign(int $id, string $mode): void
    {
        self::fixture("INSERT INTO 202_aff_campaigns SET aff_campaign_id=$id, user_id=1, aff_network_id=1, aff_campaign_name='Camp $id',
            aff_campaign_url='http://x', aff_campaign_payout=10, aff_campaign_time=1, aff_campaign_foreign_payout=10, payout_mode='$mode'");
    }

    private function click(int $id, int $campaign, string $payout = '0.00', int $lead = 0, int $user = 1): void
    {
        foreach (['202_clicks', '202_clicks_spy'] as $t) {
            self::fixture("INSERT INTO $t SET click_id=$id, user_id=$user, aff_campaign_id=$campaign, click_payout=$payout, click_cpc=0, click_lead=$lead, click_time=1700000000");
        }
        self::fixture("INSERT INTO 202_dataengine SET click_id=$id, user_id=$user, click_time=1700000000, aff_campaign_id=$campaign,
            ppc_account_id=0, landing_page_id=0, clicks=1, leads=$lead, payout=0, income=$payout, cost=0");
    }

    private function record(int $clickId, array $data): int
    {
        $r = $this->repo->record(1, ['click_id' => $clickId] + $data + ['source' => 'postback']);
        self::assertFalse($r['duplicate'], 'fixture rows are new');

        return (int) $r['convId'];
    }

    /** @return array<string, int> named conv ids */
    private function buildEveryShape(): array
    {
        $ids = [];
        $this->campaign(1, 'replace');
        $this->campaign(2, 'accumulate');
        self::fixture("INSERT INTO 202_goals SET goal_id=40, user_id=1, scope='campaign', scope_id=2, name='Reached level 3', current_version=2, created_at=1, updated_at=1");

        // 100: replace — A then B; A superseded, B counts.
        $this->click(100, 1);
        $ids['100A'] = $this->record(100, ['payout' => '5', 'transaction_id' => 'A-1']);
        $ids['100B'] = $this->record(100, ['payout' => '10', 'transaction_id' => 'B-1']);

        // 101: accumulate — two sales, a goal outcome paid, one unpaid, the
        // first sale reversed, the second deleted.
        $this->click(101, 2);
        $ids['101S1'] = $this->record(101, ['payout' => '5', 'transaction_id' => 'S-1']);
        $ids['101S2'] = $this->record(101, ['payout' => '3', 'transaction_id' => 'S-2']);
        $ids['101G'] = $this->record(101, ['payout' => '2', 'source' => 'goal', 'source_ref' => SourceRef::goal(40, 2),
            'event_name' => 'level_reached', 'dedupe_key' => 'goal:40:2:1:e1']);
        $ids['101U'] = $this->record(101, ['payout' => '0', 'payable' => false, 'source' => 'goal', 'source_ref' => SourceRef::goal(40, 1),
            'dedupe_key' => 'goal:40:1:1:e0']);
        $ids['101R'] = $this->record(101, ['transaction_id' => 'S-1', 'reversal' => true]);
        $this->repo->softDelete($ids['101S2'], 1);

        // 102: an upload replaces a postback; a second batch replaces the first.
        $this->click(102, 1);
        $ids['102P'] = $this->record(102, ['payout' => '9', 'transaction_id' => 'P-1']);
        $importer = new RevenueUploadImporter(new Connection(self::$db), $this->repo);
        $csv = static function (string $text) {
            $h = fopen('php://memory', 'r+');
            fwrite($h, $text);
            rewind($h);
            return $h;
        };
        $importer->import(1, 'first.csv', $csv("subid,amount\n102,1\n102,2\n"), 0, 1);
        $importer->import(1, 'second.csv', $csv("subid,amount\n102,4\n102,0.5\n"), 0, 1);

        // 103: replace — a reversal of a superseded sale nets nothing.
        $this->click(103, 1);
        $ids['103A'] = $this->record(103, ['payout' => '5', 'transaction_id' => 'X-1']);
        $ids['103B'] = $this->record(103, ['payout' => '8', 'transaction_id' => 'X-2']);
        $ids['103R'] = $this->record(103, ['transaction_id' => 'X-1', 'reversal' => true]);

        // 104: a pre-ledger sale, then its reversal: the baseline carries the
        // value and the reversal nets against it.
        $this->click(104, 1, '12.00', 1);
        self::fixture("INSERT INTO 202_conversion_logs SET click_id=104, transaction_id='OLD-1', campaign_id=1, click_payout=12, user_id=1,
            click_time=1, conv_time=1, time_difference='', ip='', pixel_type=2, user_agent='', deleted=0,
            source='postback', dedupe_key='tx:OLD-1', superseded_reason='pre_ledger'");
        $ids['104OLD'] = (int) self::$db->query("SELECT conv_id FROM 202_conversion_logs WHERE click_id=104 AND dedupe_key='tx:OLD-1'")->fetch_row()[0];
        $ids['104R'] = $this->record(104, ['transaction_id' => 'OLD-1', 'reversal' => true, 'payout' => '-4']);

        // 105: replace — the latest deleted, so the earlier one counts again.
        $this->click(105, 1);
        $ids['105A'] = $this->record(105, ['payout' => '6', 'transaction_id' => 'D-1']);
        $ids['105B'] = $this->record(105, ['payout' => '7', 'transaction_id' => 'D-2']);
        $this->repo->softDelete($ids['105B'], 1);

        // 106: accumulate — a goal row the engine superseded by a replay.
        $this->click(106, 2);
        $ids['106G1'] = $this->record(106, ['payout' => '2', 'source' => 'goal', 'source_ref' => SourceRef::goal(40, 2), 'dedupe_key' => 'goal:40:2:1:e9']);
        $ids['106G2'] = $this->record(106, ['payout' => '2', 'source' => 'goal', 'source_ref' => SourceRef::goal(40, 2), 'dedupe_key' => 'goal:40:2:1:e3']);
        $conn = new Connection(self::$db);
        $conn->transaction(static function () use ($conn, $ids): void {
            (new MysqlConversionLedger($conn))->supersedeGoalRow($ids['106G1'], $ids['106G2'], SupersededReason::REPLAY);
        });

        // 107: a pre-ledger lead no write has touched: its value is its cache.
        $this->click(107, 1, '20.00', 1);

        // 108: never converted.
        $this->click(108, 1);

        return $ids;
    }

    /** @return array<int, array{counted: list<int>, primary: ?int}> keyed by click id */
    private function sqlParts(): array
    {
        $sql = 'SELECT p.click_id, p.conv_id, p.primary_part FROM ' . LedgerReportSql::partsTable('s.user_id = 1') . ' p ORDER BY p.click_id, p.conv_id';
        $out = [];
        foreach (self::$db->query($sql)->fetch_all(MYSQLI_ASSOC) as $r) {
            $click = (int) $r['click_id'];
            $out[$click] ??= ['counted' => [], 'primary' => null];
            $out[$click]['counted'][] = (int) $r['conv_id'];
            if ((int) $r['primary_part'] === 1) {
                self::assertNull($out[$click]['primary'], "click $click has one primary part");
                $out[$click]['primary'] = (int) $r['conv_id'];
            }
        }

        return $out;
    }

    public function testTheReportSqlCountsExactlyTheRowsTheCalculatorCounts(): void
    {
        $this->buildEveryShape();
        $conn = new Connection(self::$db);
        $ledger = new MysqlConversionLedger($conn);
        $sql = $this->sqlParts();

        $checked = 0;
        foreach ([100, 101, 102, 103, 104, 105, 106, 107, 108] as $clickId) {
            $campaign = (int) self::$db->query("SELECT aff_campaign_id FROM 202_clicks WHERE click_id=$clickId")->fetch_row()[0];
            $rows = $ledger->loadRows($clickId);
            $value = ClickValueCalculator::calculate($rows, $ledger->campaignTerms($campaign)['mode']);
            $want = array_keys($value->counted);
            sort($want);
            $got = $sql[$clickId]['counted'] ?? [];
            self::assertSame($want, $got, "click $clickId: the report SQL counts the rows the calculator counts");

            $latest = null;
            foreach ($rows as $row) {
                if (isset($value->counted[$row->convId]) && !$row->isReversal()) {
                    $latest = max($latest ?? 0, $row->convId);
                }
            }
            self::assertSame($latest, $sql[$clickId]['primary'] ?? null, "click $clickId: its latest counted sale carries its click and lead");
            $checked++;
        }
        self::assertSame(9, $checked);

        // Every click's counted parts add up to its cached value.
        $sum = self::$db->query('SELECT p.click_id, SUM(p.amount) AS v FROM ' . LedgerReportSql::partsTable('s.user_id = 1') . ' p GROUP BY p.click_id')->fetch_all(MYSQLI_ASSOC);
        foreach ($sum as $r) {
            $cached = self::$db->query('SELECT click_payout FROM 202_clicks WHERE click_id=' . (int) $r['click_id'])->fetch_row()[0];
            self::assertSame(number_format((float) $cached, 2), number_format((float) $r['v'], 2), 'click ' . $r['click_id'] . ': parts sum to the click');
        }
    }

    /**
     * Group Overview grouped by campaign, then by a ledger level: the query
     * ReportSummaryForm builds, run against the fixture, drawn into its
     * tree the way the page and the download draw it.
     *
     * @return array{tree: object, rows: list<array<string, mixed>>, form: \ReportSummaryForm}
     */
    private function groupOverview(array $details): array
    {
        require_once dirname(__DIR__, 3) . '/202-config/ReportSummaryForm.class.php';
        if (!class_exists('DB', false)) {
            eval('class DB { public static $conn; public static function getInstance() { return new self(); } public function getConnection() { return self::$conn; } }');
        }
        \DB::$conn = self::$db;
        $_SESSION['publisher'] = true;
        $_SESSION['user_own_id'] = 1;

        $prefs = [];
        foreach (['aff_campaign_id', 'aff_network_id', 'browser_id', 'country_id', 'device_id', 'ip', 'isp_id', 'keyword', 'landing_page_id',
            'platform_id', 'ppc_account_id', 'ppc_network_id', 'referer', 'region_id', 'show', 'subid', 'text_ad_id'] as $k) {
            $prefs['user_pref_' . $k] = '';
        }
        $form = new \ReportSummaryForm();
        $form->setDetails($details);
        $form->setDetailsSort([\ReportBasicForm::SORT_NAME]);
        $form->setDisplayType([\ReportBasicForm::DISPLAY_TYPE_TABLE]);
        $form->setStartTime(1600000000);
        $form->setEndTime(1800000000);
        $result = self::$db->query($form->getQuery('1', $prefs));
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $form->addReportData($row);
        }

        return ['tree' => $form->getReportData(), 'rows' => $rows, 'form' => $form];
    }

    /** @return array<string, array{clicks: int, leads: int, income: string, cost: string}> */
    private static function children(object $group): array
    {
        $out = [];
        foreach ($group->getChildArrayBySort() as $child) {
            $out[(string) $child->getTitle()] = [
                'clicks' => (int) $child->getClicks(),
                'leads' => (int) $child->getLeads(),
                'income' => number_format((float) $child->getIncome(), 2, '.', ''),
                'cost' => number_format((float) $child->getCost(), 2, '.', ''),
            ];
        }

        return $out;
    }

    private function syncReportRows(): void
    {
        self::$db->query('UPDATE 202_dataengine d JOIN 202_clicks c ON c.click_id = d.click_id
            SET d.leads = c.click_lead, d.income = IF(c.click_lead = 1, c.click_payout, 0), d.cost = 0.25');
    }

    public function testGroupOverviewsLedgerLevelsSumTheRowsAndAddUpToTheReport(): void
    {
        require_once dirname(__DIR__, 3) . '/202-config/ReportSummaryForm.class.php';
        $this->buildEveryShape();
        // Two more clicks with transaction ids the column's collation
        // (utf8mb4_general_ci) calls equal to 100's "A-1": one differs only
        // in case, one only by an accent. Three ids, three groups.
        $this->click(110, 1);
        $this->record(110, ['payout' => '1', 'transaction_id' => 'a-1']);
        $this->click(111, 1);
        $this->record(111, ['payout' => '2', 'transaction_id' => 'Ä-1']);
        $this->syncReportRows();

        $plain = $this->groupOverview([\ReportBasicForm::DETAIL_LEVEL_CAMPAIGN]);
        $byTx = $this->groupOverview([\ReportBasicForm::DETAIL_LEVEL_CAMPAIGN, \ReportBasicForm::DETAIL_LEVEL_TRANSACTIONS]);
        $bySource = $this->groupOverview([\ReportBasicForm::DETAIL_LEVEL_CAMPAIGN, \ReportBasicForm::DETAIL_LEVEL_GOAL_SOURCE]);

        // The ledger levels change how a campaign is split, never its totals.
        $totals = self::children($plain['tree']);
        self::assertSame($totals, self::children($byTx['tree']), 'grouping by transaction leaves every campaign\'s figures as they were');
        self::assertSame($totals, self::children($bySource['tree']), 'grouping by goal / source leaves every campaign\'s figures as they were');
        self::assertSame('63.50', number_format((float) $plain['tree']->getIncome(), 2, '.', ''), 'the fixture\'s clicks are worth 10 + 2 + 4.5 + 8 + 8 + 6 + 2 + 20 + 1 + 2');

        $accumulate = null;
        $replace = null;
        foreach ($byTx['tree']->getChildArrayBySort() as $campaign) {
            if ((int) $campaign->getAffiliateCampaignId() === 2) {
                $accumulate = $campaign;
            } else {
                $replace = $campaign;
            }
        }
        // Campaign 2 (accumulate): click 101 is S-1 5, its reversal -5 (the
        // same transaction id, so the same group), and the paid goal 2
        // with no id; click 106 is the replayed goal's replacement, 2.
        self::assertSame([
            '[No transaction ID]' => ['clicks' => 2, 'leads' => 2, 'income' => '4.00', 'cost' => '0.50'],
            'S-1' => ['clicks' => 0, 'leads' => 0, 'income' => '0.00', 'cost' => '0.00'],
        ], self::children($accumulate), 'a reversal nets its sale inside the sale\'s transaction; each click\'s click and lead sit on its latest counted sale');

        $tx = self::children($replace);
        self::assertSame('10.00', $tx['B-1']['income'], 'a transaction shows its own amount, not the click\'s');
        self::assertArrayNotHasKey('X-1', $tx, 'a superseded sale and its reversal count for nothing, so they make no group');
        self::assertSame(['clicks' => 1, 'leads' => 1, 'income' => '1.00', 'cost' => '0.25'], $tx['a-1'], 'ids that differ only in case are different transactions');
        self::assertSame(['clicks' => 1, 'leads' => 1, 'income' => '2.00', 'cost' => '0.25'], $tx['Ä-1'], 'and so are ids that differ by an accent');
        self::assertArrayNotHasKey('A-1', $tx, '100\'s A-1 was replaced by B-1, so it adds nothing and has no group');
        self::assertSame(['clicks' => 1, 'leads' => 0, 'income' => '0.00', 'cost' => '0.25'], $tx['[Not converted]']);
        self::assertSame(['clicks' => 3, 'leads' => 3, 'income' => '36.50', 'cost' => '0.75'], $tx['[No transaction ID]'],
            'the upload lines 4 + 0.5, the pre-ledger lead\'s cached 20 and 104\'s carried-in 12 have no transaction id');
        self::assertSame(['clicks' => 0, 'leads' => 0, 'income' => '-4.00', 'cost' => '0.00'], $tx['OLD-1'],
            'a reversal of a pre-ledger sale sits under that sale\'s id; its click and lead sit on the baseline it nets against');

        $acc = null;
        foreach ($bySource['tree']->getChildArrayBySort() as $campaign) {
            if ((int) $campaign->getAffiliateCampaignId() === 2) {
                $acc = self::children($campaign);
            }
        }
        self::assertSame([
            'Goal: Reached level 3' => ['clicks' => 2, 'leads' => 2, 'income' => '4.00', 'cost' => '0.50'],
            'Postback' => ['clicks' => 0, 'leads' => 0, 'income' => '0.00', 'cost' => '0.00'],
        ], $acc, 'goal rows group by goal; the sale and its reversal net under their source; both clicks\' latest counted rows are goal rows');
    }

    /**
     * Goal / source as the fourth level, under Transaction ID at the third:
     * the deepest place the report nests it (every group_N offers it,
     * GroupingLevelOffersTest). Every group adds up to its parent at every
     * depth, the campaigns' figures are the ones the plain report shows, and
     * the download labels each leaf row by both ledger levels.
     */
    public function testGoalSourceWorksAsTheFourthLevel(): void
    {
        require_once dirname(__DIR__, 3) . '/202-config/ReportSummaryForm.class.php';
        $this->buildEveryShape();
        $this->syncReportRows();

        $plain = $this->groupOverview([\ReportBasicForm::DETAIL_LEVEL_CAMPAIGN]);
        $four = $this->groupOverview([\ReportBasicForm::DETAIL_LEVEL_CAMPAIGN, \ReportBasicForm::DETAIL_LEVEL_LANDING_PAGE,
            \ReportBasicForm::DETAIL_LEVEL_TRANSACTIONS, \ReportBasicForm::DETAIL_LEVEL_GOAL_SOURCE]);

        self::assertSame(self::children($plain['tree']), self::children($four['tree']), 'four levels leave every campaign\'s figures as they were');
        self::assertSame(self::figures($plain['tree']), self::figures($four['tree']), 'and the report\'s totals');
        $leaves = self::assertAddsUp($four['tree'], 0);
        self::assertSame(count($four['rows']), $leaves, 'every query row is one leaf at the fourth level');

        $byLevel = [];
        foreach ($four['tree']->getChildArrayBySort() as $campaign) {
            foreach ($campaign->getChildArrayBySort() as $lp) {
                foreach ($lp->getChildArrayBySort() as $tx) {
                    foreach (self::children($tx) as $source => $f) {
                        $byLevel[(int) $campaign->getAffiliateCampaignId()][(string) $tx->getTitle()][$source] = $f;
                    }
                }
            }
        }
        self::assertSame([
            '[No transaction ID]' => ['Goal: Reached level 3' => ['clicks' => 2, 'leads' => 2, 'income' => '4.00', 'cost' => '0.50']],
            'S-1' => ['Postback' => ['clicks' => 0, 'leads' => 0, 'income' => '0.00', 'cost' => '0.00']],
        ], $byLevel[2], 'campaign 2 splits by transaction, then each transaction by what produced it');
        self::assertSame(['clicks' => 1, 'leads' => 1, 'income' => '10.00', 'cost' => '0.25'], $byLevel[1]['B-1']['Postback'] ?? null,
            'a postback sale is its own transaction and its own source');

        // The download (group_overview_download.php) prints every leaf with
        // its levels' labels, not the columns the ledger levels no longer select.
        $form = $four['form'];
        ob_start();
        $form->getExportRowHeaderHtml();
        foreach (self::leaves($four['tree']) as $leaf) {
            $form->getExportRowHtml($leaf);
        }
        $lines = explode("\n", rtrim((string) ob_get_clean(), "\n"));
        $header = explode("\t", (string) array_shift($lines));
        $tx = array_search('Transaction ID', $header, true);
        $gs = array_search('Goal / source', $header, true);
        self::assertIsInt($tx, 'the download has a Transaction ID column');
        self::assertIsInt($gs, 'and a Goal / source column');
        $pairs = [];
        foreach ($lines as $line) {
            $cells = explode("\t", $line);
            $pairs[] = $cells[$tx] . ' | ' . $cells[$gs];
        }
        self::assertContains('S-1 | Postback', $pairs);
        self::assertContains('[No transaction ID] | Goal: Reached level 3', $pairs);
        self::assertContains('B-1 | Postback', $pairs);
        self::assertContains('[Not converted] | [Not converted]', $pairs);
        self::assertSame($leaves, count($pairs), 'one download row per leaf');
    }

    /** @return array{clicks: int, leads: int, income: string, cost: string} */
    private static function figures(object $group): array
    {
        return [
            'clicks' => (int) $group->getClicks(),
            'leads' => (int) $group->getLeads(),
            'income' => number_format((float) $group->getIncome(), 2, '.', ''),
            'cost' => number_format((float) $group->getCost(), 2, '.', ''),
        ];
    }

    /** Asserts every group's children add up to it; returns the number of leaves under $group. */
    private static function assertAddsUp(object $group, int $depth): int
    {
        $children = $group->getChildArrayBySort();
        if ($children === []) {
            return 1;
        }
        $sum = ['clicks' => 0, 'leads' => 0, 'income' => 0.0, 'cost' => 0.0];
        $leaves = 0;
        foreach ($children as $child) {
            $f = self::figures($child);
            $sum['clicks'] += $f['clicks'];
            $sum['leads'] += $f['leads'];
            $sum['income'] += (float) $f['income'];
            $sum['cost'] += (float) $f['cost'];
            $leaves += self::assertAddsUp($child, $depth + 1);
        }
        $sum['income'] = number_format($sum['income'], 2, '.', '');
        $sum['cost'] = number_format($sum['cost'], 2, '.', '');
        self::assertSame(self::figures($group), $sum, 'a depth ' . $depth . ' group is the sum of its children');

        return $leaves;
    }

    /** @return list<object> */
    private static function leaves(object $group): array
    {
        $children = $group->getChildArrayBySort();
        if ($children === []) {
            return [$group];
        }
        $out = [];
        foreach ($children as $child) {
            array_push($out, ...self::leaves($child));
        }

        return $out;
    }

    public function testTheReportSqlOnlyReadsTheClicksItIsScopedTo(): void
    {
        $this->campaign(1, 'replace');
        $this->click(200, 1);
        $this->click(201, 1, '0.00', 0, 2);
        $this->record(200, ['payout' => '5', 'transaction_id' => 'A']);
        $this->repo->record(2, ['click_id' => 201, 'payout' => '6', 'transaction_id' => 'B', 'source' => 'postback']);

        $clicks = array_column(self::$db->query('SELECT p.click_id FROM ' . LedgerReportSql::partsTable('s.user_id = 1') . ' p')->fetch_all(MYSQLI_ASSOC), 'click_id');
        self::assertSame(['200'], array_map('strval', $clicks));
    }

    public function testTheBreakdownExplainsEveryRowOfAClick(): void
    {
        $ids = $this->buildEveryShape();
        $breakdown = new ClickBreakdown(new Connection(self::$db));

        $b = $breakdown->forClick(101, 1);
        self::assertNotNull($b);
        self::assertSame('accumulate', $b['click']['payout_mode']);
        self::assertTrue($b['click']['matches_click']);
        self::assertSame('2.00000', $b['click']['ledger_value'], 'S-1 5 reversed -5, S-2 deleted, the paid goal 2');
        $byId = array_column($b['rows'], null, 'conv_id');
        self::assertTrue($byId[$ids['101S1']]['counted']);
        self::assertSame('deleted', $byId[$ids['101S2']]['not_counted_reason']);
        self::assertTrue($byId[$ids['101G']]['counted']);
        self::assertSame(['type' => 'goal', 'goal_id' => 40, 'goal_version' => 2, 'name' => 'Reached level 3', 'archived' => false,
            'label' => 'Goal "Reached level 3" v2'], $byId[$ids['101G']]['linked_to']);
        self::assertSame('level_reached', $byId[$ids['101G']]['event_name']);
        self::assertSame('unpaid', $byId[$ids['101U']]['not_counted_reason']);
        self::assertTrue($byId[$ids['101R']]['counted']);
        self::assertSame('-5.00000', $byId[$ids['101R']]['amount']);
        self::assertSame($ids['101S1'], $byId[$ids['101R']]['reverses_conv_id']);
        self::assertSame('Reverses conversion ' . $ids['101S1'], $byId[$ids['101R']]['linked_to']['label']);

        $b = $breakdown->forClick(100, 1);
        $byId = array_column($b['rows'], null, 'conv_id');
        self::assertSame('superseded', $byId[$ids['100A']]['not_counted_reason']);
        self::assertSame('replace', $byId[$ids['100A']]['superseded_reason']);
        self::assertSame($ids['100B'], $byId[$ids['100A']]['superseded_by']);
        self::assertSame('A later conversion replaced this value (the campaign pays the latest conversion).', $byId[$ids['100A']]['explanation']);

        $b = $breakdown->forClick(102, 1);
        self::assertSame('4.50000', $b['click']['ledger_value'], 'the newest batch, summed');
        self::assertSame(['superseded', 'superseded', 'superseded', null, null], array_column($b['rows'], 'not_counted_reason'));
        self::assertSame(['batch', 'batch', 'batch', null, null], array_column($b['rows'], 'superseded_reason'), 'the newest batch replaces the postback and the older batch');
        self::assertSame('Upload 2 (second.csv)', $b['rows'][4]['linked_to']['label']);

        $b = $breakdown->forClick(103, 1);
        $byId = array_column($b['rows'], null, 'conv_id');
        self::assertSame('not_netted', $byId[$ids['103R']]['not_counted_reason'], 'a reversal of a superseded sale nets nothing');
        self::assertSame('8.00000', $b['click']['ledger_value']);

        $b = $breakdown->forClick(104, 1);
        self::assertSame('ledger', $b['click']['ledger_state']);
        self::assertSame('8.00000', $b['click']['ledger_value'], 'the carried 12, less the 4 reversed');
        $byId = array_column($b['rows'], null, 'conv_id');
        self::assertSame('pre_ledger', $byId[$ids['104OLD']]['superseded_reason']);
        self::assertTrue($byId[$ids['104R']]['counted']);
        self::assertContains('legacy_baseline', array_column($b['rows'], 'source'));

        $b = $breakdown->forClick(106, 1);
        $byId = array_column($b['rows'], null, 'conv_id');
        self::assertSame('replay', $byId[$ids['106G1']]['superseded_reason']);

        $b = $breakdown->forClick(107, 1);
        self::assertSame('pre_ledger', $b['click']['ledger_state'], 'a pre-ledger lead holds its value in its cache');
        self::assertTrue($b['click']['matches_click']);
        self::assertSame([], $b['rows']);

        $b = $breakdown->forClick(108, 1);
        self::assertFalse($b['click']['lead']);
        self::assertNull($b['click']['ledger_value']);
        self::assertTrue($b['click']['matches_click']);
    }

    public function testTheBreakdownIsScopedToItsOwnerAndSaysWhenTheCacheDisagrees(): void
    {
        $this->campaign(1, 'replace');
        $this->click(300, 1, '0.00', 0, 2);
        $breakdown = new ClickBreakdown(new Connection(self::$db));
        self::assertNull($breakdown->forClick(300, 1), 'another account\'s click is not found');
        self::assertNotNull($breakdown->forClick(300, 2));
        self::assertNotNull($breakdown->forClick(300, null), 'a session that sees every account');
        self::assertNull($breakdown->forClick(999, null));

        $this->repo->record(2, ['click_id' => 300, 'payout' => '5', 'transaction_id' => 'A', 'source' => 'postback']);
        self::$db->query('UPDATE 202_clicks SET click_payout = 7 WHERE click_id = 300');
        $b = $breakdown->forClick(300, 2);
        self::assertFalse($b['click']['matches_click'], 'a cache that is not what its rows add up to is reported, not hidden');
        self::assertSame('5.00000', $b['click']['ledger_value']);
        self::assertSame('7.00000', $b['click']['click_payout']);
    }

    public function testAnApiKeyReferenceNamesTheKeyWithoutRevealingIt(): void
    {
        $this->campaign(1, 'replace');
        $this->click(400, 1);
        self::fixture("INSERT INTO 202_api_keys SET user_id=1, api_key='secret-key-one', created_at=1758758400");
        $this->record(400, ['payout' => '5', 'transaction_id' => 'K1', 'source' => 'api', 'source_ref' => SourceRef::apiKey('secret-key-one')]);
        $this->record(400, ['payout' => '6', 'transaction_id' => 'K2', 'source' => 'api', 'source_ref' => SourceRef::apiKey('revoked-key')]);

        $b = (new ClickBreakdown(new Connection(self::$db)))->forClick(400, 1);
        self::assertSame('API key created 2025-09-25', $b['rows'][0]['linked_to']['label']);
        self::assertFalse($b['rows'][0]['linked_to']['revoked']);
        self::assertSame('An API key since revoked', $b['rows'][1]['linked_to']['label']);
        $json = json_encode($b, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-key-one', $json);
    }
}
