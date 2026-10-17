<?php

declare(strict_types=1);

namespace Tests\Repository;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Repository\Cache\MemcacheIdCache;
use Prosper202\Repository\Cache\NullIdCache;
use Prosper202\Repository\Cached\CachedTrackingRepository;
use Prosper202\Repository\InMemory\InMemoryTrackingRepository;

/**
 * Only a positive id read from the cache is an answer. The decorators used
 * to return `(int) $cached` for anything but false, so an entry holding 0,
 * '' or junk answered 0 (no value) or a garbage id for its whole TTL.
 */
final class IdCacheTest extends TestCase
{
    /** @return array<string, array{mixed, ?int}> */
    public static function entries(): array
    {
        return [
            'an int (memcached)' => [42, 42],
            'a numeric string (memcache)' => ['42', 42],
            'leading zeros' => ['007', 7],
            'the largest id an int holds' => [(string) PHP_INT_MAX, PHP_INT_MAX],
            'no entry (false)' => [false, null],
            'null' => [null, null],
            'zero' => [0, null],
            'zero as a string' => ['0', null],
            'an empty string' => ['', null],
            'negative' => [-3, null],
            'negative as a string' => ['-3', null],
            'junk' => ['abc', null],
            'digits then junk' => ['12abc', null],
            'a float' => [12.0, null],
            'true' => [true, null],
            'an array' => [[12], null],
            'past PHP_INT_MAX' => ['99999999999999999999', null],
            'whitespace around digits' => [' 12', null],
        ];
    }

    /** @dataProvider entries */
    public function testOnlyAPositiveIdIsAHit(mixed $stored, ?int $id): void
    {
        $cache = new MemcacheIdCache(
            static fn (string $key): mixed => $key === 'k' ? $stored : false,
            static fn (string $key, int $id, int $ttl): bool => true,
        );

        self::assertSame($id, $cache->get('k'));
    }

    /**
     * Through a decorator: an entry that is not an id is a miss, the
     * lookup runs, and its id replaces the entry.
     *
     * @dataProvider entries
     */
    public function testAnEntryThatIsNotAnIdIsLookedUpAgain(mixed $stored, ?int $id): void
    {
        $store = [md5('c1-id' . 'x' . 'ns') => $stored];
        $cache = new MemcacheIdCache(
            static function (string $key) use (&$store): mixed {
                return $store[$key] ?? false;
            },
            static function (string $key, int $value, int $ttl) use (&$store): bool {
                $store[$key] = $value;
                return true;
            },
        );
        $inner = new InMemoryTrackingRepository();
        $inner->findOrCreateC1('other');
        $innerId = $inner->findOrCreateC1('x');

        $answer = (new CachedTrackingRepository($inner, $cache, 'ns'))->findOrCreateC1('x');

        if ($id !== null) {
            self::assertSame($id, $answer, 'a hit is answered');
            self::assertSame($stored, $store[md5('c1-id' . 'x' . 'ns')], 'and left alone');
        } else {
            self::assertSame($innerId, $answer, 'a miss is looked up');
            self::assertSame($innerId, $store[md5('c1-id' . 'x' . 'ns')], 'and the found id kept');
        }
    }

    public function testAWriteCarriesTheTtl(): void
    {
        $writes = [];
        $write = static function (string $key, int $id, int $ttl) use (&$writes): bool {
            $writes[] = [$key, $id, $ttl];
            return true;
        };
        $read = static fn (string $key): mixed => false;

        (new MemcacheIdCache($read, $write))->set('a', 5);
        (new MemcacheIdCache($read, $write, 604800))->set('b', 6);
        (new MemcacheIdCache($read, $write))->set('c', 7, 86400);

        self::assertSame([['a', 5, 2592000], ['b', 6, 604800], ['c', 7, 86400]], $writes);
    }

    /** A refused write costs the next lookup a database read, nothing more. */
    public function testAWriteTheCacheRefusesIsNotAnError(): void
    {
        $cache = new MemcacheIdCache(
            static fn (string $key): mixed => false,
            static fn (string $key, int $id, int $ttl): bool => false,
        );

        $cache->set('a', 5);
        self::assertNull($cache->get('a'));
    }

    /** @return array<string, array{\Prosper202\Repository\Cache\IdCache}> */
    public static function caches(): array
    {
        return [
            'memcache' => [new MemcacheIdCache(
                static fn (string $key): mixed => false,
                static fn (string $key, int $id, int $ttl): bool => true,
            )],
            'null' => [new NullIdCache()],
            'array' => [new ArrayIdCache()],
        ];
    }

    /** @dataProvider caches */
    public function testOnlyARowIdIsKept(\Prosper202\Repository\Cache\IdCache $cache): void
    {
        $this->expectException(InvalidArgumentException::class);
        $cache->set('k', 0);
    }

    public function testTheNullCacheKeepsNothing(): void
    {
        $cache = new NullIdCache();
        $cache->set('k', 5);

        self::assertNull($cache->get('k'));
    }
}
