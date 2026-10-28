<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\ServerStateStore;
use Tests\TestCase;

/**
 * ServerStateStore::reserveQuota(), the store behind the Android intake's
 * per-registration install cap and per-install event cap (plan §7.1): a
 * refused request takes nothing, a cost is counted whole, buckets are kept
 * apart by their whole name, and a store that cannot answer throws rather
 * than reading as "nothing used yet" (CLAUDE.md #11).
 */
final class ServerStateStoreQuotaTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/p202-quota-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function testARefusedRequestTakesNothingAndACostIsCountedWhole(): void
    {
        $store = new ServerStateStore($this->dir, 'quota-test');
        self::assertNull($store->reserveQuota('b', 100, 60, 60));
        $wait = $store->reserveQuota('b', 100, 60, 60);
        self::assertIsInt($wait);
        self::assertGreaterThanOrEqual(1, $wait);
        self::assertLessThanOrEqual(60, $wait);
        self::assertNull($store->reserveQuota('b', 100, 60, 40), 'the refused 60 took nothing: 40 still fit');
        self::assertIsInt($store->reserveQuota('b', 100, 60, 1));
    }

    public function testBucketsAreKeptApartByTheirWholeName(): void
    {
        $store = new ServerStateStore($this->dir, 'quota-test');
        // Names a slug would fold together: the file name carries a hash of the whole bucket.
        self::assertNull($store->reserveQuota('app-install-event-cap:r1:i23', 1, 60));
        self::assertNull($store->reserveQuota('app-install-event-cap:r12:i3', 1, 60));
        self::assertNull($store->reserveQuota('app-install-event-cap:r1-i23', 1, 60));
        self::assertIsInt($store->reserveQuota('app-install-event-cap:r1:i23', 1, 60));
    }

    public function testAnExpiredWindowStartsAgain(): void
    {
        $store = new ServerStateStore($this->dir, 'quota-test');
        self::assertNull($store->reserveQuota('w', 1, 60));
        $file = glob($this->dir . '/rate_limits/w-*.json')[0] ?? null;
        self::assertIsString($file);
        $state = json_decode((string) file_get_contents($file), true);
        $state['window_start'] -= 61;
        file_put_contents($file, json_encode($state));
        self::assertNull($store->reserveQuota('w', 1, 60));
    }

    public function testARefundGivesBackToTheWindowItWasChargedToAndNeverBelowZero(): void
    {
        $store = new ServerStateStore($this->dir, 'quota-test');
        $charge = $store->reserveQuotaWindow('r', 100, 60, 100);
        self::assertNull($charge['retry_after']);
        self::assertIsInt($store->reserveQuota('r', 100, 60, 1), 'full');
        self::assertTrue($store->refundQuota('r', 60, 40, $charge['window_start']));
        self::assertNull($store->reserveQuota('r', 100, 60, 40), 'the 40 refunded are spendable again');
        self::assertIsInt($store->reserveQuota('r', 100, 60, 1), 'and no more than that');

        // More than was charged takes the count to zero, never below it (a
        // negative count would read as malformed, and would be budget).
        self::assertTrue($store->refundQuota('r', 60, 500, $charge['window_start']));
        self::assertSame(0, $this->state('r')['count']);
        self::assertNull($store->reserveQuota('r', 100, 60, 100), 'zero, not negative: exactly the cap fits');
        self::assertIsInt($store->reserveQuota('r', 100, 60, 1));
    }

    public function testARefundAfterItsWindowClosedGivesNothingToTheNextOne(): void
    {
        $store = new ServerStateStore($this->dir, 'quota-test');
        $charge = $store->reserveQuotaWindow('n', 10, 60, 10);
        // The charge's window, moved a minute and a second into the past:
        // the next request opens a new one.
        $charged = $charge['window_start'] - 61;
        $state = $this->state('n');
        $state['window_start'] = $charged;
        file_put_contents($this->file('n'), json_encode($state));
        $next = $store->reserveQuotaWindow('n', 10, 60, 10);
        self::assertNull($next['retry_after']);
        self::assertNotSame($charged, $next['window_start']);
        self::assertFalse($store->refundQuota('n', 60, 10, $charged), 'the charged window is gone');
        self::assertSame(10, $this->state('n')['count'], 'the next window keeps its own count');
    }

    public function testARefundIsAsStrictAsTheCharge(): void
    {
        $store = new ServerStateStore($this->dir, 'quota-test');
        $charge = $store->reserveQuotaWindow('s', 5, 60, 5);
        foreach (['{not json', (string) json_encode(['window_start' => $charge['window_start'], 'count' => -1])] as $corrupt) {
            file_put_contents($this->file('s'), $corrupt);
            try {
                $store->refundQuota('s', 60, 1, $charge['window_start']);
                self::fail('a corrupt bucket is refused, not refunded into: ' . $corrupt);
            } catch (\RuntimeException) {
                self::assertSame($corrupt, file_get_contents($this->file('s')));
            }
        }
    }

    /** A bucket someone holds the lock of is live, however old its mtime reads. */
    public function testPruneLeavesABucketWhoseLockIsHeld(): void
    {
        $store = new ServerStateStore($this->dir, 'quota-test');
        self::assertNull($store->reserveQuota('p', 5, 60));
        $file = $this->file('p');
        touch($file, time() - 7200);
        $held = fopen($file . '.lock', 'c+');
        self::assertNotFalse($held);
        self::assertTrue(flock($held, LOCK_EX));
        try {
            $store->pruneStaleRateLimits(3600);
            clearstatcache();
            self::assertFileExists($file, 'a bucket being rewritten is not collected');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
        $store->pruneStaleRateLimits(3600);
        clearstatcache();
        self::assertFileDoesNotExist($file, 'released and still idle, it is');
        self::assertFileDoesNotExist($file . '.lock');
    }

    private function file(string $bucket): string
    {
        $file = glob($this->dir . '/rate_limits/' . $bucket . '-*.json')[0] ?? null;
        self::assertIsString($file);

        return $file;
    }

    /** @return array<string, mixed> */
    private function state(string $bucket): array
    {
        $state = json_decode((string) file_get_contents($this->file($bucket)), true);
        self::assertIsArray($state);

        return $state;
    }

    public function testAStoreThatCannotAnswerThrows(): void
    {
        $store = new ServerStateStore($this->dir, 'quota-test');
        self::assertNull($store->reserveQuota('c', 5, 60));
        $file = glob($this->dir . '/rate_limits/c-*.json')[0] ?? null;
        self::assertIsString($file);
        $corrupts = ['{not json', '"a string"', (string) json_encode(['window_start' => 'x', 'count' => 1]), (string) json_encode(['window_start' => time(), 'count' => -1])];
        foreach ($corrupts as $corrupt) {
            file_put_contents($file, $corrupt);
            try {
                $store->reserveQuota('c', 5, 60);
                self::fail('a corrupt bucket is not "nothing used": ' . $corrupt);
            } catch (\RuntimeException) {
                self::assertSame($corrupt, file_get_contents($file), 'and is left for the pruner, not overwritten with zero');
            }
        }

        $notADir = tempnam(sys_get_temp_dir(), 'p202-quota-file');
        self::assertIsString($notADir);
        try {
            (new ServerStateStore($notADir, 'quota-test'))->reserveQuota('d', 5, 60);
            self::fail('an unwritable store throws');
        } catch (\RuntimeException $e) {
            self::assertNotSame('', $e->getMessage());
        } finally {
            unlink($notADir);
        }
    }
}
