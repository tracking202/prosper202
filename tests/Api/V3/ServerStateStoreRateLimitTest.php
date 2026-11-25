<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\ServerStateStore;
use Tests\TestCase;

/**
 * The per-source limiter's two invariants — one bucket per distinct source,
 * and a lock nobody is holding is the only lock a collection pass may remove
 * — plus the check that the public endpoints all go through it.
 */
final class ServerStateStoreRateLimitTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/p202-rate-limit-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }

    /** @return string[] */
    private function bucketFiles(): array
    {
        $found = glob($this->dir . '/rate_limits/*.json');
        return $found === false ? [] : $found;
    }

    /**
     * Both keys below are canonical (RFC 5952) forms REMOTE_ADDR really
     * produces, and both slug to "peer-2001-db8-1": the bucket filename must
     * be derived from the raw key, or one peer exhausting its window answers
     * "rate limited" to an unrelated one.
     */
    public function testDistinctPeersNeverShareABucket(): void
    {
        $store = new ServerStateStore($this->dir);
        $first = 'peer:2001:db8::1';
        $second = 'peer:2001:db8:1::';

        $store->consumeRateLimit($first, 2, 60);
        $store->consumeRateLimit($first, 2, 60);
        $exhausted = $store->consumeRateLimit($first, 2, 60);
        $this->assertFalse($exhausted['allowed'], 'sanity: the limiter still counts within one bucket');

        $other = $store->consumeRateLimit($second, 2, 60);
        $this->assertTrue($other['allowed'], 'a distinct peer must not inherit another peer\'s exhausted window');
        $this->assertSame(1, $other['remaining'], 'the second peer must start its own count at 1');
        $this->assertCount(2, $this->bucketFiles(), 'two distinct keys must occupy two bucket files');
    }

    /** Every distinct key gets its own file, whatever slug() makes of it. */
    public function testKeysThatSlugAlikeGetDistinctFiles(): void
    {
        $store = new ServerStateStore($this->dir);
        foreach (['peer:fe80::1', 'peer:fe80:1::', 'peer:fe80::0:1', 'peer:fe80-1'] as $key) {
            $store->consumeRateLimit($key, 10, 60);
        }
        $this->assertCount(4, $this->bucketFiles());
    }

    /**
     * A missing bucket file and an old mtime are both true of a lock that is
     * held right now — the bucket is written at the END of the critical
     * section, and a lock's mtime never moves off its creation time. Unlinking
     * it puts the next caller on a fresh inode, which is the lost update the
     * lock exists to prevent.
     */
    public function testPruneKeepsALockThatIsHeld(): void
    {
        $store = new ServerStateStore($this->dir);
        $store->consumeRateLimit('held-peer', 100, 60);
        $bucket = $this->bucketFiles()[0];
        $lock = $bucket . '.lock';

        unlink($bucket);
        touch($lock, time() - 7200);

        $held = fopen($lock, 'c+');
        $this->assertNotFalse($held);
        $this->assertTrue(flock($held, LOCK_EX));
        try {
            $store->pruneStaleRateLimits(3600);
            clearstatcache();
            $this->assertFileExists($lock, 'a lock someone is inside must survive collection');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
    }

    /** ...and the collection the pass exists for still happens. */
    public function testPruneStillCollectsUnheldStaleFiles(): void
    {
        $store = new ServerStateStore($this->dir);
        $store->consumeRateLimit('idle-peer', 100, 60);
        $bucket = $this->bucketFiles()[0];
        $dir = $this->dir . '/rate_limits';

        unlink($bucket);
        touch($bucket . '.lock', time() - 7200);
        file_put_contents($dir . '/orphan.json.tmp-abcd', 'x');
        touch($dir . '/orphan.json.tmp-abcd', time() - 7200);
        file_put_contents($dir . '/stale.json', '{}');
        touch($dir . '/stale.json', time() - 7200);
        file_put_contents($dir . '/fresh.json', '{}');
        file_put_contents($dir . '/fresh.json.lock', '');

        $store->pruneStaleRateLimits(3600);
        clearstatcache();

        $this->assertFileDoesNotExist($bucket . '.lock');
        $this->assertFileDoesNotExist($dir . '/orphan.json.tmp-abcd');
        $this->assertFileDoesNotExist($dir . '/stale.json');
        $this->assertFileExists($dir . '/fresh.json');
        $this->assertFileExists($dir . '/fresh.json.lock');
    }

    /**
     * softIpRateLimit()'s bucket is the client VisitorIp names: behind a
     * listed proxy each visitor its own, and from any other peer the peer,
     * however many addresses the header claims (CLAUDE.md #16).
     */
    public function testTheSoftLimitKeysOnTheClientATrustedProxyNames(): void
    {
        $store = new ServerStateStore($this->dir);
        $saved = $_SERVER;
        $before = getenv('P202_TRUSTED_PROXIES');
        try {
            putenv('P202_TRUSTED_PROXIES=10.0.0.1');
            $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
            $this->assertNull($store->softIpRateLimit('t', 1, 60));
            $this->assertNotNull($store->softIpRateLimit('t', 1, 60), 'the same visitor again is over the limit');
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 198.51.100.8';
            $this->assertNull($store->softIpRateLimit('t', 1, 60), 'another visitor behind the same proxy has its own bucket');
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99, 198.51.100.8';
            $this->assertNotNull($store->softIpRateLimit('t', 1, 60), 'a forged hop to the left does not mint a bucket');
            $this->assertCount(2, $this->bucketFiles());

            $_SERVER['REMOTE_ADDR'] = '192.0.2.50';
            foreach (['198.51.100.20', '198.51.100.21'] as $i => $claimed) {
                $_SERVER['HTTP_X_FORWARDED_FOR'] = $claimed;
                $answer = $store->softIpRateLimit('t', 1, 60);
                $i === 0 ? $this->assertNull($answer) : $this->assertNotNull($answer, 'an unlisted peer is one bucket, whatever it claims');
            }
            $this->assertCount(3, $this->bucketFiles());

            putenv('P202_TRUSTED_PROXIES');
            $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.30';
            $this->assertNull($store->softIpRateLimit('t', 1, 60));
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.31';
            $this->assertNotNull($store->softIpRateLimit('t', 1, 60), 'no setting: the proxy is the one client');
        } finally {
            $_SERVER = $saved;
            putenv($before === false ? 'P202_TRUSTED_PROXIES' : 'P202_TRUSTED_PROXIES=' . $before);
        }
    }

    /**
     * The public endpoints must key on the client softIpRateLimit() resolves
     * (VisitorIp). consumeRateLimit() takes whatever key the caller builds,
     * and a key built from a forwarding header the caller read itself lets a
     * flooder mint a fresh bucket per request.
     */
    public function testPublicEndpointsUseThePeerKeyedLimiter(): void
    {
        $root = dirname(__DIR__, 3);
        $endpoints = [
            '/tracking202/static/p13n.php' => 'p13n',
            '/tracking202/static/p13n_event.php' => 'p13n_event',
            // The public app intake's shared plumbing: every pre-auth app
            // route and the /.well-known/ postback endpoints limit through it.
            '/api/v3/Apps/PublicIntake.php' => null,
        ];
        foreach ($endpoints as $file => $prefix) {
            $src = (string)file_get_contents($root . $file);
            $this->assertStringContainsString(
                'softIpRateLimit(',
                $src,
                $file . ' must rate-limit through softIpRateLimit()'
            );
            if ($prefix !== null) {
                $this->assertStringContainsString("'" . $prefix . "'", $src, $file . ' must keep its bucket prefix');
            }
            $this->assertStringNotContainsString(
                'consumeRateLimit(',
                $src,
                $file . ' must not build its own bucket key: unauthenticated endpoints key on the client softIpRateLimit() resolves'
            );
        }
    }

    /**
     * The public app routes limit through PublicIntake, each with its own
     * bucket prefix, and none builds a bucket key of its own.
     */
    public function testThePublicAppRoutesLimitThroughPublicIntake(): void
    {
        $root = dirname(__DIR__, 3);
        $callers = [
            '/api/v3/Apps/Apple/PostbackIntake.php' => "RATE_LIMIT_BUCKET = 'app-postbacks'",
            '/api/v3/index.php' => "PublicIntake::rateLimit('app-schema'",
        ];
        foreach ($callers as $file => $bucket) {
            $src = (string)file_get_contents($root . $file);
            $this->assertStringContainsString('PublicIntake::rateLimit(', $src, $file . ' must limit through PublicIntake');
            $this->assertStringContainsString($bucket, $src, $file . ' must keep its bucket prefix');
        }
        // index.php also rate-limits authenticated requests (keyed by the API
        // key, which the caller proved), so only the postback intake is held
        // to having no bucket key of its own.
        $this->assertStringNotContainsString(
            'consumeRateLimit(',
            (string)file_get_contents($root . '/api/v3/Apps/Apple/PostbackIntake.php'),
            'PostbackIntake must not build its own bucket key'
        );
    }
}
