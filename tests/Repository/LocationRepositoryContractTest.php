<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use Prosper202\Repository\Cached\CachedLocationRepository;
use Prosper202\Repository\InMemory\InMemoryLocationRepository;
use Prosper202\Repository\LocationRepositoryInterface;

final class LocationRepositoryContractTest extends TestCase
{
    /**
     * @return iterable<string, array{LocationRepositoryInterface}>
     */
    public static function implementations(): iterable
    {
        yield 'in-memory' => [new InMemoryLocationRepository()];
        yield 'cached' => [self::buildCached()];
    }

    private static function buildCached(): CachedLocationRepository
    {
        $store = [];

        return new CachedLocationRepository(
            new InMemoryLocationRepository(),
            static function (string $key) use (&$store) {
                return $store[$key] ?? false;
            },
            static function (string $key, mixed $value, int $ttl) use (&$store): void {
                $store[$key] = $value;
            },
            'test-hash',
        );
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateCountryReturnsStableId(LocationRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateCountry('United States', 'US');
        $id2 = $repo->findOrCreateCountry('United States', 'US');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testDifferentCountriesGetDifferentIds(LocationRepositoryInterface $repo): void
    {
        $us = $repo->findOrCreateCountry('United States', 'US');
        $uk = $repo->findOrCreateCountry('United Kingdom', 'GB');

        self::assertNotSame($us, $uk);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateCityReturnsStableId(LocationRepositoryInterface $repo): void
    {
        $countryId = $repo->findOrCreateCountry('United States', 'US');
        $id1 = $repo->findOrCreateCity('New York', $countryId);
        $id2 = $repo->findOrCreateCity('New York', $countryId);

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testSameCityDifferentCountryGetsDifferentId(LocationRepositoryInterface $repo): void
    {
        $us = $repo->findOrCreateCountry('United States', 'US');
        $uk = $repo->findOrCreateCountry('United Kingdom', 'GB');

        $cityUs = $repo->findOrCreateCity('Portland', $us);
        $cityUk = $repo->findOrCreateCity('Portland', $uk);

        self::assertNotSame($cityUs, $cityUk);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateRegionReturnsStableId(LocationRepositoryInterface $repo): void
    {
        $countryId = $repo->findOrCreateCountry('United States', 'US');
        $id1 = $repo->findOrCreateRegion('California', $countryId);
        $id2 = $repo->findOrCreateRegion('California', $countryId);

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateIspReturnsStableId(LocationRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateIsp('Comcast');
        $id2 = $repo->findOrCreateIsp('Comcast');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateIpReturnsStableId(LocationRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateIp('192.168.1.1');
        $id2 = $repo->findOrCreateIp('192.168.1.1');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateIpReturnsZeroForEmpty(LocationRepositoryInterface $repo): void
    {
        self::assertSame(0, $repo->findOrCreateIp(''));
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateSiteDomainReturnsStableId(LocationRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateSiteDomain('https://www.example.com/page');
        $id2 = $repo->findOrCreateSiteDomain('https://www.example.com/other');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2, 'Same domain should return same ID');
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateSiteUrlReturnsStableId(LocationRepositoryInterface $repo): void
    {
        $id1 = $repo->findOrCreateSiteUrl('https://example.com/page');
        $id2 = $repo->findOrCreateSiteUrl('https://example.com/page');

        self::assertGreaterThan(0, $id1);
        self::assertSame($id1, $id2);
    }

    /**
     * @dataProvider implementations
     */
    public function testFindOrCreateSiteUrlReturnsZeroForEmpty(LocationRepositoryInterface $repo): void
    {
        self::assertSame(0, $repo->findOrCreateSiteUrl(''));
    }
}
