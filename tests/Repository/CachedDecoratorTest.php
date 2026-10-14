<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Prosper202\Repository\Cached\CachedDeviceRepository;
use Prosper202\Repository\Cached\CachedLocationRepository;
use Prosper202\Repository\Cached\CachedTrackingRepository;
use Prosper202\Repository\DeviceRepositoryInterface;
use Prosper202\Repository\DeviceType;
use Prosper202\Repository\InMemory\InMemoryDeviceRepository;
use Prosper202\Repository\InMemory\InMemoryLocationRepository;
use Prosper202\Repository\InMemory\InMemoryTrackingRepository;
use Prosper202\Repository\NullLocationRepository;
use Prosper202\Repository\NullTrackingRepository;
use Prosper202\Repository\TrackingRepositoryInterface;
use RuntimeException;

/**
 * The cached repositories read through an IdCache. Their keys are the ones
 * the click path has always written to memcache, byte for byte, so entries
 * already there keep answering after an upgrade; an IPv6 address's is the
 * one exception, since the old lookup's answers for it are not trusted. The
 * device keys were never written before 1.9.78 (the click path cached only
 * a whole agent's parse), and a device model's carries its type.
 */
final class CachedDecoratorTest extends TestCase
{
    public function testCacheHitSkipsInner(): void
    {
        $innerCalls = 0;
        $delegate = new InMemoryDeviceRepository();
        $inner = new class ($innerCalls, $delegate) implements DeviceRepositoryInterface {
            public function __construct(private int &$calls, private DeviceRepositoryInterface $delegate)
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

            public function findOrCreateDevice(string $name, DeviceType $type): int
            {
                return $this->delegate->findOrCreateDevice($name, $type);
            }
        };

        $cached = new CachedDeviceRepository($inner, new ArrayIdCache(), 'test-hash');

        $id1 = $cached->findOrCreateBrowser('Chrome');
        self::assertSame(1, $innerCalls, 'First call should hit inner');

        $id2 = $cached->findOrCreateBrowser('Chrome');
        self::assertSame(1, $innerCalls, 'Second call should use cache, not inner');

        self::assertSame($id1, $id2);
    }

    /** @return iterable<string, array{string, string, list<int|string|DeviceType>, string}> */
    public static function keys(): iterable
    {
        yield 'keyword' => ['tracking', 'findOrCreateKeyword', ['buy shoes'], 'keyword-idbuy shoes'];
        yield 'c1' => ['tracking', 'findOrCreateC1', ['a'], 'c1-ida'];
        yield 'c2' => ['tracking', 'findOrCreateC2', ['a'], 'c2-ida'];
        yield 'c3' => ['tracking', 'findOrCreateC3', ['a'], 'c3-ida'];
        yield 'c4' => ['tracking', 'findOrCreateC4', ['a'], 'c4-ida'];
        yield 'variable' => ['tracking', 'findOrCreateVariable', ['my-value', 42], 'variable-id42|my-value'];
        yield 'variable set' => ['tracking', 'findOrCreateVariableSet', ['1,2'], 'variable_set1,2'];
        yield 'custom var' => ['tracking', 'findOrCreateCustomVar', ['sub_id', 'abc123'], 'customvar-sub_id|abc123'];
        yield 'utm' => ['tracking', 'findOrCreateUtm', ['google', 'utm_source'], 'utm-utm_source|google'];
        yield 'country' => ['location', 'findOrCreateCountry', ['United States', 'US'], 'country-idUS'];
        yield 'city' => ['location', 'findOrCreateCity', ['Berlin', 12], 'city-idBerlin|12'];
        yield 'region' => ['location', 'findOrCreateRegion', ['Bavaria', 12], 'region-idBavaria|12'];
        yield 'isp' => ['location', 'findOrCreateIsp', ['Comcast'], 'isp-idComcast'];
        yield 'ip' => ['location', 'findOrCreateIp', ['192.0.2.1'], 'ip-id192.0.2.1'];
        yield 'domain' => ['location', 'findOrCreateSiteDomain', ['https://www.example.com/a'], 'domain-idexample.com'];
        yield 'url' => ['location', 'findOrCreateSiteUrl', ['https://example.com/a'], 'url-idhttps://example.com/a'];
        yield 'browser' => ['device', 'findOrCreateBrowser', ['Chrome'], 'browser-idChrome'];
        yield 'platform' => ['device', 'findOrCreatePlatform', ['Windows'], 'platform-idWindows'];
        yield 'device' => ['device', 'findOrCreateDevice', ['Desktop', DeviceType::Desktop], 'device-idDesktop|1'];
    }

    /**
     * @dataProvider keys
     * @param list<int|string|DeviceType> $args
     */
    public function testTheKeyIsTheOneMemcacheAlreadyHolds(
        string $kind,
        string $method,
        array $args,
        string $subject
    ): void {
        $cache = new ArrayIdCache();
        $repo = match ($kind) {
            'tracking' => new CachedTrackingRepository(new InMemoryTrackingRepository(), $cache, 'fakehash'),
            'location' => new CachedLocationRepository(new InMemoryLocationRepository(), $cache, 'fakehash'),
            'device' => new CachedDeviceRepository(new InMemoryDeviceRepository(), $cache, 'fakehash'),
        };

        $repo->{$method}(...$args);

        self::assertSame([md5($subject . 'fakehash')], $cache->reads);
        self::assertSame([md5($subject . 'fakehash')], $cache->writtenKeys());
    }

    /**
     * The lookup that wrote 'ip-id' entries joined 202_ips_v6 to 202_ips as
     * numbers, so the entry for 2001:db8::1 (v6 row 1) could hold the row of
     * the IPv4 visitor 1.0.0.5. An IPv6 address is keyed under 'ip6-id' and
     * found by the text join; an IPv4 entry was always a text compare and
     * still answers.
     */
    public function testAnIpv6EntryTheOldLookupWroteIsNotServed(): void
    {
        $cache = new ArrayIdCache();
        $cache->ids[md5('ip-id' . '2001:db8::1' . 'h')] = 999;
        $cache->ids[md5('ip-id' . '192.0.2.1' . 'h')] = 77;
        $inner = new InMemoryLocationRepository();
        $cached = new CachedLocationRepository($inner, $cache, 'h');

        $v6 = $cached->findOrCreateIp('2001:db8::1');

        self::assertNotSame(999, $v6, 'the old lookup\'s entry was served');
        self::assertSame($inner->findOrCreateIp('2001:db8::1'), $v6);
        self::assertSame([md5('ip6-id' . '2001:db8::1' . 'h')], $cache->reads);
        self::assertSame([[md5('ip6-id' . '2001:db8::1' . 'h'), $v6]], $cache->writes);
        self::assertSame($v6, $cached->findOrCreateIp('2001:db8::1'), 'the new entry answers');
        self::assertSame(77, $cached->findOrCreateIp('192.0.2.1'));
    }

    public function testEmptyValuesSkipCacheAndReturnZero(): void
    {
        $cache = new ArrayIdCache();
        $device = new CachedDeviceRepository(new InMemoryDeviceRepository(), $cache, 'test-hash');
        $location = new CachedLocationRepository(new InMemoryLocationRepository(), $cache, 'test-hash');
        $tracking = new CachedTrackingRepository(new InMemoryTrackingRepository(), $cache, 'test-hash');

        self::assertSame(0, $device->findOrCreateBrowser(''));
        self::assertSame(0, $device->findOrCreatePlatform(''));
        self::assertSame(0, $device->findOrCreateDevice('', DeviceType::Mobile));
        self::assertSame(0, $location->findOrCreateIp(''));
        self::assertSame(0, $location->findOrCreateSiteDomain(''));
        self::assertSame(0, $location->findOrCreateSiteUrl(''));
        self::assertSame(0, $tracking->findOrCreateKeyword(''));
        self::assertSame([], $cache->reads, 'An empty value is no lookup');
        self::assertSame([], $cache->writes, 'Should not cache zero results');
    }

    /** An inner answer of 0 is "no value", never a row: it is answered, not kept. */
    public function testAZeroFromTheInnerRepositoryIsNotCached(): void
    {
        $cache = new ArrayIdCache();
        $tracking = new CachedTrackingRepository(new NullTrackingRepository(), $cache, 'h');
        $location = new CachedLocationRepository(new NullLocationRepository(), $cache, 'h');

        self::assertSame(0, $tracking->findOrCreateC1('x'));
        self::assertSame(0, $tracking->findOrCreateUtm('x', 'utm_source'));
        self::assertSame(0, $location->findOrCreateCountry('X', 'XX'));
        self::assertSame(0, $location->findOrCreateIp('192.0.2.1'));
        self::assertCount(4, $cache->reads);
        self::assertSame([], $cache->writes);
    }

    public function testALookupThatThrowsCachesNothing(): void
    {
        $cache = new ArrayIdCache();
        $inner = new class () implements TrackingRepositoryInterface {
            public function findOrCreateKeyword(string $name): int
            {
                throw new RuntimeException('the database refused');
            }

            public function findOrCreateC1(string $value): int
            {
                return 1;
            }

            public function findOrCreateC2(string $value): int
            {
                return 1;
            }

            public function findOrCreateC3(string $value): int
            {
                return 1;
            }

            public function findOrCreateC4(string $value): int
            {
                return 1;
            }

            public function findOrCreateVariable(string $value, int $ppcVariableId): int
            {
                return 1;
            }

            public function findOrCreateVariableSet(string $variables): int
            {
                return 1;
            }

            public function findOrCreateCustomVar(string $name, string $data): int
            {
                return 1;
            }

            public function findOrCreateUtm(string $value, string $type): int
            {
                return 1;
            }
        };

        try {
            (new CachedTrackingRepository($inner, $cache, 'h'))->findOrCreateKeyword('k');
            self::fail('The inner repository\'s failure was not passed on');
        } catch (RuntimeException $e) {
            self::assertSame('the database refused', $e->getMessage());
        }
        self::assertSame([], $cache->writes);
    }
}
