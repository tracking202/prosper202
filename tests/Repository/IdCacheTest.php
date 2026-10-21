<?php

declare(strict_types=1);

namespace Tests\Repository;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\Repository\Cache\CachedIds;
use Prosper202\Repository\Cache\MemcacheIdCache;
use Prosper202\Repository\Cache\MemcacheRowCache;
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

    /** @return array<string, array{int}> */
    public static function lifetimesMemcacheMisreads(): array
    {
        return [
            'zero (never expire)' => [0],
            'negative' => [-1],
            'past 30 days (a Unix time)' => [2592001],
        ];
    }

    /**
     * A lifetime memcache would read as "never expire" or as a time long
     * past is refused by every cache, before anything is written.
     *
     * @dataProvider lifetimesMemcacheMisreads
     */
    public function testALifetimeMemcacheWouldMisreadIsRefused(int $ttl): void
    {
        $writes = [];
        $write = static function (string $key, mixed $value, int $ttl) use (&$writes): bool {
            $writes[] = $ttl;
            return true;
        };
        $read = static fn (string $key): mixed => false;
        $refusals = 0;
        $attempts = [
            static fn () => (new MemcacheIdCache($read, $write))->set('k', 5, $ttl),
            static fn () => new MemcacheIdCache($read, $write, $ttl),
            static fn () => new MemcacheRowCache($read, $write, $ttl),
            static fn () => (new NullIdCache())->set('k', 5, $ttl),
            static fn () => (new ArrayIdCache())->set('k', 5, $ttl),
            static fn () => (new CachedIds(new MemcacheIdCache($read, $write), 'ns'))
                ->remember('subject', static fn (): int => 9, $ttl),
        ];
        foreach ($attempts as $i => $attempt) {
            try {
                $attempt();
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString("not $ttl", $e->getMessage(), "attempt $i");
                $refusals++;
            }
        }

        self::assertSame(count($attempts), $refusals);
        self::assertSame([], $writes, 'nothing written');
    }

    public function testTheLifetimesAtTheEdgesAreKept(): void
    {
        $writes = [];
        $write = static function (string $key, int $id, int $ttl) use (&$writes): bool {
            $writes[] = $ttl;
            return true;
        };
        $cache = new MemcacheIdCache(static fn (string $key): mixed => false, $write, 1);
        $cache->set('a', 5);
        $cache->set('b', 5, 2592000);

        self::assertSame([1, 2592000], $writes);
    }

    public function testTheNullCacheKeepsNothing(): void
    {
        $cache = new NullIdCache();
        $cache->set('k', 5);

        self::assertNull($cache->get('k'));
    }
}
