<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for _upgrade_query_run() — the resilient query core used by the
 * live-upgrade path. Exercises the retry/backoff and transient-vs-permanent
 * classification with a fake connection, no real DB, no real sleeps, and no _die().
 *
 * @covers ::_upgrade_query_run
 */
final class UpgradeQueryTest extends TestCase
{
    /** @var list<int> backoff seconds recorded from the injected sleeper */
    private array $sleeps = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../202-config/functions-upgrade.php';
    }

    protected function setUp(): void
    {
        $this->sleeps = [];
    }

    /** Sleeper that records the backoff instead of sleeping. */
    private function sleeper(): callable
    {
        return function (int $seconds): void {
            $this->sleeps[] = $seconds;
        };
    }

    /** onExhausted that throws so the abort is assertable (production _die()s here). */
    private function aborter(): callable
    {
        return function (int $errno, string $error): void {
            throw new RuntimeException('aborted:' . $errno);
        };
    }

    public function testSucceedsOnFirstTryWithoutSleeping(): void
    {
        $conn = new FakeUpgradeConnection([true]);

        $result = _upgrade_query_run($conn, 'SELECT 1', $this->sleeper(), $this->aborter());

        $this->assertTrue($result);
        $this->assertSame(1, $conn->queryCount);
        $this->assertSame([], $this->sleeps);
    }

    public function testReturnsTheUnderlyingResultObject(): void
    {
        $sentinel = (object) ['rows' => 3];
        $conn = new FakeUpgradeConnection([$sentinel]);

        $result = _upgrade_query_run($conn, 'SELECT 1', $this->sleeper(), $this->aborter());

        $this->assertSame($sentinel, $result);
    }

    public function testRetriesLockWaitThenSucceeds(): void
    {
        $conn = new FakeUpgradeConnection([1205, true]);

        $result = _upgrade_query_run($conn, 'ALTER TABLE x', $this->sleeper(), $this->aborter());

        $this->assertTrue($result);
        $this->assertSame(2, $conn->queryCount);
        $this->assertSame([1], $this->sleeps);
    }

    public function testRetriesDeadlockThenSucceeds(): void
    {
        $conn = new FakeUpgradeConnection([1213, true]);

        $result = _upgrade_query_run($conn, 'ALTER TABLE x', $this->sleeper(), $this->aborter());

        $this->assertTrue($result);
        $this->assertSame(2, $conn->queryCount);
        $this->assertSame([1], $this->sleeps);
    }

    public function testPermanentErrorReturnsFalseWithoutRetry(): void
    {
        // 1064 = syntax error: never retried, falls through to legacy false contract.
        $conn = new FakeUpgradeConnection([1064]);

        $result = _upgrade_query_run($conn, 'ALTER TABLE x', $this->sleeper(), $this->aborter());

        $this->assertFalse($result);
        $this->assertSame(1, $conn->queryCount);
        $this->assertSame([], $this->sleeps);
    }

    public function testExhaustedTransientAbortsAfterExponentialBackoff(): void
    {
        $conn = new FakeUpgradeConnection([1205, 1205, 1205, 1205]);

        try {
            _upgrade_query_run($conn, 'ALTER TABLE x', $this->sleeper(), $this->aborter());
            $this->fail('Expected the exhausted-transient path to invoke onExhausted (which throws here).');
        } catch (RuntimeException $e) {
            $this->assertSame('aborted:1205', $e->getMessage());
        }

        // 4 attempts (default), 3 backoffs in the documented 1s/2s/4s sequence.
        $this->assertSame(4, $conn->queryCount);
        $this->assertSame([1, 2, 4], $this->sleeps);
    }

    public function testHonorsCustomMaxAttempts(): void
    {
        $conn = new FakeUpgradeConnection([1205, 1205, 1205, 1205]);
        $exhaustedErrno = null;

        $onExhausted = function (int $errno, string $error) use (&$exhaustedErrno) {
            $exhaustedErrno = $errno;
            return false; // mimic a non-throwing handler; core returns this value
        };

        $result = _upgrade_query_run($conn, 'ALTER TABLE x', $this->sleeper(), $onExhausted, 2);

        $this->assertFalse($result);
        $this->assertSame(1205, $exhaustedErrno);
        $this->assertSame(2, $conn->queryCount); // 1 attempt + 1 retry
        $this->assertSame([1], $this->sleeps);
    }

    public function testTransientMysqliExceptionIsRetried(): void
    {
        // MYSQLI_REPORT_ERROR environments throw instead of returning false.
        $conn = new FakeUpgradeConnection([
            static fn () => throw new \mysqli_sql_exception('lock wait timeout', 1205),
            true,
        ]);

        $result = _upgrade_query_run($conn, 'ALTER TABLE x', $this->sleeper(), $this->aborter());

        $this->assertTrue($result);
        $this->assertSame(2, $conn->queryCount);
        $this->assertSame([1], $this->sleeps);
    }

    public function testPermanentMysqliExceptionReturnsFalse(): void
    {
        $conn = new FakeUpgradeConnection([
            static fn () => throw new \mysqli_sql_exception('syntax error', 1064),
        ]);

        $result = _upgrade_query_run($conn, 'ALTER TABLE x', $this->sleeper(), $this->aborter());

        $this->assertFalse($result);
        $this->assertSame(1, $conn->queryCount);
        $this->assertSame([], $this->sleeps);
    }
}

/**
 * Minimal stand-in for a mysqli connection: returns a queued sequence of outcomes.
 * Each outcome is one of:
 *   - true / object : success (returned as-is)
 *   - int           : failure with that errno (sets ->errno/->error, returns false)
 *   - callable      : invoked (may throw \mysqli_sql_exception)
 */
final class FakeUpgradeConnection
{
    public int $errno = 0;
    public string $error = '';
    public int $queryCount = 0;

    /** @var list<mixed> */
    private array $outcomes;

    public function __construct(array $outcomes)
    {
        $this->outcomes = $outcomes;
    }

    public function query(string $sql)
    {
        $outcome = $this->outcomes[$this->queryCount] ?? 0;
        $this->queryCount++;

        if ($outcome instanceof \Closure) {
            return $outcome();
        }
        if (is_int($outcome)) {
            $this->errno = $outcome;
            $this->error = 'errno ' . $outcome;
            return false;
        }

        $this->errno = 0;
        $this->error = '';
        return $outcome;
    }
}
