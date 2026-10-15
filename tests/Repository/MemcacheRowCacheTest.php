<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Prosper202\Repository\Cache\MemcacheRowCache;

/**
 * A setup row kept in memcache comes back exactly as it was kept, a kept
 * "no such row" comes back as that, and anything else under the key is a
 * miss: the read that follows asks the database rather than trusting it.
 */
final class MemcacheRowCacheTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** @var list<int> */
    private array $ttls = [];

    private function cache(): MemcacheRowCache
    {
        return new MemcacheRowCache(
            fn (string $key): mixed => $this->store[$key] ?? false,
            function (string $key, string $entry, int $ttl): bool {
                $this->store[$key] = $entry;
                $this->ttls[] = $ttl;

                return true;
            },
        );
    }

    public function testARowComesBackAsItWasKept(): void
    {
        $row = ['user_id' => '7', 'aff_campaign_url' => 'https://offer.example/?a=1&b=é', 'parameters' => null];
        $this->cache()->set('k', $row);

        self::assertSame($row, $this->cache()->get('k')?->row);
        self::assertSame([180], $this->ttls, 'the three minutes memcache_mysql_fetch_assoc() kept a row for');
    }

    public function testNoSuchRowIsKeptAsThat(): void
    {
        $this->cache()->set('k', null);

        $hit = $this->cache()->get('k');
        self::assertNotNull($hit);
        self::assertNull($hit->row);
    }

    public function testARowJsonCannotHoldIsNotKept(): void
    {
        // A connection whose character set is not UTF-8 hands text back in
        // its own bytes: the row is read from the database every time.
        $this->cache()->set('k', ['aff_campaign_url' => "https://offer.example/caf\xE9"]);

        self::assertSame([], $this->store);
        self::assertNull($this->cache()->get('k'));
    }

    public function testNothingKeptIsAMiss(): void
    {
        self::assertNull($this->cache()->get('absent'));
    }

    /** @return iterable<string, array{mixed}> */
    public static function untrusted(): iterable
    {
        yield 'not text' => [123];
        yield 'an array' => [['row' => null]];
        yield 'not JSON' => ['{"row":'];
        yield 'serialize()d, as memcache_mysql_fetch_assoc() kept rows' => [serialize(['user_id' => '7'])];
        yield 'no row key' => ['{"other":null}'];
        yield 'another key beside it' => ['{"row":null,"x":1}'];
        yield 'a list' => ['{"row":["7"]}'];
        yield 'an empty row' => ['{"row":{}}'];
        yield 'a number' => ['{"row":{"user_id":7}}'];
        yield 'a nested value' => ['{"row":{"user_id":{"a":"b"}}}'];
        yield 'a scalar row' => ['{"row":"7"}'];
        yield 'nested too deep' => ['{"row":{"a":{"b":{"c":"d"}}}}'];
    }

    /** @dataProvider untrusted */
    public function testAnythingElseIsAMiss(mixed $entry): void
    {
        $this->store['k'] = $entry;

        self::assertNull($this->cache()->get('k'));
    }
}
