<?php

declare(strict_types=1);

namespace Prosper202\Repository\InMemory;

use InvalidArgumentException;
use Prosper202\Repository\LocationRepositoryInterface;
use Prosper202\Repository\Mysql\MysqlLocationRepository;

/** Stores as the MySQL repository does: each value cut to its column. */
final class InMemoryLocationRepository implements LocationRepositoryInterface
{
    /** @var array<string, int> */
    private array $countries = [];
    /** @var array<string, int> */
    private array $cities = [];
    /** @var array<string, int> */
    private array $regions = [];
    /** @var array<string, int> */
    private array $isps = [];
    /** @var array<string, int> */
    private array $ips = [];
    /** @var array<string, int> */
    private array $siteDomains = [];
    /** @var array<string, int> */
    private array $siteUrls = [];
    private int $nextId = 1;

    #[\Override]
    public function findOrCreateCountry(string $name, string $code): int
    {
        $code = MysqlLocationRepository::storedCountryCode($code);

        return $this->countries[$code] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateCity(string $name, int $countryId): int
    {
        $key = MysqlLocationRepository::storedName($name) . '|' . $countryId;

        return $this->cities[$key] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateRegion(string $name, int $countryId): int
    {
        $key = MysqlLocationRepository::storedName($name) . '|' . $countryId;

        return $this->regions[$key] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateIsp(string $name): int
    {
        $name = MysqlLocationRepository::storedName($name);

        return $this->isps[$name] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateIp(string $address): int
    {
        if ($address === '') {
            return 0;
        }
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException(
                'Not an IP address: "' . $address . '"'
            );
        }

        return $this->ips[$address] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateSiteDomain(string $url): int
    {
        $host = MysqlLocationRepository::storedDomainHost($url);
        if ($host === '') {
            return 0;
        }

        return $this->siteDomains[$host] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateSiteUrl(string $url): int
    {
        if ($url === '') {
            return 0;
        }
        $url = MysqlLocationRepository::storedSiteUrl($url);

        return $this->siteUrls[$url] ??= $this->nextId++;
    }

    #[\Override]
    public function findOrCreateClickSiteUrl(string $url): int
    {
        return $this->findOrCreateSiteUrl($url);
    }
}
