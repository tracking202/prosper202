<?php

declare(strict_types=1);

namespace Tests\License;

use PHPUnit\Framework\TestCase;
use Prosper202\License\ShellAccessCache;

/**
 * The file cache of ClickServer key checks the web sign-in writes: strict
 * reads, a private directory, the hold-off marker, pruning and the one-asker
 * claim.
 */
final class ShellAccessCacheTest extends TestCase
{
    private string $key;

    protected function setUp(): void
    {
        $this->key = 'test-key-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        ShellAccessCache::invalidate($this->key);
    }

    /**
     * @dataProvider malformed
     */
    public function testMalformedEntryIsUnknownNotADenial(string $content): void
    {
        ShellAccessCache::write($this->key, true); // create the dir + a real entry
        $path = (new \ReflectionMethod(ShellAccessCache::class, 'path'))->invoke(null, $this->key);
        self::assertIsString($path);
        file_put_contents($path, $content);
        self::assertNull(ShellAccessCache::read($this->key));
        self::assertNull(ShellAccessCache::readStale($this->key));
    }

    /** @return array<string, array{string}> */
    public static function malformed(): array
    {
        return [
            'empty (interrupted write)' => [''],
            'garbage' => ['x'],
            'two digits' => ['10'],
            'leading space' => [' 1'],
            'trailing newline' => ["1\n"],
            'partial' => [' '],
        ];
    }

    public function testWriteLeavesNoTempFiles(): void
    {
        ShellAccessCache::write($this->key, false);
        $dir = sys_get_temp_dir() . '/p202-shell-access';
        self::assertSame([], glob($dir . '/*.tmp') ?: []);
        self::assertFalse(ShellAccessCache::read($this->key));
    }

    /**
     * Pins the shell cache's reads too (shared implementation): exact "1"/"0"
     * are verdicts; a corrupt entry is unknown, so /capabilities revalidates
     * the shell instead of denying it outright.
     */
    public function testShellCacheReadsAreStrictToo(): void
    {
        ShellAccessCache::write($this->key, true);
        self::assertTrue(ShellAccessCache::read($this->key));
        ShellAccessCache::write($this->key, false);
        self::assertFalse(ShellAccessCache::read($this->key));
        $path = (new \ReflectionMethod(ShellAccessCache::class, 'path'))->invoke(null, $this->key);
        file_put_contents($path, '');
        self::assertNull(ShellAccessCache::read($this->key));
    }

    public function testTheCacheLivesInItsOwnPrivateDirectory(): void
    {
        ShellAccessCache::write($this->key, true);
        $dir = sys_get_temp_dir() . '/p202-shell-access';
        self::assertDirectoryExists($dir);
        self::assertSame(0700, fileperms($dir) & 0777);
    }

    /**
     * An unanswered check is remembered for UNKNOWN_TTL_SECONDS so a burst of
     * requests during an outage asks ClickServer once, and it is never a
     * verdict: read() and readStale() still say unknown, and the last real
     * verdict is what readStale() keeps returning.
     */
    public function testAnUnansweredCheckHoldsOffTheNextWithoutBecomingAVerdict(): void
    {
        self::assertFalse(ShellAccessCache::recentlyUnknown($this->key));
        ShellAccessCache::markUnknown($this->key);
        self::assertTrue(ShellAccessCache::recentlyUnknown($this->key));
        self::assertNull(ShellAccessCache::read($this->key));
        self::assertNull(ShellAccessCache::readStale($this->key));
        self::assertFalse(ShellAccessCache::recentlyUnknown($this->key, 0 - 1), 'an expired marker is not recent');
    }

    public function testTheStaleVerdictSurvivesAnUnansweredCheck(): void
    {
        ShellAccessCache::write($this->key, true);
        ShellAccessCache::markUnknown($this->key);
        self::assertTrue(ShellAccessCache::recentlyUnknown($this->key));
        self::assertTrue(ShellAccessCache::readStale($this->key));
        self::assertTrue(ShellAccessCache::read($this->key), 'a fresh verdict is still a verdict');
    }

    public function testAVerdictEndsTheHoldOff(): void
    {
        ShellAccessCache::markUnknown($this->key);
        ShellAccessCache::write($this->key, false);
        self::assertFalse(ShellAccessCache::recentlyUnknown($this->key));
        self::assertFalse(ShellAccessCache::read($this->key));
    }

    public function testARefusalExpiresSoonerThanAnAllow(): void
    {
        $path = (new \ReflectionMethod(ShellAccessCache::class, 'path'))->invoke(null, $this->key);
        ShellAccessCache::write($this->key, false);
        self::assertFalse(ShellAccessCache::readVerdict($this->key), 'a fresh refusal is a refusal');
        touch($path, time() - ShellAccessCache::DENIAL_TTL_SECONDS + 60);
        self::assertFalse(ShellAccessCache::readVerdict($this->key), 'and still one shortly before its TTL');
        touch($path, time() - ShellAccessCache::DENIAL_TTL_SECONDS - 60);
        self::assertNull(ShellAccessCache::readVerdict($this->key), 'past DENIAL_TTL_SECONDS a refusal is unknown');
        self::assertFalse(ShellAccessCache::read($this->key), 'read() alone still sees it within the long TTL');
        ShellAccessCache::write($this->key, true);
        touch($path, time() - ShellAccessCache::DENIAL_TTL_SECONDS - 60);
        self::assertTrue(ShellAccessCache::readVerdict($this->key), 'an allow of the same age is still an allow');
        touch($path, time() - ShellAccessCache::TTL_SECONDS - 60);
        self::assertNull(ShellAccessCache::readVerdict($this->key), 'until TTL_SECONDS');
    }

    public function testAnEntryDatedInTheFutureIsUnknownNotAVerdict(): void
    {
        $path = (new \ReflectionMethod(ShellAccessCache::class, 'path'))->invoke(null, $this->key);
        ShellAccessCache::write($this->key, false);
        touch($path, time() + 600);
        self::assertNull(ShellAccessCache::read($this->key));
        self::assertNull(ShellAccessCache::readVerdict($this->key));
        self::assertNull(ShellAccessCache::readStale($this->key));
        ShellAccessCache::markUnknown($this->key);
        touch($path . '.unknown', time() + 600);
        self::assertFalse(ShellAccessCache::recentlyUnknown($this->key), 'a future-dated hold-off is no hold-off');
    }

    /**
     * prune() keeps what readStale() may still serve (STALE_TTL_SECONDS) and
     * drops what is older. Every write() here happens before the ageing:
     * write() itself sweeps one time in fifty, and a sweep between the ageing
     * and the explicit prune() would make the count below a coin toss.
     */
    public function testPruneDropsOnlyWhatIsPastTheStaleWindow(): void
    {
        $old = 'test-key-old-' . bin2hex(random_bytes(6));
        $stale = 'test-key-stale-' . bin2hex(random_bytes(6));
        $paths = [];
        foreach ([$old, $stale] as $k) {
            $paths[$k] = (new \ReflectionMethod(ShellAccessCache::class, 'path'))->invoke(null, $k);
            ShellAccessCache::write($k, true);
            ShellAccessCache::markUnknown($k);
        }
        ShellAccessCache::write($this->key, false); // fresh: must survive
        try {
            touch($paths[$old], time() - ShellAccessCache::STALE_TTL_SECONDS - 60);
            touch($paths[$old] . '.unknown', time() - ShellAccessCache::STALE_TTL_SECONDS - 60);
            touch($paths[$stale], time() - ShellAccessCache::TTL_SECONDS - 60); // expired, still stale-readable
            $removed = ShellAccessCache::prune();
            self::assertGreaterThanOrEqual(2, $removed, 'the entry and marker past the stale window go');
            self::assertFileDoesNotExist($paths[$old]);
            self::assertFileDoesNotExist($paths[$old] . '.unknown');
            self::assertFileExists($paths[$stale], 'an entry readStale() still serves is kept');
            self::assertNull(ShellAccessCache::read($stale));
            self::assertTrue(ShellAccessCache::readStale($stale));
            self::assertFalse(ShellAccessCache::read($this->key), 'the fresh entry stays');
            self::assertSame(0, ShellAccessCache::prune(), 'nothing else is old enough');
            touch($paths[$stale], time() - ShellAccessCache::STALE_TTL_SECONDS - 60);
            self::assertNull(ShellAccessCache::readStale($stale), 'past the stale window readStale() is unknown too');
        } finally {
            ShellAccessCache::invalidate($old);
            ShellAccessCache::invalidate($stale);
        }
    }

    /** A temp file left by a write that never finished is swept once it is an hour old. */
    public function testPruneSweepsAnOrphanedTempFile(): void
    {
        $path = (new \ReflectionMethod(ShellAccessCache::class, 'path'))->invoke(null, $this->key);
        ShellAccessCache::write($this->key, true);
        $orphan = $path . '.0123456789ab.tmp';
        $inFlight = $path . '.ba9876543210.tmp';
        file_put_contents($orphan, 'x');
        file_put_contents($inFlight, 'x');
        try {
            touch($orphan, time() - ShellAccessCache::ORPHAN_TMP_SECONDS - 60);
            self::assertSame(1, ShellAccessCache::prune());
            self::assertFileDoesNotExist($orphan);
            self::assertFileExists($inFlight, 'a temp file a write may still rename is kept');
            self::assertTrue(ShellAccessCache::read($this->key), 'the entry stays');
            ShellAccessCache::prune(0);
            self::assertFileExists($inFlight, 'a sweep of every entry still leaves a temp file being written');
        } finally {
            @unlink($orphan);
            @unlink($inFlight);
        }
    }

    public function testOneAskerPerKeyAcrossProcesses(): void
    {
        self::assertTrue(ShellAccessCache::beginCheck($this->key), 'the first claim wins');
        self::assertFalse(ShellAccessCache::beginCheck($this->key), 'a second claim within the window is refused');
        ShellAccessCache::endCheck($this->key);
        self::assertTrue(ShellAccessCache::beginCheck($this->key), 'released, it can be claimed again');
        $path = (new \ReflectionMethod(ShellAccessCache::class, 'path'))->invoke(null, $this->key);
        touch($path . '.checking', time() - ShellAccessCache::CHECK_LOCK_SECONDS - 5);
        self::assertTrue(
            ShellAccessCache::beginCheck($this->key),
            'a claim older than the window belonged to a dead worker and is taken over'
        );
        ShellAccessCache::endCheck($this->key);
        self::assertFileDoesNotExist($path . '.checking');
    }

    public function testAClaimThatCannotBeCreatedStillAsks(): void
    {
        $unwritable = new class () extends ShellAccessCache {
            protected static function path(string $key): ?string
            {
                return sys_get_temp_dir() . '/p202-no-such-dir-' . bin2hex(random_bytes(6)) . '/entry.cache';
            }
        };
        self::assertTrue(
            $unwritable::beginCheck($this->key),
            'a lock that cannot be created is nothing to coordinate on, not another worker\'s claim'
        );
    }

    public function testInvalidateDropsTheHoldOffToo(): void
    {
        ShellAccessCache::markUnknown($this->key);
        ShellAccessCache::invalidate($this->key);
        self::assertFalse(ShellAccessCache::recentlyUnknown($this->key));
        self::assertSame([], glob(sys_get_temp_dir() . '/p202-shell-access/*.tmp') ?: []);
    }
}
