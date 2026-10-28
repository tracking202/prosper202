<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Ltv\LtvMaintenance;
use Prosper202\Ltv\MysqlLtvRepository;

/**
 * The LTV maintenance on a real server: the subscription sweep (its states,
 * the time a cancel is dated, the customers' rollups it refreshes, every
 * account's rows each tied to its own), its batches and deadline, the
 * reconcile's window and the rows it corrects, the lock, a job's failure
 * leaving the others to run, the company linking and the token purge.
 *
 * The maintenance reads every account's rows, so its tables are emptied for
 * each test.
 *
 * @group integration
 */
final class LtvMaintenanceIntegrationTest extends TestCase
{
    private const USER = 990901;
    private const OTHER = 990902;
    private const DAY = 86400;

    private const TABLES = [
        '202_customers', '202_subscriptions', '202_revenue_events', '202_personalization_tokens',
        '202_offer_transitions', '202_offer_recommendations', '202_companies', '202_conversion_logs',
        '202_ltv_webhooks', '202_ltv_webhook_deliveries',
    ];

    private static ?\mysqli $db = null;

    private string $log = '';
    private string $errorLogBefore = '';

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
        $db->query("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,"
            . "NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            foreach (self::TABLES as $table) {
                self::$db->query('TRUNCATE TABLE ' . $table);
            }
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        foreach (self::TABLES as $table) {
            self::q('TRUNCATE TABLE ' . $table);
        }
        $log = tempnam(sys_get_temp_dir(), 'p202-ltv-maintenance-log');
        self::assertIsString($log);
        $this->log = $log;
        $this->errorLogBefore = (string) ini_get('error_log');
        ini_set('error_log', $log);
    }

    protected function tearDown(): void
    {
        if ($this->log !== '') {
            ini_set('error_log', $this->errorLogBefore);
            if (is_file($this->log)) {
                unlink($this->log);
            }
        }
    }

    private static function conn(): Connection
    {
        self::assertNotNull(self::$db);

        return new Connection(self::$db);
    }

    private static function q(string $sql): \mysqli_result|bool
    {
        self::assertNotNull(self::$db);
        $result = self::$db->query($sql);
        self::assertNotFalse($result, $sql);

        return $result;
    }

    /** @return array<string, string|null> */
    private static function row(string $sql): array
    {
        $result = self::q($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        self::assertIsArray($row, $sql);

        return $row;
    }

    /**
     * @param list<string>|null $jobs
     * @return array<string, mixed>
     */
    private static function maintain(
        ?array $jobs = null,
        bool $full = false,
        int $batch = LtvMaintenance::BATCH,
        ?callable $clock = null
    ): array {
        $report = LtvMaintenance::runExclusive(
            self::conn(),
            time() + 60,
            $jobs ?? LtvMaintenance::JOBS,
            $full,
            $batch,
            $clock
        );
        self::assertNotNull($report, 'no other run holds the lock');

        return $report;
    }

    private static function customer(
        int $user = self::USER,
        float $mrr = 0.0,
        int $activeSubs = 0,
        ?int $lastActivity = null
    ): int {
        $last = $lastActivity ?? time() - 30 * self::DAY;
        self::q('INSERT INTO 202_customers SET user_id = ' . $user . ", primary_ref = 'ref-" . uniqid('', true) . "',"
            . ' first_seen_time = ' . ($last - self::DAY) . ', last_activity_time = ' . $last . ', mrr = ' . $mrr
            . ', active_subscription_count = ' . $activeSubs . ', created_at = 1, updated_at = 1');

        return (int) self::$db?->insert_id;
    }

    private static function subscription(
        int $customerId,
        string $status,
        int $periodEnd,
        float $mrr = 10.0,
        int $user = self::USER,
        int $graceDays = 3,
        ?int $canceledAt = null
    ): int {
        self::q('INSERT INTO 202_subscriptions SET user_id = ' . $user . ', customer_id = ' . $customerId
            . ", external_sub_id = 'sub-" . uniqid('', true) . "', amount = " . $mrr . ", status = '" . $status . "'"
            . ', mrr = ' . $mrr . ', started_at = ' . ($periodEnd - 60 * self::DAY)
            . ', current_period_start = ' . ($periodEnd - 30 * self::DAY) . ', current_period_end = ' . $periodEnd
            . ', grace_days = ' . $graceDays . ', canceled_at = ' . ($canceledAt ?? 'NULL')
            . ', created_at = 1, updated_at = 1');

        return (int) self::$db?->insert_id;
    }

    /** @return array<string, string|null> */
    private static function sub(int $id): array
    {
        return self::row('SELECT status, canceled_at, updated_at FROM 202_subscriptions'
            . ' WHERE subscription_id = ' . $id);
    }

    /** @return array<string, string|null> */
    private static function rollups(int $customerId): array
    {
        return self::row('SELECT mrr, active_subscription_count, order_count, total_revenue, refunded_amount,'
            . ' updated_at FROM 202_customers WHERE customer_id = ' . $customerId);
    }

    public function testALapsedSubscriptionIsPastDueThenCanceledDatedByTheRule(): void
    {
        $now = time();
        $c = self::customer(self::USER, 117.0, 4);
        $pastDue = self::subscription($c, 'active', $now - 5 * self::DAY, 10.0);
        $inGrace = self::subscription($c, 'active', $now - self::DAY, 20.0);
        $longGone = self::subscription($c, 'active', $now - 100 * self::DAY, 30.0);
        $renewed = self::subscription($c, 'active', $now + 20 * self::DAY, 57.0);
        $overdue = self::subscription($c, 'past_due', $now - 40 * self::DAY, 40.0);
        $stillDue = self::subscription($c, 'past_due', $now - 10 * self::DAY, 50.0);
        $trial = self::subscription($c, 'trialing', $now - 300 * self::DAY, 0.0);
        $paused = self::subscription($c, 'paused', $now - 300 * self::DAY, 60.0);
        $canceled = self::subscription($c, 'canceled', $now - 300 * self::DAY, 70.0, self::USER, 3, 12345);
        $longGrace = self::subscription($c, 'active', $now - 10 * self::DAY, 0.0, self::USER, 14);

        $report = self::maintain([LtvMaintenance::SWEEP]);

        self::assertSame(1, $report['past_due'], LtvMaintenance::summary($report));
        self::assertSame(2, $report['canceled']);
        self::assertSame(1, $report['swept_customers']);
        self::assertSame([], $report['more']);
        self::assertSame([], $report['failed']);
        self::assertSame(['past_due', null], [self::sub($pastDue)['status'], self::sub($pastDue)['canceled_at']]);
        self::assertSame('active', self::sub($inGrace)['status']);
        self::assertSame('active', self::sub($renewed)['status']);
        self::assertSame('active', self::sub($longGrace)['status'], 'its own grace, not the default');
        self::assertSame('past_due', self::sub($stillDue)['status']);
        // Canceled when the rule says: the period end, the grace and 30 days.
        $cancelAt = static fn (int $end): string => (string) ($end + 33 * self::DAY);
        $statusAndCancel = static fn (int $id): array => array_values(array_slice(self::sub($id), 0, 2));
        self::assertSame(['canceled', $cancelAt($now - 100 * self::DAY)], $statusAndCancel($longGone));
        self::assertSame(['canceled', $cancelAt($now - 40 * self::DAY)], $statusAndCancel($overdue));
        self::assertSame('trialing', self::sub($trial)['status']);
        self::assertSame('paused', self::sub($paused)['status']);
        self::assertSame(
            ['canceled', '12345', '1'],
            array_values(self::sub($canceled)),
            'a canceled one is left alone'
        );

        $rollups = self::rollups($c);
        self::assertSame('77.00000', $rollups['mrr'], 'the in-grace, renewed and long-grace subscriptions');
        self::assertSame('3', $rollups['active_subscription_count']);
        self::assertGreaterThanOrEqual($now, (int) $rollups['updated_at']);

        $again = self::maintain([LtvMaintenance::SWEEP]);
        self::assertSame([0, 0, 0], [$again['past_due'], $again['canceled'], $again['swept_customers']]);
        self::assertFalse(LtvMaintenance::didWork($again));
    }

    public function testChurnCountsALapseByWhenItLapsedNotWhenTheSweepRan(): void
    {
        $now = time();
        $c = self::customer();
        self::subscription($c, 'active', $now + 10 * self::DAY, 10.0);
        // Lapsed past canceling a year ago: never in this quarter's churn.
        self::subscription($c, 'active', $now - 400 * self::DAY, 10.0);
        // Canceled 17 days ago by the rule: in it.
        self::subscription($c, 'active', $now - 50 * self::DAY, 10.0);

        self::maintain([LtvMaintenance::SWEEP]);

        $mrr = (new MysqlLtvRepository(self::conn()))->mrr(self::USER);
        self::assertSame(1, $mrr['active_subscriptions']);
        self::assertSame(2, $mrr['canceled_total']);
        self::assertSame(1, $mrr['churn_inputs']['canceled_in_window'], 'only the one that lapsed in the window');
        self::assertSame(10.0, (float) $mrr['mrr']);
    }

    public function testEveryAccountIsSweptAndARollupCountsOnlyItsAccountsSubscriptions(): void
    {
        $now = time();
        $mine = self::customer(self::USER, 10.0, 1);
        $theirs = self::customer(self::OTHER, 25.0, 1);
        $a = self::subscription($mine, 'active', $now - 5 * self::DAY, 10.0);
        $b = self::subscription($theirs, 'active', $now - 5 * self::DAY, 25.0, self::OTHER);
        // Another account's row naming this customer (no write makes one):
        // read as naming nothing.
        self::subscription($mine, 'active', $now + 10 * self::DAY, 50.0, self::OTHER);

        $report = self::maintain([LtvMaintenance::SWEEP]);

        self::assertSame([2, 0, 2], [$report['past_due'], $report['canceled'], $report['swept_customers']]);
        self::assertSame('past_due', self::sub($a)['status']);
        self::assertSame('past_due', self::sub($b)['status']);
        self::assertSame(['0.00000', '0'], array_values(array_slice(self::rollups($mine), 0, 2)));
        self::assertSame(['0.00000', '0'], array_values(array_slice(self::rollups($theirs), 0, 2)));
    }

    /**
     * A renewal that commits while the sweep waits on the row it read as
     * lapsed: the sweep's write asks the row again and leaves it active.
     * Run in a child process, held at its write by this test's lock on the
     * row until it is seen waiting.
     */
    public function testARenewalBetweenTheSweepsReadAndItsWriteWins(): void
    {
        $now = time();
        $c = self::customer(self::USER, 10.0, 1);
        $s = self::subscription($c, 'active', $now - 5 * self::DAY, 10.0);
        (new \Prosper202\Ltv\MysqlWebhookRepository(self::conn()))
            ->create(self::USER, 'https://hooks.example/all', ['*']);
        $renewal = self::connect();
        $renewalConn = new Connection($renewal);
        $proc = null;
        $pipes = [];
        $waiting = $renewalConn->transaction(function () use ($renewalConn, $s, $now, &$proc, &$pipes): bool {
            $lock = $renewalConn->prepareWrite(
                'SELECT status FROM 202_subscriptions WHERE subscription_id = ? FOR UPDATE'
            );
            $renewalConn->bind($lock, 'i', [$s]);
            $renewalConn->fetchOne($lock);
            $proc = proc_open(
                [
                    PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/maintenance-runner.php',
                    LtvMaintenance::SWEEP,
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $waiting = false;
            for ($i = 0; $i < 100 && !$waiting && is_resource($proc); $i++) {
                usleep(100000);
                $row = self::row(
                    "SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT'"
                );
                $waiting = $row['n'] === '1';
            }
            $renew = $renewalConn->prepareWrite(
                'UPDATE 202_subscriptions SET current_period_end = ? WHERE subscription_id = ?'
            );
            $renewalConn->bind($renew, 'ii', [$now + 25 * self::DAY, $s]);
            $renewalConn->executeUpdate($renew);

            return $waiting;
        });
        $renewal->close();
        self::assertIsResource($proc);
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), $out . ' ' . $err);
        self::assertTrue($waiting, 'the sweep waited on the renewal\'s lock: ' . $out);

        $report = json_decode($out, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame([0, 0, 0], [$report['past_due'], $report['canceled'], $report['announced']], $out);
        self::assertSame('active', self::sub($s)['status']);
        self::assertSame('10.00000', self::rollups($c)['mrr']);
        $queued = self::row('SELECT COUNT(*) AS n FROM 202_ltv_webhook_deliveries')['n'];
        self::assertSame('0', $queued, 'nothing announced');
    }

    public function testABacklogIsSweptInBatchesAndADeadlineLeavesTheRestForTheNextRun(): void
    {
        $now = time();
        $c = self::customer(self::USER, 50.0, 5);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = self::subscription($c, 'active', $now - (10 + $i) * self::DAY, 10.0);
        }
        $late = static fn (): int => PHP_INT_MAX;

        $first = self::maintain(null, false, 2, $late);
        self::assertSame(2, $first['past_due'], LtvMaintenance::summary($first));
        self::assertSame(LtvMaintenance::JOBS, $first['more'], 'one batch, then every job is left for the next run');
        // The oldest period ends first.
        self::assertSame('past_due', self::sub($ids[4])['status']);
        self::assertSame('past_due', self::sub($ids[3])['status']);
        self::assertSame('active', self::sub($ids[2])['status']);
        self::assertSame('30.00000', self::rollups($c)['mrr'], 'the batch\'s customer refreshed with it');

        $second = self::maintain([LtvMaintenance::SWEEP], false, 2);
        self::assertSame(3, $second['past_due'], 'the rest, in two batches');
        self::assertSame([], $second['more']);
        self::assertSame('0.00000', self::rollups($c)['mrr']);
    }

    public function testTheReconcileCorrectsTheCustomersActiveInItsWindow(): void
    {
        $now = time();
        $recent = self::customer(self::USER, 0.0, 0, $now - 3600);
        $quiet = self::customer(self::USER, 0.0, 0, $now - 10 * self::DAY);
        foreach ([$recent, $quiet] as $c) {
            self::q('INSERT INTO 202_revenue_events SET user_id = ' . self::USER . ', customer_id = ' . $c
                . ", event_type = 'purchase', amount = 30, occurred_at = 1, source = 'api', created_at = 1");
            self::q('INSERT INTO 202_revenue_events SET user_id = ' . self::USER . ', customer_id = ' . $c
                . ", event_type = 'refund', amount = -5, occurred_at = 2, source = 'api', created_at = 2");
            self::subscription($c, 'active', $now + 10 * self::DAY, 12.0);
        }
        // Another account's event naming this customer counts for nothing.
        self::q('INSERT INTO 202_revenue_events SET user_id = ' . self::OTHER . ', customer_id = ' . $recent
            . ", event_type = 'purchase', amount = 1000, occurred_at = 3, source = 'api', created_at = 3");

        $report = self::maintain([LtvMaintenance::RECONCILE]);
        self::assertSame([1, 1], [$report['checked'], $report['reconciled']], LtvMaintenance::summary($report));
        self::assertSame(
            ['12.00000', '1', '1', '25.00000', '5.00000'],
            array_values(array_slice(self::rollups($recent), 0, 5))
        );
        self::assertSame(
            ['0.00000', '0', '0', '0.00000', '0.00000'],
            array_values(array_slice(self::rollups($quiet), 0, 5))
        );

        self::q('UPDATE 202_customers SET updated_at = 1 WHERE customer_id = ' . $recent);
        $again = self::maintain([LtvMaintenance::RECONCILE]);
        self::assertSame([1, 0], [$again['checked'], $again['reconciled']]);
        self::assertSame('1', self::rollups($recent)['updated_at'], 'a row already right is not rewritten');

        $full = self::maintain([LtvMaintenance::RECONCILE], true);
        self::assertSame([2, 1], [$full['checked'], $full['reconciled']]);
        self::assertSame('25.00000', self::rollups($quiet)['total_revenue']);
        self::assertStringContainsString('every customer', LtvMaintenance::summary($full));
    }

    public function testTheLockKeepsASecondRunOut(): void
    {
        $c = self::customer();
        $s = self::subscription($c, 'active', time() - 10 * self::DAY);
        $other = self::connect();
        $name = LtvMaintenance::lockName(self::conn());
        $held = $other->query("SELECT GET_LOCK('" . $other->real_escape_string($name) . "', 0)");
        self::assertInstanceOf(\mysqli_result::class, $held);
        self::assertSame('1', (string) $held->fetch_row()[0]);
        try {
            self::assertNull(LtvMaintenance::runExclusive(self::conn(), time() + 60));
        } finally {
            // Released, not left to the disconnect, which the server may not
            // have finished when the next run asks.
            $other->query("SELECT RELEASE_LOCK('" . $other->real_escape_string($name) . "')");
            $other->close();
        }
        self::assertSame('active', self::sub($s)['status']);

        self::assertSame(1, self::maintain()['past_due'], 'the lock goes with the session that held it');
    }

    public function testAJobThatFailsIsReportedAndTheJobsAfterItRun(): void
    {
        $c = self::customer();
        self::q('INSERT INTO 202_revenue_events SET user_id = ' . self::USER . ', customer_id = ' . $c
            . ", event_type = 'purchase', amount = 30, occurred_at = 1, source = 'import', created_at = 1");
        self::q('INSERT INTO 202_personalization_tokens SET token_hash = UNHEX(SHA2(\'t1\', 256)), user_id = '
            . self::USER . ', customer_id = ' . $c . ', created_at = 1, first_use_deadline = 2, replay_until = 3');
        self::q('RENAME TABLE 202_offer_transitions TO 202_offer_transitions_gone');
        try {
            $report = self::maintain();
        } finally {
            self::q('RENAME TABLE 202_offer_transitions_gone TO 202_offer_transitions');
        }
        self::assertArrayHasKey(LtvMaintenance::TRANSITIONS, $report['failed'], LtvMaintenance::summary($report));
        self::assertCount(1, $report['failed']);
        self::assertSame(1, $report['tokens_purged'], 'the purge after it ran');
        self::assertStringContainsString('FAILED: transitions', LtvMaintenance::summary($report));
        self::assertStringContainsString('ltv_maintenance transitions failed', (string) file_get_contents($this->log));
    }

    public function testCompaniesAreLinkedAndExpiredTokensPurged(): void
    {
        self::q('INSERT IGNORE INTO 202_users_pref SET user_id = ' . self::USER);
        $c = self::customer();
        self::q("UPDATE 202_customers SET company = 'Acme  Corp' WHERE customer_id = " . $c);
        $now = time();
        self::q('INSERT INTO 202_personalization_tokens SET token_hash = UNHEX(SHA2(\'old\', 256)), user_id = '
            . self::USER . ', customer_id = ' . $c . ', created_at = 1, first_use_deadline = 2, replay_until = 3');
        self::q('INSERT INTO 202_personalization_tokens SET token_hash = UNHEX(SHA2(\'live\', 256)), user_id = '
            . self::USER . ', customer_id = ' . $c . ', created_at = 1, first_use_deadline = 2, replay_until = '
            . ($now + 3600));

        $report = self::maintain(LtvMaintenance::DAILY_JOBS);

        self::assertSame(1, $report['companies_linked'], LtvMaintenance::summary($report));
        self::assertNotNull(self::row('SELECT company_id FROM 202_customers WHERE customer_id = ' . $c)['company_id']);
        self::assertSame(1, $report['tokens_purged']);
        self::assertSame('1', self::row('SELECT COUNT(*) AS n FROM 202_personalization_tokens')['n']);
        self::assertSame(LtvMaintenance::DAILY_JOBS, $report['jobs']);
    }

    public function testAJobNotOnTheListIsRefusedBeforeAnythingRuns(): void
    {
        $s = self::subscription(self::customer(), 'active', time() - 10 * self::DAY);
        try {
            (new LtvMaintenance(self::conn()))->run(time() + 60, [LtvMaintenance::SWEEP, 'sweap']);
            self::fail('an unknown job was run');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('sweap', $e->getMessage());
        }
        self::assertSame('active', self::sub($s)['status']);
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
}
