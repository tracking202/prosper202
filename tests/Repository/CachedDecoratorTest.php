<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Prosper202\Repository\Cached\CachedDeviceRepository;
use Prosper202\Repository\Cached\CachedLocationRepository;
use Prosper202\Repository\Cached\CachedTrackingRepository;
use Prosper202\Repository\InMemory\InMemoryDeviceRepository;
use Prosper202\Repository\InMemory\InMemoryLocationRepository;
use Prosper202\Repository\InMemory\InMemoryTrackingRepository;

final class CachedDecoratorTest extends TestCase
{
    public function testCacheHitSkipsInner(): void
    {
        $innerCalls = 0;
        $delegate = new InMemoryDeviceRepository();
        $inner = new class ($innerCalls, $delegate) implements \Prosper202\Repository\DeviceRepositoryInterface {
            public function __construct(private int &$calls, private \Prosper202\Repository\DeviceRepositoryInterface $delegate)
            {
            }

            public function findOrCreateBrowser(string $name): int
            {
                $this->calls++;
                return $this->delegate->findOrCreateBrowser($name);
            }

            public function findOrCreatePlatform(string $name): int
            {
                return $this->delegate->findOrCreatePlatform($name);
            }

            public function findOrCreateDevice(string $name): int
            {
                return $this->delegate->findOrCreateDevice($name);
            }
        };

        $store = [];
        $cached = new CachedDeviceRepository(
            $inner,
            static function (string $key) use (&$store) {
                return $store[$key] ?? false;
            },
            static function (string $key, mixed $value, int $ttl) use (&$store): void {
                $store[$key] = $value;
            },
            'test-hash',
        );

        $id1 = $cached->findOrCreateBrowser('Chrome');
        self::assertSame(1, $innerCalls, 'First call should hit inner');

        $id2 = $cached->findOrCreateBrowser('Chrome');
        self::assertSame(1, $innerCalls, 'Second call should use cache, not inner');

        self::assertSame($id1, $id2);
    }

    public function testCacheKeyMatchesCountry(): void
    {
        $store = [];
        $setKeys = [];

        $cached = new CachedLocationRepository(
            new InMemoryLocationRepository(),
            static function (string $key) use (&$store) {
                return $store[$key] ?? false;
            },
            static function (string $key, mixed $value, int $ttl) use (&$store, &$setKeys): void {
                $store[$key] = $value;
                $setKeys[] = $key;
            },
            'fakehash',
        );

        $cached->findOrCreateCountry('United States', 'US');

        $expectedKey = md5('country-id' . 'US' . 'fakehash');
        self::assertSame($expectedKey, $setKeys[0]);
    }

    public function testCacheKeyMatchesUtm(): void
    {
        $setKeys = [];
        $store = [];

        $cached = new CachedTrackingRepository(
            new InMemoryTrackingRepository(),
            static function (string $key) use (&$store) {
                return $store[$key] ?? false;
            },
            static function (string $key, mixed $value, int $ttl) use (&$store, &$setKeys): void {
                $store[$key] = $value;
                $setKeys[] = $key;
            },
            'fakehash',
        );

        $cached->findOrCreateUtm('google', 'utm_source');

        $expectedKey = md5('utm-' . 'utm_source' . '|' . 'google' . 'fakehash');
        self::assertSame($expectedKey, $setKeys[0]);
    }

    public function testCacheKeyMatchesVariable(): void
    {
        $setKeys = [];
        $store = [];

        $cached = new CachedTrackingRepository(
            new InMemoryTrackingRepository(),
            static function (string $key) use (&$store) {
                return $store[$key] ?? false;
            },
            static function (string $key, mixed $value, int $ttl) use (&$store, &$setKeys): void {
                $store[$key] = $value;
                $setKeys[] = $key;
            },
            'fakehash',
        );

        $cached->findOrCreateVariable('my-value', 42);

        $expectedKey = md5('variable-id' . '42' . '|' . 'my-value' . 'fakehash');
        self::assertSame($expectedKey, $setKeys[0]);
    }

    public function testCacheKeyMatchesCustomVar(): void
    {
        $setKeys = [];
        $store = [];

        $cached = new CachedTrackingRepository(
            new InMemoryTrackingRepository(),
            static function (string $key) use (&$store) {
                return $store[$key] ?? false;
            },
            static function (string $key, mixed $value, int $ttl) use (&$store, &$setKeys): void {
                $store[$key] = $value;
                $setKeys[] = $key;
            },
            'fakehash',
        );

        $cached->findOrCreateCustomVar('sub_id', 'abc123');

        $expectedKey = md5('customvar-' . 'sub_id' . '|' . 'abc123' . 'fakehash');
        self::assertSame($expectedKey, $setKeys[0]);
    }

    public function testEmptyValuesSkipCacheAndReturnZero(): void
    {
        $store = [];
        $setCalls = 0;

        $cached = new CachedDeviceRepository(
            new InMemoryDeviceRepository(),
            static function (string $key) use (&$store) {
                return $store[$key] ?? false;
            },
            static function (string $key, mixed $value, int $ttl) use (&$setCalls): void {
                $setCalls++;
            },
            'test-hash',
        );

        self::assertSame(0, $cached->findOrCreateBrowser(''));
        self::assertSame(0, $cached->findOrCreatePlatform(''));
        self::assertSame(0, $cached->findOrCreateDevice(''));
        self::assertSame(0, $setCalls, 'Should not cache zero results');
    }
}
