<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Prosper202\Repository\Cached\CachedLocationRepository;
use Prosper202\Repository\Cached\CachedTrackingRepository;
use Prosper202\Repository\InMemory\InMemoryLocationRepository;
use Prosper202\Repository\InMemory\InMemoryTrackingRepository;

final class CachedKeyCollisionTest extends TestCase
{
    public function testCityKeyUsesDelimiterToPreventCollisions(): void
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

        $cached->findOrCreateCity('Berlin', 12);
        $cached->findOrCreateCity('Berlin1', 2);

        self::assertCount(2, $setKeys);
        self::assertNotSame($setKeys[0], $setKeys[1]);
    }

    public function testRegionKeyUsesDelimiterToPreventCollisions(): void
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

        $cached->findOrCreateRegion('Berlin', 12);
        $cached->findOrCreateRegion('Berlin1', 2);

        self::assertCount(2, $setKeys);
        self::assertNotSame($setKeys[0], $setKeys[1]);
    }

    public function testVariableKeyUsesDelimiterToPreventCollisions(): void
    {
        $store = [];
        $setKeys = [];

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

        $cached->findOrCreateVariable('2foo', 1);
        $cached->findOrCreateVariable('foo', 12);

        self::assertCount(2, $setKeys);
        self::assertNotSame($setKeys[0], $setKeys[1]);
    }

    public function testLongTrackingValueUsesTruncatedKey(): void
    {
        $store = [];
        $setKeys = [];

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

        $long = str_repeat('a', 351);
        $truncated = str_repeat('a', 350);

        $idLong = $cached->findOrCreateC1($long);
        $idTruncated = $cached->findOrCreateC1($truncated);

        self::assertSame($idLong, $idTruncated);
        self::assertCount(1, $setKeys);
    }
}
