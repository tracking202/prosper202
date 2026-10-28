<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\AttributionWorker;
use Prosper202\Attribution\WorkerReport;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * An identity merge that fails no longer stops the merges after it, or the
 * rest of the worker's run (AttributionWorker::requeueMerges()).
 *
 * The merges were taken in merge_id order and each one's transaction threw
 * out of the run: a merge that failed on every run was the head of every
 * run, and neither the merges after it, nor the outbox, nor the rollup ran
 * again. Each failing merge here sits ahead of one that must be re-queued.
 * Only a failure the merge's own data determines (a person with more visitor
 * keys than one statement can name) ends its retries; a failure of the
 * server's (a trigger's refusal, a lock wait) is retried, later each time,
 * and never ends them.
 *
 * @group integration
 */
final class MergeRequeueFailuresIntegrationTest extends TestCase
{
    use AttributionDatabase;

    /** The worker's clock: early enough that the rollup finds no hour sealed. */
    private const T = 1_000_000;
    private const WIDE_KEY = 1_000_000;
    private const REFUSE_FIRST_MERGE = "CREATE TRIGGER p202_test_refuse_merge BEFORE UPDATE ON 202_identity_merges
        FOR EACH ROW IF NEW.merge_id = %d AND NEW.requeued_at IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused for the test';
        END IF";

    public function testAMergeItsOwnDataRefusesIsRetiredAndTheMergesAfterItAreRequeued(): void
    {
        $this->campaign(1);
        [, $right1] = $this->twoVisitors(100);
        [, $right2] = $this->twoVisitors(200);
        $this->workAt(self::T);
        $wide = $this->wideMerge(65534);
        $first = $this->join(100);
        $second = $this->join(200);
        self::assertLessThan($first, $wide, 'the merge that cannot be re-queued is taken first');
        $late = $this->convert(102, '3.00', 'late');

        $report = $this->workAt(self::T);

        self::assertMerges([2, 0, 1], $report);
        self::assertSame(self::T, $this->merge($first)['requeued_at']);
        self::assertSame(self::T, $this->merge($second)['requeued_at']);
        self::assertSame([100, 101], self::journeyClicks($right1), 'the merge after it joined the two visitors');
        self::assertSame([200, 201], self::journeyClicks($right2));
        self::assertSame([100, 101, 102], self::journeyClicks($late), 'the outbox ran in the same run');

        $row = $this->merge($wide);
        self::assertNull($row['requeued_at'], 'nothing of the wide person was re-queued');
        self::assertSame(self::T, $row['requeue_retired_at']);
        self::assertSame(0, $row['requeue_failures'], 'retired, not counted as a failure');
        self::assertStringContainsString('has 65534 visitor keys', (string) $row['requeue_error']);

        self::assertMerges([0, 0, 0], $this->workAt(self::T + 86400), 'a retired merge is not taken again');

        $queue = (new AttributionReports($this->conn))->queue(1);
        self::assertSame(
            [0, 0, 1],
            [$queue['merges_awaiting_requeue'], $queue['merges_failing'], $queue['merges_retired']]
        );
        self::assertSame($wide, $queue['merge_rows'][0]['merge_id']);
        self::assertSame(self::T, $queue['merge_rows'][0]['retired_at']);
        self::assertSame($row['requeue_error'], $queue['merge_rows'][0]['last_error']);
    }

    public function testAPersonAtTheLimitIsRequeued(): void
    {
        // As many keys as the statement can name (65,535 values, two of them
        // the account): the limit is the server's, not one below it.
        $this->campaign(1);
        $wide = $this->wideMerge(65533);

        self::assertMerges([1, 0, 0], $this->workAt(self::T));
        self::assertSame(self::T, $this->merge($wide)['requeued_at']);
    }

    public function testAServerFailureIsRetriedLaterEachTimeAndNeverEndsTheMerge(): void
    {
        $this->campaign(1);
        [$left1, $right1] = $this->twoVisitors(100);
        [, $right2] = $this->twoVisitors(200);
        $this->workAt(self::T);
        $first = $this->join(100);
        $second = $this->join(200);

        // The server refuses the first merge's own write, after its conversions were queued.
        self::fixture(sprintf(self::REFUSE_FIRST_MERGE, $first));
        $minute = AttributionWorker::MERGE_FIRST_BACKOFF;
        $hour = AttributionWorker::MERGE_MAX_BACKOFF;
        try {
            self::assertMerges([1, 1, 0], $this->workAt(self::T));
            self::assertSame(self::T, $this->merge($second)['requeued_at'], 'the merge after it was re-queued');
            self::assertSame([200, 201], self::journeyClicks($right2));
            $row = $this->merge($first);
            self::assertSame(
                [null, 1, self::T, null],
                [$row['requeued_at'], $row['requeue_failures'], $row['requeue_retry_at'], $row['requeue_retired_at']],
                'tried again on the next run'
            );
            self::assertStringContainsString('refused for the test', (string) $row['requeue_error']);
            self::assertSame([101], self::journeyClicks($right1), 'its transaction was rolled back whole');
            self::assertSame(0, (int) self::scalar(
                "SELECT COUNT(*) FROM 202_attribution_pending WHERE conv_id IN ($left1, $right1)"
            ));

            $this->workAt(self::T);
            $row = $this->merge($first);
            self::assertSame([2, self::T + $minute], [$row['requeue_failures'], $row['requeue_retry_at']]);

            self::assertMerges([0, 0, 0], $this->workAt(self::T + $minute - 1), 'not taken before its turn');

            self::$db->query("UPDATE 202_identity_merges SET requeue_failures = 400 WHERE merge_id = $first");
            $this->workAt(self::T + $minute);
            $row = $this->merge($first);
            self::assertSame(
                [401, self::T + $minute + $hour, null],
                [$row['requeue_failures'], $row['requeue_retry_at'], $row['requeue_retired_at']],
                'any number of server failures waits at most an hour and never retires the merge'
            );

            $queue = (new AttributionReports($this->conn))->queue(1);
            self::assertSame(
                [1, 1, 0],
                [$queue['merges_awaiting_requeue'], $queue['merges_failing'], $queue['merges_retired']]
            );
            $listed = $queue['merge_rows'][0];
            self::assertSame([$first, 401, null], [$listed['merge_id'], $listed['failures'], $listed['retired_at']]);
        } finally {
            self::$db->query('DROP TRIGGER IF EXISTS p202_test_refuse_merge');
        }

        self::assertMerges([1, 0, 0], $this->workAt(self::T + $minute + $hour));
        self::assertNotNull($this->merge($first)['requeued_at']);
        self::assertSame([100, 101], self::journeyClicks($right1));
    }

    public function testALockWaitIsTheServersAndTheMergesAfterItStillRun(): void
    {
        $this->campaign(1);
        [$left1, $right1] = $this->twoVisitors(100);
        [, $right2] = $this->twoVisitors(200);
        $this->workAt(self::T);
        $first = $this->join(100);
        $second = $this->join(200);

        // A conversion path holds the first merge's conversion in the outbox, uncommitted.
        $other = $this->connect();
        self::assertTrue($other->begin_transaction());
        self::assertTrue($other->query(
            "INSERT INTO 202_attribution_pending (conv_id, enqueued_at, reason) VALUES ($left1, 1, 'recorded')"
        ));
        self::assertTrue(self::$db->query('SET SESSION innodb_lock_wait_timeout = 1'));
        try {
            $report = $this->workAt(self::T);
        } finally {
            self::$db->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            $other->rollback();
            $other->close();
        }

        self::assertMerges([1, 1, 0], $report);
        self::assertSame(self::T, $this->merge($second)['requeued_at']);
        self::assertSame([200, 201], self::journeyClicks($right2));
        $row = $this->merge($first);
        self::assertSame([null, 1, null], [$row['requeued_at'], $row['requeue_failures'], $row['requeue_retired_at']]);
        self::assertStringContainsString('[errno 1205]', (string) $row['requeue_error']);

        self::assertMerges([1, 0, 0], $this->workAt(self::T), 'the next run takes it again');
        self::assertSame([100, 101], self::journeyClicks($right1));
    }

    public function testMergesNeverTriedComeBeforeOnesWhoseRetryIsDue(): void
    {
        $this->campaign(1);
        $this->twoVisitors(100);
        $this->twoVisitors(200);
        $this->workAt(self::T);
        $first = $this->join(100);
        $second = $this->join(200);
        self::fixture(sprintf(self::REFUSE_FIRST_MERGE, $first));
        try {
            $worker = new AttributionWorker($this->conn, static fn (): int => self::T);
            self::assertSame(['requeued' => 0, 'failed' => 1, 'retired' => 0], $worker->requeueMerges(1));
            self::assertSame(
                ['requeued' => 1, 'failed' => 0, 'retired' => 0],
                $worker->requeueMerges(1),
                'the merge never tried goes first'
            );
            self::assertSame(self::T, $this->merge($second)['requeued_at']);
            self::assertSame(
                ['requeued' => 0, 'failed' => 1, 'retired' => 0],
                $worker->requeueMerges(1),
                'then the one whose turn came'
            );
            self::assertSame(2, $this->merge($first)['requeue_failures']);
        } finally {
            self::$db->query('DROP TRIGGER IF EXISTS p202_test_refuse_merge');
        }
    }

    /** @param array{int, int, int} $expected requeued, failed, retired */
    private static function assertMerges(array $expected, WorkerReport $report, string $why = ''): void
    {
        self::assertSame(
            $expected,
            [$report->mergesRequeued, $report->mergesFailed, $report->mergesRetired],
            trim($why . ' ' . $report->summary())
        );
    }

    private function workAt(int $now): WorkerReport
    {
        return (new AttributionWorker($this->conn, static fn (): int => $now))->run(30, 200);
    }

    /**
     * Two visitors of one person, not yet known to be one, each with a
     * conversion that the next run credits to its own click alone.
     *
     * @return array{int, int} the conversions on $base and $base + 1
     */
    private function twoVisitors(int $base): array
    {
        $now = time();
        $this->click($base, 1, $now - 7200);
        $this->visit($base, $now - 7200, self::cookie('l' . $base));
        $this->click($base + 1, 1, $now - 3600);
        $this->visit($base + 1, $now - 3600, self::cookie('r' . $base));

        return [$this->convert($base, '1.00', 'l' . $base), $this->convert($base + 1, '2.00', 'r' . $base)];
    }

    /** A click carrying both visitors' cookies: the merge, with its merge_id. */
    private function join(int $base): int
    {
        $now = time();
        $this->click($base + 2, 1, $now - 60);
        $this->visit($base + 2, $now - 60, self::cookie('l' . $base), self::cookie('r' . $base));

        return (int) self::scalar('SELECT MAX(merge_id) FROM 202_identity_merges');
    }

    /** A merge into a person of $keys visitor keys, with its merge_id. */
    private function wideMerge(int $keys): int
    {
        $at = time();
        $canonical = self::WIDE_KEY;
        self::fixture("INSERT INTO 202_identity_visitors (visitor_key, user_id, alias_of, created_at)
            VALUES ($canonical, 1, NULL, $at)");
        for ($start = 1; $start < $keys; $start += 5000) {
            $rows = [];
            for ($k = $start; $k < min($start + 5000, $keys); $k++) {
                $rows[] = '(' . ($canonical + $k) . ", 1, $canonical, $at)";
            }
            self::fixture('INSERT INTO 202_identity_visitors (visitor_key, user_id, alias_of, created_at) VALUES '
                . implode(', ', $rows));
        }
        self::assertSame($keys, (int) self::scalar(
            "SELECT COUNT(*) FROM 202_identity_visitors WHERE visitor_key = $canonical OR alias_of = $canonical"
        ));
        self::fixture("INSERT INTO 202_identity_merges
            (user_id, from_key, into_key, click_id, signal_type, signal_hash, merged_at)
            VALUES (1, " . ($canonical + 1) . ", $canonical, 9000, 'vid', '" . str_repeat('e', 64) . "', $at)");

        return (int) self::scalar('SELECT MAX(merge_id) FROM 202_identity_merges');
    }

    /**
     * @return array{requeued_at: ?int, requeue_failures: int, requeue_retry_at: int, requeue_error: ?string,
     *               requeue_retired_at: ?int}
     */
    private function merge(int $mergeId): array
    {
        $row = self::all("SELECT requeued_at, requeue_failures, requeue_retry_at, requeue_error, requeue_retired_at
            FROM 202_identity_merges WHERE merge_id = $mergeId")[0];

        return [
            'requeued_at' => $row['requeued_at'] === null ? null : (int) $row['requeued_at'],
            'requeue_failures' => (int) $row['requeue_failures'],
            'requeue_retry_at' => (int) $row['requeue_retry_at'],
            'requeue_error' => $row['requeue_error'],
            'requeue_retired_at' => $row['requeue_retired_at'] === null ? null : (int) $row['requeue_retired_at'],
        ];
    }

    private function connect(): \mysqli
    {
        $db = mysqli_connect(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertInstanceOf(\mysqli::class, $db);

        return $db;
    }
}
