<?php

declare(strict_types=1);

namespace Tests\Conversion;

use Api\V3\Controllers\ConversionsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\ConversionBackfill;
use Prosper202\Click\LatestVisit;
use Prosper202\Conversion\Ledger\ClickBreakdown;
use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Identity\ClickIdentity;
use Prosper202\Update\SubidBatch;

/**
 * A redirector re-click on a real server: click 500 has two 202_clicks rows,
 * its first visit (campaign 11 at 3.00, traffic source 21 at a CPC of 0.25)
 * and its latest (campaign 12 at 7.00, the link's source 22 at 0.90, as a
 * re-click wrote it before it kept the first visit's), each with its own
 * time. Every conversion path credits the latest visit's campaign, payout and
 * time, and announces the conversion through the first visit's traffic
 * source with the first visit's CPC.
 *
 * The visits are stored the way the writer stores them, the earlier first,
 * which is also the row a read with no order gets; the source reads are run
 * with the latest stored first too, the row such a read gets then.
 *
 * @group integration
 */
final class ReClickedConversionIntegrationTest extends TestCase
{
    private const CLICK = 500;
    private const FIRST_TIME = 1_700_000_000;
    private const LATEST_TIME = 1_700_000_600;

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {}'
                . ' public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
        }
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
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
        $tables = [
            '202_conversion_logs', '202_clicks', '202_clicks_spy', '202_clicks_rotator', '202_clicks_tracking',
            '202_clicks_advance', '202_clicks_site', '202_google', '202_cpa_trackers', '202_tracking_c1',
            '202_aff_campaigns', '202_attribution_pending', '202_attribution_backfill', '202_dataengine',
            '202_ppc_account_pixels', '202_notification_pending', '202_identity_observations', '202_identity_signals',
            '202_identity_keys', '202_click_latest_visits',
        ];
        foreach ($tables as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        self::fixture('DELETE FROM 202_ppc_accounts WHERE ppc_account_id IN (21, 22)');
        self::campaign(11, '3.00');
        self::campaign(12, '7.00');
        foreach ([21 => 'first', 22 => 'link'] as $account => $host) {
            self::fixture("INSERT INTO 202_ppc_accounts SET ppc_account_id=$account, user_id=1, ppc_network_id=1,"
                . " ppc_account_name='a$account', ppc_account_time=1");
            self::fixture("INSERT INTO 202_ppc_account_pixels SET pixel_id=$account, ppc_account_id=$account,"
                . " pixel_type_id=4, pixel_code='https://$host.example/pb?sub=[[subid]]&cpc=[[cpc2]]'");
        }
        self::fixture("INSERT INTO 202_tracking_c1 SET c1_id=1, c1='kw-one'");
        self::fixture('INSERT INTO 202_clicks_tracking SET click_id=' . self::CLICK
            . ', c1_id=1, c2_id=0, c3_id=0, c4_id=0');
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

    private static function campaign(int $id, string $payout, int $identity = 1): void
    {
        self::fixture("INSERT INTO 202_aff_campaigns SET aff_campaign_id=$id, user_id=1, aff_network_id=1,"
            . " aff_campaign_name='c$id', aff_campaign_url='http://x', aff_campaign_payout=$payout,"
            . " aff_campaign_time=1, aff_campaign_foreign_payout=$payout, payout_mode='replace',"
            . " identity_signals=$identity");
    }

    /** One visit's rows, as MysqlClickRepository writes them; the latest carries its route. */
    private static function visit(bool $latest, int $lead = 0, ?string $payout = null): void
    {
        [$campaign, $ppc, $cpc, $time, $rotator, $redirect] = $latest
            ? [12, 22, '0.90', self::LATEST_TIME, 5, 9]
            : [11, 21, '0.25', self::FIRST_TIME, 0, 0];
        $payout ??= $latest ? '7.00' : '3.00';
        $columns = 'click_id=' . self::CLICK . ", user_id=1, aff_campaign_id=$campaign, ppc_account_id=$ppc,"
            . " click_cpc=$cpc, click_payout=$payout, click_lead=$lead, click_time=$time";
        self::fixture("INSERT INTO 202_clicks SET $columns, rotator_id=$rotator, rule_id=$redirect");
        self::fixture("INSERT INTO 202_clicks_spy SET $columns");
        if ($latest) {
            self::fixture('INSERT INTO 202_clicks_rotator SET click_id=' . self::CLICK
                . ", rotator_id=$rotator, rule_id=3, rule_redirect_id=$redirect");
        }
    }

    /** Both visits, and the latest recorded, as a re-click records it. */
    private static function reClicked(bool $latestFirst = false): void
    {
        foreach ($latestFirst ? [true, false] : [false, true] as $latest) {
            self::visit($latest);
        }
        LatestVisit::refresh(new Connection(self::$db), self::CLICK);
    }

    /** @return array<string, mixed> */
    private static function one(string $sql): array
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $rows, $sql);

        return $rows[0];
    }

    /** @return list<array<string, mixed>> */
    private static function conversions(): array
    {
        return self::$db->query('SELECT campaign_id, click_payout, click_time, source FROM 202_conversion_logs'
            . ' WHERE click_id = ' . self::CLICK . ' ORDER BY conv_id')->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array{campaign_id: string, click_payout: string, click_time: string} */
    private static function credited(): array
    {
        return ['campaign_id' => '12', 'click_payout' => '7.00000', 'click_time' => (string) self::LATEST_TIME];
    }

    /** @return array<string, array{bool}> */
    public static function storedOrders(): iterable
    {
        yield 'the earlier visit stored first' => [false];
        yield 'the latest visit stored first' => [true];
    }

    /** @dataProvider storedOrders */
    public function testThePostbackReadsCreditTheLatestVisitAndTakeTheFirstVisitsSource(bool $latestFirst): void
    {
        self::reClicked($latestFirst);

        $click = self::one(p202PostbackClickSql(self::CLICK));
        self::assertSame(
            ['1', '12', '7.00000', '0', (string) self::LATEST_TIME, 'kw-one'],
            array_map('strval', [
                $click['user_id'], $click['aff_campaign_id'], $click['click_payout'], $click['click_lead'],
                $click['click_time'], $click['c1'],
            ]),
            'gpb.php and upx.php credit the latest visit'
        );
        $source = self::one(p202ClickSourceSql(self::CLICK, 1));
        self::assertSame(
            ['21', '0.25000'],
            [(string) $source['ppc_account_id'], (string) $source['click_cpc']],
            'and fire the first visit\'s traffic source with its CPC'
        );

        $pixel = self::one(p202PixelClickSql(self::CLICK));
        self::assertSame(
            ['1', '12', (string) self::LATEST_TIME],
            array_map('strval', [$pixel['user_id'], $pixel['aff_campaign_id'], $pixel['click_time']]),
            'gpx.php credits the latest visit'
        );
    }

    /** @dataProvider storedOrders */
    public function testTheClicksTokensAreTheFirstVisitsSource(bool $latestFirst): void
    {
        self::reClicked($latestFirst);

        $click = TrafficSourcePixels::clickTokens(new Connection(self::$db), 1, self::CLICK);
        self::assertNotNull($click);
        self::assertSame(
            [21, '0.25000', 'kw-one'],
            [$click['ppc_account_id'], $click['tokens']['cpc2'], $click['tokens']['c1']]
        );
    }

    /**
     * A postback whose endpoint read the click before a re-click committed:
     * what it read is not what the conversion credits. The campaign, time
     * and time_difference are the visit the writer's lock reads, whatever
     * the caller's log says.
     */
    public function testAPostbackReadBeforeAReClickCreditsTheVisitTheLockReads(): void
    {
        self::visit(false);
        $click = self::one(p202PostbackClickSql(self::CLICK));
        self::assertSame('11', (string) $click['aff_campaign_id']);
        self::visit(true);
        LatestVisit::refresh(new Connection(self::$db), self::CLICK);

        $result = p202RecordConversion(self::$db, [
            'click_id' => self::CLICK,
            'campaign_id' => (string) $click['aff_campaign_id'],
            'user_id' => (string) $click['user_id'],
            'click_time' => (int) $click['click_time'],
            'conv_time' => self::LATEST_TIME + 3661,
            'time_difference' => 'from the read before the re-click',
            'ip' => '',
            'pixel_type' => 2,
            'user_agent' => '',
            'click_payout' => '',
            'once_per_click' => false,
        ], '', false, '', 'TX-AFTER-RECLICK');

        self::assertGreaterThan(0, $result['conv_id']);
        self::assertSame([self::credited() + ['source' => 'postback']], self::conversions());
        $row = self::one('SELECT time_difference FROM 202_conversion_logs WHERE conv_id = ' . (int) $result['conv_id']);
        self::assertSame('0 days, 1 hours, 1 min and 1 sec', $row['time_difference']);
    }

    /** gpb.php's conversion, as it records one from what it read. */
    public function testAPostbackConversionCreditsTheLatestVisitAndQueuesTheFirstVisitsPostback(): void
    {
        self::reClicked();
        $click = self::one(p202PostbackClickSql(self::CLICK));

        $result = p202RecordConversion(self::$db, [
            'click_id' => self::CLICK,
            'user_id' => (string) $click['user_id'],
            'conv_time' => self::LATEST_TIME + 60,
            'ip' => '',
            'pixel_type' => 2,
            'user_agent' => '',
            'click_payout' => '',
            'once_per_click' => true,
            'notify_traffic_source' => true,
        ], '', false, '');

        self::assertGreaterThan(0, $result['conv_id']);
        self::assertSame([self::credited() + ['source' => 'postback']], self::conversions());
        $pending = self::$db->query('SELECT url FROM 202_notification_pending')->fetch_all(MYSQLI_ASSOC);
        self::assertSame(
            ['https://first.example/pb?sub=500&cpc=0.25000'],
            array_column($pending, 'url'),
            'the first visit\'s traffic source is told'
        );
    }

    /** pb.php's campaign check: any visit's campaign, the sale the visitor made before a re-click moved them on. */
    public function testALegacyConversionTakesAnyVisitsCampaignAndCreditsTheLatestVisit(): void
    {
        self::reClicked();
        self::campaign(13, '5.00');

        $refused = p202RecordLegacyConversion(self::$db, self::CLICK, 1, ['campaign_id' => 13]);
        self::assertSame('campaign_mismatch', $refused['reason'], 'pb.php naming a campaign no visit was on');
        $recorded = p202RecordLegacyConversion(self::$db, self::CLICK, 1, ['campaign_id' => 11, 'user_id' => 1]);
        self::assertTrue($recorded['recorded'], 'pb.php naming the first visit\'s campaign: ' . json_encode($recorded));
        self::assertSame([self::credited() + ['source' => 'legacy_pixel']], self::conversions(), 'credited to the latest visit');
    }

    public function testAnApiConversionCreditsTheLatestVisit(): void
    {
        self::reClicked();
        // The report row an earlier rollup wrote for the first visit.
        self::fixture('INSERT INTO 202_dataengine SET user_id=1, click_id=' . self::CLICK
            . ', click_time=' . self::FIRST_TIME . ', aff_campaign_id=11, clicks=1');

        (new ConversionsController(self::$db, 1))->create(['click_id' => self::CLICK, 'transaction_id' => 'API-1']);

        self::assertSame([self::credited() + ['source' => 'api']], self::conversions());
        $report = self::$db->query('SELECT aff_campaign_id, click_time, leads, payout FROM 202_dataengine'
            . ' WHERE click_id = ' . self::CLICK)->fetch_all(MYSQLI_ASSOC);
        self::assertSame(
            [['aff_campaign_id' => '12', 'click_time' => (string) self::LATEST_TIME, 'leads' => '1',
                'payout' => '7.00']],
            $report,
            'the click is reported once, as its latest visit, with the lead'
        );
        $breakdown = (new ClickBreakdown(new Connection(self::$db)))->forClick(self::CLICK, 1);
        self::assertNotNull($breakdown);
        self::assertSame([12, true, '7.00000'], [
            $breakdown['click']['campaign_id'], $breakdown['click']['lead'], $breakdown['click']['click_payout'],
        ], 'the breakdown shows the visit the ledger credits');
        self::assertTrue($breakdown['click']['matches_click']);
    }

    /**
     * A conversion that counts once per click asks whether any visit of the
     * click is a lead (LatestVisit::anyVisitIsLead()). A re-click written
     * before 1.9.78 stored its row as no lead, and such a click has no
     * record of its latest visit until the 1.9.78 backfill reaches it (none
     * here): read from its latest visit alone, an id-less retry converted a
     * click converted on its first visit again (in replace mode, replacing
     * the sale at the latest visit's payout), where 1.9.77 read the first
     * visit and refused. Every id-less path refuses it: the fast paths
     * (pb.php, gpx.php, gpb.php and upx.php, Update Subids) and the check
     * under the click's lock.
     *
     * @dataProvider storedOrders
     */
    public function testALeadOnAnEarlierVisitStopsAnIdLessConversion(bool $latestFirst): void
    {
        foreach ($latestFirst ? [true, false] : [false, true] as $latest) {
            self::visit($latest, $latest ? 0 : 1);
        }
        $repo = new MysqlConversionRepository(new Connection(self::$db));
        // The first visit's conversion, in the ledger.
        self::fixture('INSERT INTO 202_conversion_logs SET click_id=' . self::CLICK
            . ", transaction_id='T-1', campaign_id=11, click_payout=3.00, user_id=1, click_time=" . self::FIRST_TIME
            . ", conv_time=1, time_difference='', ip='', pixel_type=0, user_agent='', deleted=0, source='api',"
            . " payable=1, dedupe_key='tx:T-1'");
        $before = self::conversions();

        $preview = (new SubidBatch(new Connection(self::$db), $repo))->markPreview(1, [(string) self::CLICK]);
        self::assertSame(0, $preview['would_mark'], (string) json_encode($preview));
        $legacy = p202RecordLegacyConversion(self::$db, self::CLICK, 1, ['user_id' => 1]);
        self::assertSame('already_lead', $legacy['reason'] ?? null, (string) json_encode($legacy));
        self::assertSame('1', (string) self::one(p202PixelClickSql(self::CLICK))['click_lead'], 'gpx.php');
        self::assertSame('1', (string) self::one(p202PostbackClickSql(self::CLICK))['click_lead'], 'gpb.php, upx.php');

        $locked = p202RecordConversion(self::$db, [
            'click_id' => self::CLICK, 'campaign_id' => '12', 'user_id' => '1', 'click_time' => self::LATEST_TIME,
            'conv_time' => self::LATEST_TIME + 60, 'time_difference' => '', 'ip' => '', 'pixel_type' => 2,
            'user_agent' => '', 'click_payout' => '', 'once_per_click' => true,
        ], '', false, '');
        self::assertSame(0, $locked['conv_id'], 'the check under the lock: ' . json_encode($locked));
        self::assertSame($before, self::conversions(), 'no conversion was recorded or replaced');
    }

    /**
     * A click whose rows name two accounts (written before the account tie)
     * and whose latest visit is the other account's row is not this
     * account's click: its conversion records nothing and changes no row.
     * The lock is by account; the visit read after it was not, so the
     * conversion took the other account's campaign, payout and time, and
     * the recompute wrote that payout onto both accounts' rows.
     */
    public function testAClickWhoseLatestVisitIsAnotherAccountsIsNotThisAccounts(): void
    {
        self::visit(false);
        self::fixture('INSERT INTO 202_clicks SET click_id=' . self::CLICK . ', user_id=2, aff_campaign_id=31,'
            . ' ppc_account_id=0, click_cpc=0, click_payout=9.00, click_lead=0, click_time=' . self::LATEST_TIME);
        $rows = static fn (): array => self::$db->query('SELECT user_id, click_lead, click_payout FROM 202_clicks'
            . ' WHERE click_id = ' . self::CLICK . ' ORDER BY click_time')->fetch_all(MYSQLI_ASSOC);
        $before = $rows();

        $result = (new MysqlConversionRepository(new Connection(self::$db)))
            ->record(1, ['click_id' => self::CLICK, 'transaction_id' => 'M-1', 'source' => 'api']);

        self::assertSame([0, false], [$result['convId'], $result['clickFound']]);
        self::assertSame([], self::conversions());
        self::assertSame($before, $rows());
    }

    /**
     * A click converted before the ledger and re-clicked since holds its
     * value on the visits that were leads; its latest visit is none. The
     * first ledger write carries that value in, from the latest visit that
     * is a lead, on every path that carries one.
     */
    public function testAPreLedgerLeadOnAnEarlierVisitIsCarriedIn(): void
    {
        self::visit(false, 1, '4.50');
        self::visit(true);
        $repo = new MysqlConversionRepository(new Connection(self::$db));

        $repo->record(1, ['click_id' => self::CLICK, 'transaction_id' => 'NEW-1', 'source' => 'api']);

        $rows = self::conversions();
        self::assertSame(
            ['campaign_id' => '11', 'click_payout' => '4.50000', 'click_time' => (string) self::FIRST_TIME,
                'source' => 'legacy_baseline'],
            $rows[0],
            'the cached value, from the visit that held it'
        );
        self::assertSame(self::credited() + ['source' => 'api'], $rows[1]);
    }

    public function testTheBackfillCarriesAPreLedgerLeadOnAnEarlierVisitOnce(): void
    {
        self::visit(false, 1, '4.50');
        self::visit(true, 1, '4.50');
        self::$db->query(ConversionBackfill::MARK_SQL);

        $done = (new ConversionBackfill(new Connection(self::$db)))->run(time() + 30);

        self::assertNotNull($done);
        self::assertSame([1, 1], [$done['clicks'], $done['baselines']], 'one click, however many visits are leads');
        self::assertSame(
            [['campaign_id' => '12', 'click_payout' => '4.50000', 'click_time' => (string) self::LATEST_TIME,
                'source' => 'legacy_baseline']],
            self::conversions(),
            'the latest visit that is a lead'
        );
    }

    public function testAConvertingClickIsLinkedByItsLatestVisitsCampaignSetting(): void
    {
        self::fixture('UPDATE 202_aff_campaigns SET identity_signals = 0 WHERE aff_campaign_id = 12');
        self::reClicked();
        $conn = new Connection(self::$db);

        self::assertNull(
            ClickIdentity::trustedCustomer('cust-1')->attachToStoredClick($conn, self::CLICK),
            'the latest visit\'s campaign captures no identity'
        );

        self::fixture('UPDATE 202_aff_campaigns SET identity_signals = 1 - identity_signals');
        self::assertNotNull(
            ClickIdentity::trustedCustomer('cust-1')->attachToStoredClick($conn, self::CLICK),
            'with its campaign capturing, the click is linked'
        );
    }

    /**
     * A lock asked for after the transaction read is refused, and nothing is
     * locked: from that read on the transaction answers from its snapshot,
     * so a re-click and a sale committed before the lock would be read as
     * absent. A locking read before the lock takes no snapshot, and a click
     * the transaction locked first is read again after a plain read.
     */
    public function testALockAskedForAfterAReadIsRefused(): void
    {
        self::visit(false);
        $conn = new Connection(self::$db);
        $other = self::otherConnection();
        $other->query('SET SESSION innodb_lock_wait_timeout = 1');
        mysqli_report(MYSQLI_REPORT_OFF);
        try {
            $refused = null;
            $answer = $conn->transaction(function () use ($conn, $other, &$refused): string {
                $conn->fetchAll($conn->prepareWrite('SELECT conv_id FROM 202_conversion_logs'));
                try {
                    (new MysqlConversionLedger($conn))->lockClick(self::CLICK, 1);
                } catch (\LogicException $e) {
                    $refused = $e->getMessage();
                }
                self::assertTrue($other->begin_transaction());
                $answer = $other->query('UPDATE 202_clicks SET click_lead = 1 WHERE click_id = ' . self::CLICK) === true
                    ? 'ran' : 'waited (' . $other->errno . ')';
                self::assertTrue($other->rollback());

                return $answer;
            });
        } finally {
            mysqli_report(MYSQLI_REPORT_STRICT);
            $other->close();
        }

        self::assertIsString($refused);
        self::assertStringContainsString(
            'Click ' . self::CLICK . '\'s lock was asked for after its transaction read',
            $refused
        );
        self::assertSame('ran', $answer, 'the refused lock locked nothing');

        $locked = $conn->transaction(function () use ($conn): array {
            $conn->fetchAll($conn->prepareWrite('SELECT conv_id FROM 202_conversion_logs FOR UPDATE'));
            $ledger = new MysqlConversionLedger($conn);
            $first = $ledger->lockClick(self::CLICK, 1);
            $conn->fetchAll($conn->prepareWrite('SELECT conv_id FROM 202_conversion_logs'));

            return [$first, $ledger->lockClick(self::CLICK, 1)];
        });
        self::assertSame('11', (string) ($locked[0]['aff_campaign_id'] ?? ''), 'a locking read takes no snapshot');
        self::assertSame($locked[0], $locked[1], 'a click locked first is read again after a plain read');
    }

    /**
     * A re-click and a sale committed while the transaction was open, before
     * its first statement: the lock, first, reads both.
     */
    public function testALockTakenFirstSeesWhatCommittedBeforeIt(): void
    {
        self::visit(false);
        $conn = new Connection(self::$db);
        $other = self::otherConnection();
        try {
            $visit = $conn->transaction(function () use ($conn, $other): ?array {
                $reClickAndSale = [
                    'INSERT INTO 202_clicks SET click_id=' . self::CLICK . ', user_id=1, aff_campaign_id=12,'
                        . ' ppc_account_id=21, click_cpc=0.25, click_payout=7.00, click_lead=1,'
                        . ' click_time=' . self::LATEST_TIME . ', rotator_id=5, rule_id=9',
                    'INSERT INTO 202_clicks_rotator SET click_id=' . self::CLICK
                        . ', rotator_id=5, rule_id=3, rule_redirect_id=9',
                    'INSERT INTO 202_click_latest_visits SET click_id=' . self::CLICK
                        . ', click_time=' . self::LATEST_TIME,
                ];
                foreach ($reClickAndSale as $sql) {
                    self::assertTrue($other->query($sql), $other->error);
                }

                return (new MysqlConversionLedger($conn))->lockClick(self::CLICK, 1);
            });
        } finally {
            $other->close();
        }

        $read = array_flip(['aff_campaign_id', 'click_payout', 'click_time', 'click_lead']);
        self::assertSame(
            [
                'aff_campaign_id' => '12', 'click_payout' => '7.00000',
                'click_time' => (string) self::LATEST_TIME, 'click_lead' => '1',
            ],
            array_map('strval', array_intersect_key($visit ?? [], $read))
        );
    }

    /**
     * Two visits in one second, written before 1.9.78: the one on the route
     * the click holds now is its latest, as LatestVisit::orderBy() reads it,
     * whichever is stored first.
     *
     * @dataProvider storedOrders
     */
    public function testTheLockTellsTwoVisitsInOneSecondByTheirRoute(bool $routeFirst): void
    {
        $visits = $routeFirst ? [[12, 5, 9], [11, 0, 0]] : [[11, 0, 0], [12, 5, 9]];
        foreach ($visits as [$campaign, $rotator, $redirect]) {
            self::fixture('INSERT INTO 202_clicks SET click_id=' . self::CLICK . ", user_id=1,"
                . " aff_campaign_id=$campaign, ppc_account_id=21, click_cpc=0.25, click_payout=3.00,"
                . ' click_time=' . self::LATEST_TIME . ", rotator_id=$rotator, rule_id=$redirect");
        }
        self::fixture('INSERT INTO 202_clicks_rotator SET click_id=' . self::CLICK
            . ', rotator_id=5, rule_id=3, rule_redirect_id=9');
        $conn = new Connection(self::$db);

        $visit = $conn->transaction(fn (): ?array => (new MysqlConversionLedger($conn))->lockClick(self::CLICK, 1));

        self::assertSame('12', (string) ($visit['aff_campaign_id'] ?? ''));
    }

    /**
     * The lock holds no gap of 202_clicks_rotator: on a click with no route
     * row, a share-locked read of the route held it, and on a click newer
     * than every redirector click every new one's route waited for the
     * conversion's commit.
     */
    public function testALockedClickWithNoRouteHoldsNoRouteGap(): void
    {
        self::fixture('INSERT INTO 202_clicks_rotator SET click_id=' . (self::CLICK - 100)
            . ', rotator_id=5, rule_id=3, rule_redirect_id=9');
        self::visit(false);
        $conn = new Connection(self::$db);
        $other = self::otherConnection();
        $other->query('SET SESSION innodb_lock_wait_timeout = 1');
        mysqli_report(MYSQLI_REPORT_OFF);
        try {
            $answer = $conn->transaction(function () use ($conn, $other): string {
                self::assertNotNull((new MysqlConversionLedger($conn))->lockClick(self::CLICK, 1));
                self::assertTrue($other->begin_transaction());
                $sql = 'INSERT INTO 202_clicks_rotator SET click_id=' . (self::CLICK + 1)
                    . ', rotator_id=5, rule_id=3, rule_redirect_id=9';
                $answer = $other->query($sql) === true ? 'ran' : 'waited (' . $other->errno . ')';
                self::assertTrue($other->rollback());

                return $answer;
            });
        } finally {
            mysqli_report(MYSQLI_REPORT_STRICT);
            $other->close();
        }

        self::assertSame('ran', $answer);
    }

    /**
     * What the click's lock holds, taken first in its transaction by
     * clickTransaction() or by lockClick(): an UPDATE of the click's rows by
     * click_id waits for it; a re-click's new row, the next click's insert
     * and another click becoming a lead do not.
     */
    public function testTheClickLockHoldsItsRowsAndNotTheNextClick(): void
    {
        self::reClicked();
        self::fixture('INSERT INTO 202_clicks SET click_id=' . (self::CLICK + 400) . ', user_id=1, aff_campaign_id=11,'
            . ' ppc_account_id=21, click_cpc=0.25, click_payout=3.00, click_time=' . self::FIRST_TIME);
        $conn = new Connection(self::$db);
        $ledger = new MysqlConversionLedger($conn);
        $other = self::otherConnection();
        $other->query('SET SESSION innodb_lock_wait_timeout = 1');
        $probe = function (?array $visit) use ($other): array {
            self::assertSame('12', (string) ($visit['aff_campaign_id'] ?? ''));
            $later = ', user_id = 1, ppc_account_id = 21, click_cpc = 0, click_time = ' . (self::LATEST_TIME + 60);
            $probes = [
                'UPDATE 202_clicks SET click_lead = 1 WHERE click_id = ' . self::CLICK
                    . ' AND click_time = ' . self::LATEST_TIME,
                'INSERT INTO 202_clicks SET click_id = ' . self::CLICK . $later,
                'INSERT INTO 202_clicks SET click_id = ' . (self::CLICK + 1) . $later,
                'UPDATE 202_clicks SET click_lead = 1 WHERE click_id = ' . (self::CLICK + 400),
            ];
            $answers = [];
            foreach ($probes as $sql) {
                self::assertTrue($other->begin_transaction());
                $answers[] = $other->query($sql) === true ? 'ran' : 'waited (' . $other->errno . ')';
                self::assertTrue($other->rollback());
            }

            return $answers;
        };
        mysqli_report(MYSQLI_REPORT_OFF);
        try {
            $opening = $ledger->clickTransaction(self::CLICK, 1, $probe);
            $joined = $conn->transaction(fn (): array => $probe($ledger->lockClick(self::CLICK, 1)));
        } finally {
            mysqli_report(MYSQLI_REPORT_STRICT);
            $other->close();
        }

        self::assertSame(['waited (1205)', 'ran', 'ran', 'ran'], $opening);
        self::assertSame($opening, $joined);
    }

    private static function otherConnection(): \mysqli
    {
        $other = @mysqli_connect(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertInstanceOf(\mysqli::class, $other);
        $other->query("SET SESSION sql_mode=''");

        return $other;
    }
}
