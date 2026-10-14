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
        $cache = new ArrayIdCache();
        $cached = new CachedLocationRepository(new InMemoryLocationRepository(), $cache, 'fakehash');

        $cached->findOrCreateCity('Berlin', 12);
        $cached->findOrCreateCity('Berlin1', 2);

        self::assertCount(2, $cache->writes);
        self::assertNotSame($cache->writes[0][0], $cache->writes[1][0]);
    }

    public function testRegionKeyUsesDelimiterToPreventCollisions(): void
    {
        $cache = new ArrayIdCache();
        $cached = new CachedLocationRepository(new InMemoryLocationRepository(), $cache, 'fakehash');

        $cached->findOrCreateRegion('Berlin', 12);
        $cached->findOrCreateRegion('Berlin1', 2);

        self::assertCount(2, $cache->writes);
        self::assertNotSame($cache->writes[0][0], $cache->writes[1][0]);
    }

    public function testVariableKeyUsesDelimiterToPreventCollisions(): void
    {
        $cache = new ArrayIdCache();
        $cached = new CachedTrackingRepository(new InMemoryTrackingRepository(), $cache, 'fakehash');

        $cached->findOrCreateVariable('2foo', 1);
        $cached->findOrCreateVariable('foo', 12);

        self::assertCount(2, $cache->writes);
        self::assertNotSame($cache->writes[0][0], $cache->writes[1][0]);
    }

    public function testLongTrackingValueUsesTruncatedKey(): void
    {
        $cache = new ArrayIdCache();
        $cached = new CachedTrackingRepository(new InMemoryTrackingRepository(), $cache, 'fakehash');

        $idLong = $cached->findOrCreateC1(str_repeat('a', 351));
        $idTruncated = $cached->findOrCreateC1(str_repeat('a', 350));

        self::assertSame($idLong, $idTruncated);
        self::assertCount(1, $cache->writes);
    }

    /** A name is stored cut to its column, so its key is the cut one too. */
    public function testLongLocationNamesUseTheirStoredKeys(): void
    {
        $cache = new ArrayIdCache();
        $cached = new CachedLocationRepository(new InMemoryLocationRepository(), $cache, 'fakehash');

        $city = $cached->findOrCreateCity(str_repeat('c', 60), 7);
        self::assertSame($city, $cached->findOrCreateCity(str_repeat('c', 50), 7));
        $isp = $cached->findOrCreateIsp(str_repeat('i', 51));
        self::assertSame($isp, $cached->findOrCreateIsp(str_repeat('i', 50)));
        $country = $cached->findOrCreateCountry('Name', 'USAX');
        self::assertSame($country, $cached->findOrCreateCountry('Name', 'USA'));
        $host = str_repeat('h', 100);
        $domain = $cached->findOrCreateSiteDomain('https://' . $host . 'tail.example/x');
        self::assertSame($domain, $cached->findOrCreateSiteDomain('https://' . $host . '/y'));

        self::assertSame([
            md5('city-id' . str_repeat('c', 50) . '|7' . 'fakehash'),
            md5('isp-id' . str_repeat('i', 50) . 'fakehash'),
            md5('country-id' . 'USA' . 'fakehash'),
            md5('domain-id' . $host . 'fakehash'),
        ], $cache->writtenKeys());
    }
}
