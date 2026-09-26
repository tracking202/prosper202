<?php

declare(strict_types=1);

namespace Tests\Identity;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Identity\ClickIdentity;
use Prosper202\Identity\CustomerId;
use Prosper202\Identity\IdentityGraph;
use Prosper202\Identity\IdentityKeys;
use Prosper202\Identity\IdentitySignal;
use Prosper202\Identity\SignalType;

/**
 * The identity graph against a real MySQL/MariaDB: merging, aliasing,
 * quarantine, the evidence rows, and the click path that writes them.
 *
 * Skips unless P202_TEST_DB_HOST (and friends) name a scratch database.
 *
 * @group integration
 */
final class IdentityGraphIntegrationTest extends TestCase
{
    private static ?\mysqli $db = null;
    private Connection $conn;
    private IdentityGraph $graph;

    private const TABLES = [
        '202_identity_keys', '202_identity_visitors', '202_identity_signals',
        '202_identity_observations', '202_identity_merges', '202_clicks_visitor',
        '202_clicks', '202_aff_campaigns', '202_clicks_counter',
    ];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
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
        foreach (self::TABLES as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        $this->conn = new Connection(self::$db);
        $this->graph = new IdentityGraph($this->conn);
    }

    private static function vid(string $v): IdentitySignal
    {
        return new IdentitySignal(SignalType::VISITOR_COOKIE, str_pad($v, 32, '0', STR_PAD_LEFT));
    }

    private static function lpid(string $v): IdentitySignal
    {
        return new IdentitySignal(SignalType::LANDING_PAGE, str_pad($v, 32, '0', STR_PAD_LEFT));
    }

    private function attach(int $click, IdentitySignal ...$signals): ?int
    {
        return $this->conn->transaction(fn (): ?int => $this->graph->attachClick(1, $click, 1700000000 + $click, $signals));
    }

    private static function scalar(string $sql): mixed
    {
        $row = self::$db->query($sql)->fetch_row();

        return $row[0] ?? null;
    }

    public function testClicksWithOneCookieShareOneVisitor(): void
    {
        $a = $this->attach(1, self::vid('a'));
        $b = $this->attach(2, self::vid('a'));
        $c = $this->attach(3, self::vid('c'));
        self::assertNotNull($a);
        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertSame($a, $this->graph->visitorOfClick(2));
        self::assertSame(2, (int) self::scalar('SELECT COUNT(*) FROM 202_identity_visitors'));
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_identity_merges'));
        self::assertSame(1700000002, (int) self::scalar('SELECT click_time FROM 202_clicks_visitor WHERE click_id=2'));
    }

    public function testNoSignalsLinksNothing(): void
    {
        self::assertNull($this->attach(9));
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM 202_clicks_visitor'));
    }

    public function testOnlyHashesAreStored(): void
    {
        $this->attach(1, self::vid('abc'));
        $raw = str_pad('abc', 32, '0', STR_PAD_LEFT);
        self::assertSame(0, (int) self::scalar("SELECT COUNT(*) FROM 202_identity_signals WHERE signal_hash LIKE '%$raw%'"));
        self::assertSame(0, (int) self::scalar("SELECT COUNT(*) FROM 202_identity_observations WHERE signal_hash LIKE '%$raw%'"));
        $hashKey = (new IdentityKeys($this->conn))->forUser(1)['hash'];
        self::assertSame(
            IdentityGraph::hash($hashKey, self::vid('abc')),
            self::scalar('SELECT signal_hash FROM 202_identity_signals')
        );
    }

    public function testAClickCarryingTwoKnownSignalsMergesTheirVisitors(): void
    {
        $x = $this->attach(1, self::vid('x'));
        $y = $this->attach(2, self::lpid('y'));
        self::assertNotSame($x, $y);

        $merged = $this->attach(3, self::vid('x'), self::lpid('y'));
        self::assertSame(max($x, $y), $merged, 'the higher key survives');
        self::assertSame($merged, $this->graph->visitorOfClick(1), 'the earlier click follows the alias');
        self::assertSame($merged, $this->graph->visitorOfClick(2));
        self::assertSame([min($x, $y), max($x, $y)], $this->graph->keysOf(1, $merged));

        $merge = self::$db->query('SELECT from_key, into_key, click_id, requeued_at FROM 202_identity_merges')->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $merge);
        self::assertSame([min($x, $y), max($x, $y), 3], [(int) $merge[0]['from_key'], (int) $merge[0]['into_key'], (int) $merge[0]['click_id']]);
        self::assertNull($merge[0]['requeued_at'], 'left for the attribution worker to re-queue');
        self::assertSame(2, (int) self::scalar('SELECT COUNT(*) FROM 202_identity_observations WHERE click_id=3'), 'both signals recorded as the evidence');
    }

    public function testAliasChainsAreCompressedToOneHop(): void
    {
        $k1 = $this->attach(1, self::vid('1'));
        $k2 = $this->attach(2, self::vid('2'));
        $k3 = $this->attach(3, self::vid('3'));
        $this->attach(4, self::vid('1'), self::vid('2'));       // k1 -> k2
        $final = $this->attach(5, self::vid('2'), self::vid('3')); // k2 -> k3, and k1 re-pointed
        self::assertSame($k3, $final);
        foreach ([$k1, $k2] as $k) {
            self::assertSame($k3, (int) self::scalar("SELECT alias_of FROM 202_identity_visitors WHERE visitor_key=$k"));
        }
        self::assertNull(self::scalar("SELECT alias_of FROM 202_identity_visitors WHERE visitor_key=$k3"));
        self::assertSame([$k1, $k2, $k3], $this->graph->keysOf(1, $k3));
        // A new click by the oldest signal lands on the canonical key.
        self::assertSame($k3, $this->attach(6, self::vid('1')));
    }

    public function testASignalThatJoinsTooManyVisitorsIsQuarantined(): void
    {
        // A shared "cust=test": every tester's cookie gets merged by it.
        $shared = new IdentitySignal(SignalType::CUSTOMER, 'test');
        $this->attach(1000, $shared);
        for ($i = 1; $i <= IdentityGraph::QUARANTINE_AFTER + 1; $i++) {
            $this->attach($i, self::vid((string) $i));
            $this->attach(100 + $i, self::vid((string) $i), $shared);
        }
        $row = self::$db->query("SELECT merges, quarantined_at FROM 202_identity_signals WHERE signal_type='cust'")->fetch_assoc();
        self::assertSame(IdentityGraph::QUARANTINE_AFTER + 1, (int) $row['merges']);
        self::assertNotNull($row['quarantined_at']);

        // Quarantined: still observed, links nothing more.
        $lonely = $this->attach(500, self::vid('lonely'));
        $after = $this->attach(501, self::vid('lonely'), $shared);
        self::assertSame($lonely, $after, 'the quarantined signal did not pull this visitor in');
        self::assertSame(1, (int) self::scalar("SELECT COUNT(*) FROM 202_identity_observations WHERE click_id=501 AND signal_type='cust'"));
        self::assertSame(IdentityGraph::QUARANTINE_AFTER + 1, (int) self::scalar('SELECT COUNT(*) FROM 202_identity_merges'));
    }

    public function testASignedCustomerJoinsTwoDevices(): void
    {
        $link = (new IdentityKeys($this->conn))->forUser(1)['link'];
        $digest = hash('sha256', 'ana@example.com');
        $sig = CustomerId::sign($link, 'email_sha256:' . $digest);
        $get = ['cust' => strtoupper($digest), 'cust_type' => 'email_sha256', 'cust_sig' => $sig];

        $laptop = ClickIdentity::fromRequest([], ['p202vid' => str_repeat('a', 32)], true)->attach($this->conn, 1, 1, 1700000001);
        $phone = ClickIdentity::fromRequest([], ['p202vid' => str_repeat('b', 32)], true)->attach($this->conn, 1, 2, 1700000002);
        self::assertNotSame($laptop, $phone);

        $afterLaptopBuy = ClickIdentity::customerOnly($get)->attach($this->conn, 1, 1, 1700000001);
        self::assertSame($laptop, $afterLaptopBuy, 'first sighting of the customer: attached, nothing merged');
        $joined = ClickIdentity::customerOnly($get)->attach($this->conn, 1, 2, 1700000002);
        self::assertSame(max($laptop, $phone), $joined);
        self::assertSame($joined, $this->graph->visitorOfClick(1));
    }

    public function testAForgedSignatureLinksNothing(): void
    {
        $phone = ClickIdentity::fromRequest([], ['p202vid' => str_repeat('b', 32)], true)->attach($this->conn, 1, 2, 1700000002);
        $forged = ['cust' => 'ana', 'cust_sig' => str_repeat('0', 64)];
        self::assertNull(ClickIdentity::customerOnly($forged)->attach($this->conn, 1, 2, 1700000002), 'nothing to link');
        self::assertSame($phone, $this->graph->visitorOfClick(2), 'the click keeps the visitor it had');
        self::assertSame(0, (int) self::scalar("SELECT COUNT(*) FROM 202_identity_signals WHERE signal_type='cust'"));
    }

    public function testKeysAreMintedOncePerAccountAndRefusedWhenCorrupt(): void
    {
        $a = (new IdentityKeys($this->conn))->forUser(7);
        $b = (new IdentityKeys($this->conn))->forUser(7);
        self::assertSame($a, $b);
        self::assertNotSame($a['hash'], $a['link']);
        self::assertNotSame($a, (new IdentityKeys($this->conn))->forUser(8));

        self::$db->query("UPDATE 202_identity_keys SET hash_key='short' WHERE user_id=7");
        $this->expectException(\RuntimeException::class);
        (new IdentityKeys($this->conn))->forUser(7);
    }

    public function testAConversionOnACampaignWithCaptureOffLinksNothing(): void
    {
        self::$db->query("SET SESSION sql_mode=''");
        self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id=5, user_id=1, identity_signals=0, aff_campaign_name='c'");
        self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id=6, user_id=1, identity_signals=1, aff_campaign_name='d'");
        self::$db->query('INSERT INTO 202_clicks SET click_id=40, user_id=1, aff_campaign_id=5, click_time=1700000040');
        self::$db->query('INSERT INTO 202_clicks SET click_id=41, user_id=1, aff_campaign_id=6, click_time=1700000041');
        self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");

        $trusted = ClickIdentity::trustedCustomer('acct-1');
        self::assertNull($trusted->attachToStoredClick($this->conn, 40));
        self::assertNotNull($trusted->attachToStoredClick($this->conn, 41));
        self::assertSame(1700000041, (int) self::scalar('SELECT click_time FROM 202_clicks_visitor WHERE click_id=41'));
        self::assertNull($trusted->attachToStoredClick($this->conn, 999), 'no such click');
    }

    public function testTheClickPathLinksAfterCommitAndKeepsTheClickWhenLinkingFails(): void
    {
        $repo = new MysqlClickRepository($this->conn);
        $click = new ClickRecord();
        $click->clickId = $repo->allocateClickId();
        $click->userId = 1;
        $click->clickTime = 1700000100;
        $click->clickIdPublic = '0';
        $click->identity = ClickIdentity::fromRequest([], ['p202vid' => str_repeat('c', 32)], true);
        $id = $repo->recordClick($click);
        self::assertSame(1, (int) self::scalar("SELECT COUNT(*) FROM 202_clicks WHERE click_id=$id"));
        self::assertNotNull(self::scalar("SELECT visitor_key FROM 202_clicks_visitor WHERE click_id=$id"));

        // Break the graph: the click must still be stored.
        self::$db->query('RENAME TABLE 202_identity_signals TO 202_identity_signals_gone');
        $log = tempnam(sys_get_temp_dir(), 'idlog');
        $old = ini_set('error_log', (string) $log);
        try {
            $second = new ClickRecord();
            $second->clickId = $repo->allocateClickId();
            $second->userId = 1;
            $second->clickTime = 1700000200;
            $second->clickIdPublic = '0';
            $second->identity = ClickIdentity::fromRequest([], ['p202vid' => str_repeat('c', 32)], true);
            $id2 = $repo->recordClick($second);
        } finally {
            ini_set('error_log', (string) $old);
            self::$db->query('RENAME TABLE 202_identity_signals_gone TO 202_identity_signals');
        }
        self::assertSame(1, (int) self::scalar("SELECT COUNT(*) FROM 202_clicks WHERE click_id=$id2"));
        self::assertNull(self::scalar("SELECT visitor_key FROM 202_clicks_visitor WHERE click_id=$id2"));
        self::assertSame(0, (int) self::scalar("SELECT COUNT(*) FROM 202_identity_observations WHERE click_id=$id2"), 'the failed link rolled back whole');
        self::assertStringContainsString("click $id2 was stored but not linked", (string) file_get_contents((string) $log));
        @unlink((string) $log);
    }
}
