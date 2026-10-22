<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Conversion\Ledger\SupersededReason;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;

/**
 * A delete waits on its click's lock while another transaction writes the
 * click's ledger, and must then read what that transaction committed: the
 * sale it recorded, the reversal, the legacy baseline it carried in, the
 * revenue it posted again. This connection holds the click's lock in a
 * transaction that writes, while the delete runs in a child process
 * (fixtures/soft-delete-runner.php), and commits once the server shows the
 * child waiting.
 *
 * Each case failed while the delete read the conversion to find its click
 * inside its transaction: under REPEATABLE READ that read took the snapshot
 * every later plain read answered from, whatever the lock let in after.
 *
 * @group integration
 */
final class LedgerReadsAfterTheLockIntegrationTest extends TestCase
{
    private const CLICK = 700;
    private const USER = 1;

    private static ?\mysqli $db = null;

    private MysqlConversionRepository $repo;

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
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = self::connect();
        } catch (\Throwable) {
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
            '202_conversion_logs', '202_clicks', '202_clicks_spy', '202_click_latest_visits', '202_aff_campaigns',
            '202_attribution_pending', '202_dataengine', '202_revenue_events', '202_revenue_line_items',
            '202_customers', '202_customer_aliases', '202_notification_pending',
        ];
        foreach ($tables as $table) {
            self::$db->query('TRUNCATE TABLE ' . $table);
        }
        self::fixture('INSERT INTO 202_aff_campaigns SET aff_campaign_id=11, user_id=' . self::USER
            . ', aff_network_id=1,'
            . " aff_campaign_name='c11', aff_campaign_url='http://x', aff_campaign_payout=3.00,"
            . " aff_campaign_time=1, aff_campaign_foreign_payout=3.00, payout_mode='replace'");
        $this->repo = new MysqlConversionRepository(new Connection(self::$db));
    }

    private static function connect(): \mysqli
    {
        return new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
    }

    private static function fixture(string $sql): void
    {
        self::$db->query("SET SESSION sql_mode=''");
        try {
            self::assertTrue(self::$db->query($sql), self::$db->error);
        } finally {
            self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        }
    }

    private static function click(int $lead = 0, string $payout = '3.00'): void
    {
        $columns = 'click_id=' . self::CLICK . ', user_id=' . self::USER . ', aff_campaign_id=11, ppc_account_id=0,'
            . " click_cpc=0, click_payout=$payout, click_lead=$lead, click_time=1700000000";
        self::fixture("INSERT INTO 202_clicks SET $columns");
        self::fixture("INSERT INTO 202_clicks_spy SET $columns");
    }

    /** @param array<string, mixed> $data */
    private function record(array $data): int
    {
        $result = $this->repo->record(self::USER, ['click_id' => self::CLICK] + $data);
        self::assertFalse($result['duplicate']);

        return (int) $result['convId'];
    }

    /**
     * Run $write in a transaction that holds the click's lock, then delete
     * $convId in the child, and commit $write's transaction once the child
     * waits on the lock.
     *
     * @param callable(Connection): void $write
     * @return string the child's outcome: `ok`, or the failure
     */
    private static function deleteWhile(int $convId, callable $write): string
    {
        $holder = self::connect();
        $holder->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        $conn = new Connection($holder);
        $child = null;
        try {
            $conn->transaction(function () use ($conn, $write, $convId, &$child): void {
                self::assertNotNull((new MysqlConversionLedger($conn))->lockClick(self::CLICK));
                $write($conn);
                $child = self::waitingDelete($convId);
            });
        } finally {
            $holder->close();
        }
        [$proc, $pipes] = $child;
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertSame(0, $exit, "exit $exit\n$out$err");

        return $out;
    }

    /** @return array{resource, array<int, resource>} the child, once it waits on a lock */
    private static function waitingDelete(int $convId): array
    {
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/soft-delete-runner.php',
                (string) $convId, (string) self::USER,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        $thread = trim((string) fgets($pipes[1]));
        self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $thread, 'the runner names its connection');
        $deadline = microtime(true) + 15;
        do {
            $waiting = self::$db->query('SELECT COUNT(*) FROM information_schema.INNODB_TRX'
                . " WHERE trx_mysql_thread_id = $thread AND trx_state = 'LOCK WAIT'");
            self::assertInstanceOf(\mysqli_result::class, $waiting, self::$db->error);
            if ((int) $waiting->fetch_row()[0] === 1) {
                return [$proc, $pipes];
            }
            // The server refreshes INNODB_TRX only once it has gone 0.1s unread.
            usleep(250000);
        } while (microtime(true) < $deadline);
        self::fail("The delete on connection $thread never waited for a lock: "
            . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
    }

    /** @return array{lead: int, payout: string} */
    private static function clickValue(): array
    {
        $row = self::$db->query('SELECT click_lead, click_payout FROM 202_clicks WHERE click_id = ' . self::CLICK)
            ->fetch_assoc();

        return ['lead' => (int) $row['click_lead'], 'payout' => (string) $row['click_payout']];
    }

    private static function customerTotal(): string
    {
        $row = self::$db->query(
            'SELECT COALESCE(SUM(amount), 0) AS total FROM 202_revenue_events WHERE user_id = ' . self::USER
        )
            ->fetch_assoc();

        return number_format((float) $row['total'], 2, '.', '');
    }

    /** @return list<string> */
    private static function voidKeys(): array
    {
        $rows = self::$db->query("SELECT idempotency_key FROM 202_revenue_events WHERE idempotency_key LIKE 'void:%'"
            . ' ORDER BY event_id')->fetch_all(MYSQLI_NUM);

        return array_map(static fn (array $r): string => (string) $r[0], $rows);
    }

    /**
     * The click's value after the delete counts the sale recorded while the
     * delete waited. Recomputed from rows read before that sale committed,
     * the click was left no lead under a counted $9 sale.
     */
    public function testADeleteRecomputesWithTheSaleCommittedInItsWait(): void
    {
        self::click();
        $first = $this->record(['payout' => '5', 'transaction_id' => 'TX-1']);

        $outcome = self::deleteWhile($first, function (Connection $conn): void {
            (new MysqlConversionRepository($conn))->recordInTransaction(
                self::USER,
                ['click_id' => self::CLICK, 'payout' => '9', 'transaction_id' => 'TX-2']
            );
        });

        self::assertSame('ok', $outcome);
        self::assertSame(['lead' => 1, 'payout' => '9.00000'], self::clickValue());
    }

    /**
     * A reversal of the sale recorded while its delete waited stops netting
     * with the sale, so its revenue is voided with the sale's, as it is when
     * nothing overlaps. Read before the reversal committed, it was left
     * standing: the customer kept a $10 refund of a sale that is gone.
     */
    public function testADeleteVoidsTheReversalRecordedInItsWait(): void
    {
        self::click();
        $customer = ['customer_ref' => 'cust-700', 'customer_ref_type' => 'custom'];
        $sale = $this->record(['payout' => '10', 'transaction_id' => 'TX-1'] + $customer);
        $reversal = 0;

        $outcome = self::deleteWhile($sale, function (Connection $conn) use ($customer, &$reversal): void {
            $recorded = (new MysqlConversionRepository($conn))->recordInTransaction(
                self::USER,
                ['click_id' => self::CLICK, 'transaction_id' => 'TX-1', 'reversal' => true] + $customer
            );
            $reversal = (int) $recorded['convId'];
        });

        self::assertSame('ok', $outcome);
        self::assertGreaterThan(0, $reversal);
        self::assertSame(['void:conv:' . $sale, 'void:conv:' . $reversal], self::voidKeys());
        self::assertSame('0.00', self::customerTotal());
    }

    /**
     * A pre-ledger lead whose value the ledger carried in as its baseline
     * while the delete waited: the delete finds the baseline and carries in
     * nothing. Read before it committed, the delete inserted a second
     * baseline, and failed on the click's (click_id, dedupe_key) key.
     */
    public function testADeleteFindsTheBaselineCarriedInDuringItsWait(): void
    {
        self::click(1, '9.00');
        self::fixture('INSERT INTO 202_conversion_logs SET click_id=' . self::CLICK . ', user_id=' . self::USER
            . ", campaign_id=11, click_payout=9.00, click_time=1700000000, conv_time=1700000100, time_difference='',"
            . " ip='', pixel_type=0, user_agent='', deleted=0, source='pixel', payable=1,"
            . " superseded_reason='pre_ledger', dedupe_key='pre-ledger-1'");
        $preLedger = (int) self::$db->query("SELECT conv_id FROM 202_conversion_logs WHERE dedupe_key = 'pre-ledger-1'")
            ->fetch_row()[0];
        self::assertGreaterThan(0, $preLedger);

        $outcome = self::deleteWhile($preLedger, function (Connection $conn): void {
            (new MysqlConversionRepository($conn))->recordInTransaction(
                self::USER,
                ['click_id' => self::CLICK, 'payout' => '4', 'transaction_id' => 'TX-2']
            );
        });

        self::assertSame('ok', $outcome);
        $baselines = self::$db->query("SELECT COUNT(*) FROM 202_conversion_logs WHERE source = 'legacy_baseline'")
            ->fetch_row()[0];
        self::assertSame(1, (int) $baselines);
        $deleted = self::$db->query('SELECT deleted FROM 202_conversion_logs WHERE conv_id = ' . $preLedger)
            ->fetch_row()[0];
        self::assertSame(1, (int) $deleted);
    }

    /**
     * A goal row the engine revived while an operator's delete of it waited:
     * the revival posted its revenue again, so the delete voids that posting
     * under the next generation's key. Read before the revival committed,
     * the delete reused the first void's key, its void was dropped as a
     * repeat, and the customer kept $10 of a deleted conversion.
     */
    public function testADeleteOfARowRevivedInItsWaitVoidsTheRevivedRevenue(): void
    {
        self::click();
        $customer = ['customer_ref' => 'cust-701', 'customer_ref_type' => 'custom'];
        $goal = $this->repo->record(self::USER, [
            'click_id' => self::CLICK, 'source' => 'goal', 'payout' => '10', 'transaction_id' => 'G-1',
        ] + $customer);
        $goalId = (int) $goal['convId'];
        $conn = new Connection(self::$db);
        $conn->transaction(function () use ($conn, $goalId): void {
            (new MysqlConversionLedger($conn))->lockClick(self::CLICK);
            (new MysqlConversionRepository($conn))
                ->retireGoalRowInTransaction($goalId, self::USER, SupersededReason::REEVALUATION);
        });
        self::assertSame('0.00', self::customerTotal());

        $outcome = self::deleteWhile($goalId, function (Connection $conn) use ($goalId, $goal): void {
            (new MysqlConversionRepository($conn))->reviveGoalRowInTransaction(
                $goalId,
                self::USER,
                self::CLICK,
                (string) $goal['dedupeKey']
            );
        });

        self::assertSame('ok', $outcome);
        self::assertSame(['void:conv:' . $goalId, 'void:conv:' . $goalId . ':1'], self::voidKeys());
        self::assertSame('0.00', self::customerTotal());
    }
}
