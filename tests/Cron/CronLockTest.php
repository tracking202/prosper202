<?php

declare(strict_types=1);

namespace Tests\Cron;

use PHPUnit\Framework\TestCase;
use Prosper202\Cron\CronLock;
use Prosper202\Cron\CronLockFailed;

/**
 * One run of 202-cronjobs/index.php at a time, for as long as it runs.
 *
 * The lock was a file's presence: file_exists() then touch(), so two runs
 * could both find it absent, and a file older than 600 s was deleted as
 * stale, so a run longer than ten minutes had a second one started beside it
 * (measured: both ran the minute and hourly tiers). A run that died left the
 * file, and every run for ten minutes after it skipped. It is an flock() now
 * (CronLock), which has no age and goes with the process; each case below
 * runs the lock in child processes, as the cron's runs are.
 *
 * "Longer than ten minutes" is the lock file's modification time set eleven
 * minutes back while a run holds it: the old lock's only clock. The flock
 * reads no clock at all, so no wait stands in for the ten minutes.
 *
 * tests/live/cron-lock.sh runs index.php itself the same ways.
 */
final class CronLockTest extends TestCase
{
    private const RUNNER = __DIR__ . '/fixtures/cron-lock-runner.php';

    private string $dir = '';

    /** @var list<array{0: resource, 1: array<int, resource>}> */
    private array $children = [];

    private ?string $errorLog = null;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/p202-cron-lock-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0700));
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        if ($this->errorLog !== null) {
            ini_set('error_log', $this->errorLog);
        }
        foreach ($this->children as [$proc, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($proc, 9);
            proc_close($proc);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->dir);
    }

    private function lockPath(): string
    {
        return $this->dir . '/' . CronLock::FILE;
    }

    /**
     * A runner started now; its stdin stays open, which keeps a holding one holding.
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function start(string $then = 'hold', ?string $go = null): array
    {
        $cmd = [PHP_BINARY, self::RUNNER, $this->lockPath(), $then];
        if ($go !== null) {
            $cmd[] = $go;
        }
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $child = [$proc, $pipes];
        $this->children[] = $child;

        return $child;
    }

    /**
     * The runner's next line, within ten seconds: a run that waits for the
     * lock instead of being turned away says nothing, and fails here.
     *
     * @param array{0: resource, 1: array<int, resource>} $child
     */
    private static function said(array $child): string
    {
        $read = [$child[1][1]];
        $none = [];
        $ready = stream_select($read, $none, $none, 10);
        self::assertSame(1, $ready, 'the runner answers at once, without waiting for the lock');
        $line = fgets($child[1][1]);

        return $line === false ? 'nothing (' . stream_get_contents($child[1][2]) . ')' : rtrim($line);
    }

    /** @param array{0: resource, 1: array<int, resource>} $child */
    private static function stop(array $child): void
    {
        fclose($child[1][0]);
        $deadline = microtime(true) + 10;
        while (proc_get_status($child[0])['running']) {
            self::assertLessThan($deadline, microtime(true), 'the runner ends once its stdin closes');
            usleep(10000);
        }
    }

    public function testOfRunsStartedTogetherExactlyOneRuns(): void
    {
        $go = $this->dir . '/go';
        $runners = [];
        for ($i = 0; $i < 4; $i++) {
            $runners[] = $this->start('hold', $go);
        }
        usleep(300000);
        self::assertTrue(touch($go));
        $said = array_map(self::said(...), $runners);
        sort($said);
        self::assertSame(['busy', 'busy', 'busy', 'ran'], $said, 'four runs taking the lock in one instant');
    }

    public function testARunLongerThanTenMinutesStillKeepsTheNextOut(): void
    {
        $first = $this->start();
        self::assertSame('ran', self::said($first));
        // The old lock deleted a file this old as stale, and the next run ran beside the first.
        self::assertTrue(touch($this->lockPath(), time() - 660, time() - 660));
        clearstatcache();
        self::assertLessThan(time() - 600, (int) filemtime($this->lockPath()));

        self::assertSame('busy', self::said($this->start()), 'a run started eleven minutes into the first');
        self::assertTrue(CronLock::isHeld($this->lockPath()));

        self::stop($first);
        self::assertSame('ran', self::said($this->start()), 'the next run, once the long one is done');
    }

    public function testAKilledRunsLockIsFreeAtOnce(): void
    {
        $first = $this->start();
        self::assertSame('ran', self::said($first));
        self::assertTrue(proc_terminate($first[0], 9));
        $deadline = microtime(true) + 10;
        while (proc_get_status($first[0])['running']) {
            self::assertLessThan($deadline, microtime(true), 'the killed runner is gone');
            usleep(10000);
        }
        self::assertFileExists($this->lockPath(), 'the file stays; only the lock went');
        self::assertFalse(CronLock::isHeld($this->lockPath()));
        self::assertSame('ran', self::said($this->start()), 'the run right after the killed one');
    }

    public function testAProcessTheRunStartsDoesNotKeepTheLock(): void
    {
        $started = microtime(true);
        $runner = $this->start('spawn');
        self::assertSame('ran', self::said($runner));
        self::assertSame('spawned', self::said($runner));
        self::stop($runner);
        $took = microtime(true) - $started;
        self::assertLessThan(2.0, $took, 'the runner ended while what it started (sleep 3) still runs');
        self::assertFalse(CronLock::isHeld($this->lockPath()), 'the lock ended with the run, not with what it started');
        self::assertSame('ran', self::said($this->start()));
    }

    public function testTheLockIsHeldWhileTheObjectIsAndFreeOnceItGoes(): void
    {
        self::assertFalse(CronLock::isHeld($this->lockPath()), 'no file: nobody holds it');
        $lock = CronLock::take($this->lockPath());
        self::assertInstanceOf(CronLock::class, $lock);
        self::assertTrue(CronLock::isHeld($this->lockPath()), 'another handle in this process sees it held');
        self::assertSame('busy', self::said($this->start()), 'a second run');
        unset($lock);
        self::assertFalse(CronLock::isHeld($this->lockPath()));
        self::assertInstanceOf(CronLock::class, CronLock::take($this->lockPath()));
    }

    public function testAFileTheLockMadeCanBeLockedByEveryUser(): void
    {
        $umask = umask(0077);
        try {
            $lock = CronLock::take($this->lockPath());
        } finally {
            umask($umask);
        }
        self::assertInstanceOf(CronLock::class, $lock);
        clearstatcache();
        self::assertSame('0644', sprintf('%04o', fileperms($this->lockPath()) & 0777), 'made under umask 077');
    }

    public function testAFileThisUserMayNotWriteIsLockedReadOnly(): void
    {
        self::assertTrue(touch($this->lockPath()));
        self::assertTrue(chmod($this->lockPath(), 0444));
        $lock = CronLock::take($this->lockPath());
        self::assertInstanceOf(CronLock::class, $lock);
        self::assertSame('busy', self::said($this->start()));
        unset($lock);
        self::assertSame('ran', self::said($this->start()));
    }

    public function testALockFileThatCannotBeMadeIsAFailureNotBusy(): void
    {
        $this->expectException(CronLockFailed::class);
        $this->expectExceptionMessage('could not make its lock file ' . $this->dir . '/none/' . CronLock::FILE);
        CronLock::take($this->dir . '/none/' . CronLock::FILE);
    }

    public function testAFileThisUserCanNeitherWriteNorReadIsAFailureNotBusy(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root reads every file');
        }
        self::assertTrue(touch($this->lockPath()));
        self::assertTrue(chmod($this->lockPath(), 0000));
        self::assertNull(CronLock::isHeld($this->lockPath()), 'health cannot tell, and says so');
        $this->expectException(CronLockFailed::class);
        $this->expectExceptionMessage('could not open its lock file');
        CronLock::take($this->lockPath());
    }

    /** A stream that is not a file is made by 'x' and refused a chmod, which is logged: here, to the test's directory. */
    private function quietly(): void
    {
        $log = ini_set('error_log', $this->dir . '/php.log');
        self::assertNotFalse($log);
        $this->errorLog = $log;
    }

    public function testALockThatCannotBeTakenIsAFailureNotBusy(): void
    {
        $this->quietly();
        $this->expectException(CronLockFailed::class);
        $this->expectExceptionMessage('could not lock php://memory');
        CronLock::take('php://memory');
    }

    /** flock() on a stream that cannot lock reports the errno an earlier call left behind. */
    public function testALockThatCannotBeTakenIsAFailureAfterAStaleEagain(): void
    {
        $this->quietly();
        $pipe = popen('sleep 1', 'r');
        self::assertIsResource($pipe);
        try {
            stream_set_blocking($pipe, false);
            self::assertSame('', fread($pipe, 1), 'an empty non-blocking read leaves errno at EAGAIN');
            $this->expectException(CronLockFailed::class);
            $this->expectExceptionMessage('could not lock php://memory');
            CronLock::take('php://memory');
        } finally {
            pclose($pipe);
        }
    }

    public function testTheLockIsTheOneBesideTheCronJob(): void
    {
        self::assertSame(dirname(__DIR__, 2) . '/202-cronjobs/cron-run.lock', CronLock::path());
    }
}
