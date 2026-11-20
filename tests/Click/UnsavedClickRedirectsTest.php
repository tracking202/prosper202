<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\UnsavedClickRedirects;

final class UnsavedClickRedirectsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/p202-unsaved-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/202-config/temp', 0777, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->root . '/202-config/temp', 0777);
        foreach (['', '.lock', '.tmp'] as $suffix) {
            @chmod(UnsavedClickRedirects::path($this->root) . $suffix, 0666);
            @unlink(UnsavedClickRedirects::path($this->root) . $suffix);
        }
        @chmod($this->root . '/202-config/temp', 0777);
        @rmdir($this->root . '/202-config/temp');
        @rmdir($this->root . '/202-config');
        @rmdir($this->root);
    }

    public function testNoFileIsNoneCounted(): void
    {
        self::assertSame(
            ['state' => 'none', 'count' => 0, 'first_at' => null, 'last_at' => null],
            UnsavedClickRedirects::read($this->root)
        );
        self::assertNull(UnsavedClickRedirects::warning(UnsavedClickRedirects::read($this->root)));
    }

    public function testEachRedirectIsCountedWithTheFirstAndLastTimes(): void
    {
        self::assertTrue(UnsavedClickRedirects::record($this->root, 'dl.php', 1_000));
        self::assertTrue(UnsavedClickRedirects::record($this->root . '/', 'lp.php', 2_000));
        self::assertTrue(UnsavedClickRedirects::record($this->root, 'off.php', 3_000));

        self::assertSame(
            ['state' => 'counted', 'count' => 3, 'first_at' => 1_000, 'last_at' => 3_000],
            UnsavedClickRedirects::read($this->root)
        );
    }

    /**
     * Twenty processes counting at once: a count waits about 30 ms for the
     * lock and is dropped after that, so every count is either kept or
     * answered false, and none is counted twice.
     */
    public function testConcurrentRedirectsAreKeptOrAnsweredFalse(): void
    {
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$dropped = 0; for ($i = 0; $i < 25; $i++) { if (!\\Prosper202\\Click\\UnsavedClickRedirects::record('
            . var_export($this->root, true) . ', "dl.php")) { $dropped++; } } echo $dropped;';
        $processes = [];
        for ($i = 0; $i < 20; $i++) {
            $processes[] = [proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes), $pipes[1]];
        }
        $dropped = 0;
        foreach ($processes as [$process, $out]) {
            $dropped += (int) stream_get_contents($out);
            fclose($out);
            self::assertSame(0, proc_close($process));
        }

        self::assertSame(500 - $dropped, UnsavedClickRedirects::read($this->root)['count']);
    }

    /** A count that finds the lock taken gives up within a few tens of milliseconds and changes nothing; a read never waits. */
    public function testATakenLockDropsTheCountWithoutWaiting(): void
    {
        self::assertTrue(UnsavedClickRedirects::record($this->root, 'dl.php', 1_000));
        $holder = fopen(UnsavedClickRedirects::path($this->root) . '.lock', 'c');
        self::assertIsResource($holder);
        self::assertTrue(flock($holder, LOCK_EX));
        try {
            $started = microtime(true);
            self::assertFalse(UnsavedClickRedirects::record($this->root, 'dl.php', 2_000));
            $recordTook = microtime(true) - $started;
            $started = microtime(true);
            $read = UnsavedClickRedirects::read($this->root);
            $readTook = microtime(true) - $started;
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }

        self::assertLessThan(0.25, $recordTook, 'a count waits no more than its few tries');
        self::assertLessThan(0.25, $readTook);
        self::assertSame(['state' => 'counted', 'count' => 1, 'first_at' => 1_000, 'last_at' => 1_000], $read);
    }

    /**
     * A write that fails part way (here a file size limit of 0, which is what
     * a full disk does to it) leaves the previous count whole.
     */
    public function testAFailedWriteKeepsThePreviousCount(): void
    {
        if (!is_executable('/bin/sh')) {
            self::markTestSkipped('no /bin/sh to set a file size limit in');
        }
        file_put_contents(UnsavedClickRedirects::path($this->root), '{"count":1000,"first_at":1000,"last_at":2000}');
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . 'var_export(\\Prosper202\\Click\\UnsavedClickRedirects::record('
            . var_export($this->root, true) . ', "dl.php", 3000));';
        $process = proc_open(
            ['/bin/sh', '-c', 'ulimit -f 0; exec "$0" -d log_errors=0 -r "$1"', PHP_BINARY, $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $said = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertNotSame('true', $said, 'the write was refused');
        self::assertSame(
            ['state' => 'counted', 'count' => 1000, 'first_at' => 1000, 'last_at' => 2000],
            UnsavedClickRedirects::read($this->root)
        );
        self::assertTrue(
            UnsavedClickRedirects::record($this->root, 'dl.php', 4000),
            'and the next count goes on from it'
        );
        self::assertSame(1001, UnsavedClickRedirects::read($this->root)['count']);
    }

    public function testAFileLongerThanAnyCountIsUnreadable(): void
    {
        file_put_contents(UnsavedClickRedirects::path($this->root), '{"count":1,"first_at":1,"last_at":1,"pad":"'
            . str_repeat('x', 5000) . '"}');

        self::assertSame('unreadable', UnsavedClickRedirects::read($this->root)['state']);
    }

    /** A file that cannot be read is not zero: it says so, and the next count starts again from its own time. */
    public function testAnUnreadableCountIsNeverZero(): void
    {
        file_put_contents(UnsavedClickRedirects::path($this->root), '{"count": "lots"}');

        $read = UnsavedClickRedirects::read($this->root);
        self::assertSame('unreadable', $read['state']);
        self::assertNull($read['count']);
        self::assertStringContainsString('cannot be read', (string) UnsavedClickRedirects::warning($read));

        self::assertTrue(UnsavedClickRedirects::record($this->root, 'dl.php', 5_000));
        self::assertSame(
            ['state' => 'counted', 'count' => 1, 'first_at' => 5_000, 'last_at' => 5_000],
            UnsavedClickRedirects::read($this->root)
        );
    }

    public function testAnEmptyFileIsUnreadable(): void
    {
        touch(UnsavedClickRedirects::path($this->root));

        self::assertSame('unreadable', UnsavedClickRedirects::read($this->root)['state']);
    }

    /** Logged once a request: testACountThatCannotBeKeptIsLoggedOnceARequest reads the line in a process of its own. */
    public function testACountThatCannotBeWrittenAnswersFalseAndIsUnrecordable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes into a read-only directory');
        }
        chmod($this->root . '/202-config/temp', 0555);
        $previous = ini_set('error_log', '/dev/null');
        try {
            self::assertFalse(UnsavedClickRedirects::record($this->root, 'dl.php'));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $read = UnsavedClickRedirects::read($this->root);
        self::assertSame(['state' => 'unrecordable', 'count' => null, 'first_at' => null, 'last_at' => null], $read);
        self::assertStringContainsString('cannot be counted', (string) UnsavedClickRedirects::warning($read));
    }

    /** Twice in one request is logged once: an outage must not write a line for every click it loses. */
    public function testACountThatCannotBeKeptIsLoggedOnceARequest(): void
    {
        rmdir($this->root . '/202-config/temp');
        $code = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . 'for ($i = 0; $i < 5; $i++) { \\Prosper202\\Click\\UnsavedClickRedirects::record('
            . var_export($this->root, true) . ', "dl.php"); }';
        $log = (string) tempnam(sys_get_temp_dir(), 'p202log');
        $process = proc_open([PHP_BINARY, '-d', 'log_errors=1', '-d', 'error_log=' . $log, '-r', $code], [], $pipes);
        self::assertSame(0, proc_close($process));
        $logged = (string) file_get_contents($log);
        unlink($log);
        mkdir($this->root . '/202-config/temp');

        self::assertSame(1, substr_count($logged, 'sent a visitor on without saving the click'), $logged);
    }

    /** No directory to count in is not "none": nothing could have been counted. */
    public function testAMissingDirectoryIsUnrecordable(): void
    {
        rmdir($this->root . '/202-config/temp');
        try {
            self::assertSame('unrecordable', UnsavedClickRedirects::read($this->root)['state']);
        } finally {
            mkdir($this->root . '/202-config/temp');
        }
    }

    /** A count already kept is still said when the directory stops being writable. */
    public function testAnUnwritableDirectoryKeepsTheCountItHad(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes into a read-only directory');
        }
        self::assertTrue(UnsavedClickRedirects::record($this->root, 'dl.php', 1_000));
        chmod(UnsavedClickRedirects::path($this->root) . '.lock', 0444);
        $previous = ini_set('error_log', '/dev/null');

        self::assertSame(
            ['state' => 'unrecordable', 'count' => 1, 'first_at' => 1_000, 'last_at' => 1_000],
            UnsavedClickRedirects::read($this->root)
        );
        self::assertFalse(UnsavedClickRedirects::record($this->root, 'dl.php', 2_000));
        ini_set('error_log', (string) $previous);
    }

    /** The unauthenticated probe says whether to look, never the count, the times or the file. */
    public function testThePublicFieldsSayNoCountTimeOrPath(): void
    {
        $now = 1_000_000;
        $recent = ['state' => 'counted', 'count' => 1234, 'first_at' => $now - 100, 'last_at' => $now - 10];
        self::assertSame(['state' => 'warning'], UnsavedClickRedirects::publicFields($recent, $now));
        $sentence = (string) UnsavedClickRedirects::publicWarning($recent, $now);
        self::assertStringContainsString('in the past 7 days', $sentence);
        foreach (['1234', '1,234', gmdate('Y-m-d', $now), 'temp', '.json'] as $secret) {
            self::assertStringNotContainsString($secret, $sentence);
        }
        foreach (['unreadable', 'unrecordable'] as $state) {
            $read = ['state' => $state, 'count' => null, 'first_at' => null, 'last_at' => null];
            self::assertSame(['state' => 'warning'], UnsavedClickRedirects::publicFields($read, $now));
            self::assertStringNotContainsString('temp', (string) UnsavedClickRedirects::publicWarning($read, $now));
        }
        $old = ['last_at' => $now - UnsavedClickRedirects::RECENT_SECONDS - 1] + $recent;
        self::assertSame(['state' => 'ok'], UnsavedClickRedirects::publicFields($old, $now));
        self::assertSame(['state' => 'ok'], UnsavedClickRedirects::publicFields(
            ['state' => 'none', 'count' => 0, 'first_at' => null, 'last_at' => null],
            $now
        ));
    }

    public function testTheWarningHoldsForSevenDaysAfterTheLast(): void
    {
        $read = ['state' => 'counted', 'count' => 1234, 'first_at' => 86_400, 'last_at' => 172_800];
        $last = 172_800;

        $warning = (string) UnsavedClickRedirects::warning($read, $last + UnsavedClickRedirects::RECENT_SECONDS);
        self::assertStringContainsString('1,234 clicks were redirected without being saved since 1970-01-02 00:00', $warning);
        self::assertStringContainsString('the last at 1970-01-03 00:00 UTC', $warning);
        self::assertStringContainsString('the database refused the connection', $warning);
        self::assertNull(UnsavedClickRedirects::warning($read, $last + UnsavedClickRedirects::RECENT_SECONDS + 1));
        self::assertStringStartsWith(
            '1 click was redirected',
            (string) UnsavedClickRedirects::warning(['count' => 1] + $read, $last)
        );
    }
}
