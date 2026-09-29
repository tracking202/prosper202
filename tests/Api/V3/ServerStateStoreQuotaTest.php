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
