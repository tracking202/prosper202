<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cached;

use Prosper202\Repository\Cache\CachedIds;
use Prosper202\Repository\Cache\IdCache;
use Prosper202\Repository\LocationRepositoryInterface;
use Prosper202\Repository\Mysql\MysqlLocationRepository;

/**
 * Keys on the value as it is stored, so a name past its column and its cut
 * form share one entry, as they share one row.
 */
final readonly class CachedLocationRepository implements LocationRepositoryInterface
{
    private CachedIds $ids;

    public function __construct(
        private LocationRepositoryInterface $inner,
        IdCache $cache,
        string $namespace,
    ) {
        $this->ids = new CachedIds($cache, $namespace);
    }

    #[\Override]
    public function findOrCreateCountry(string $name, string $code): int
    {
        $code = MysqlLocationRepository::storedCountryCode($code);

        return $this->ids->remember(
            'country-id' . $code,
            fn (): int => $this->inner->findOrCreateCountry($name, $code)
        );
    }

    #[\Override]
    public function findOrCreateCity(string $name, int $countryId): int
    {
        $name = MysqlLocationRepository::storedName($name);

        return $this->ids->remember(
            'city-id' . $name . '|' . $countryId,
            fn (): int => $this->inner->findOrCreateCity($name, $countryId)
        );
    }

    #[\Override]
    public function findOrCreateRegion(string $name, int $countryId): int
    {
        $name = MysqlLocationRepository::storedName($name);

        return $this->ids->remember(
            'region-id' . $name . '|' . $countryId,
            fn (): int => $this->inner->findOrCreateRegion($name, $countryId)
        );
    }

    #[\Override]
    public function findOrCreateIsp(string $name): int
    {
        $name = MysqlLocationRepository::storedName($name);

        return $this->ids->remember(
            'isp-id' . $name,
            fn (): int => $this->inner->findOrCreateIsp($name)
        );
    }

    #[\Override]
    public function findOrCreateIp(string $address): int
    {
        if ($address === '') {
            return 0;
        }

        return $this->ids->remember(
            'ip-id' . $address,
            fn (): int => $this->inner->findOrCreateIp($address)
        );
    }

    #[\Override]
    public function findOrCreateSiteDomain(string $url): int
    {
        $host = MysqlLocationRepository::storedDomainHost($url);
        if ($host === '') {
            return 0;
        }

        return $this->ids->remember(
            'domain-id' . $host,
            fn (): int => $this->inner->findOrCreateSiteDomain($url)
        );
    }

    #[\Override]
    public function findOrCreateSiteUrl(string $url): int
    {
        if ($url === '') {
            return 0;
        }
        $url = MysqlLocationRepository::storedSiteUrl($url);

        return $this->ids->remember(
            'url-id' . $url,
            fn (): int => $this->inner->findOrCreateSiteUrl($url)
        );
    }
}
