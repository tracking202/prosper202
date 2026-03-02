<?php

declare(strict_types=1);

namespace Prosper202\Repository\InMemory;

use Prosper202\Repository\LocationRepositoryInterface;

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

    public function findOrCreateCountry(string $name, string $code): int
    {
        return $this->countries[$code] ??= $this->nextId++;
    }

    public function findOrCreateCity(string $name, int $countryId): int
    {
        $key = $name . '|' . $countryId;

        return $this->cities[$key] ??= $this->nextId++;
    }

    public function findOrCreateRegion(string $name, int $countryId): int
    {
        $key = $name . '|' . $countryId;

        return $this->regions[$key] ??= $this->nextId++;
    }

    public function findOrCreateIsp(string $name): int
    {
        return $this->isps[$name] ??= $this->nextId++;
    }

    public function findOrCreateIp(string $address): int
    {
        if ($address === '') {
            return 0;
        }

        return $this->ips[$address] ??= $this->nextId++;
    }

    public function findOrCreateSiteDomain(string $url): int
    {
        $host = $this->extractDomainHost($url);

        if ($host === '') {
            return 0;
        }

        return $this->siteDomains[$host] ??= $this->nextId++;
    }

    public function findOrCreateSiteUrl(string $url): int
    {
        if ($url === '') {
            return 0;
        }

        return $this->siteUrls[$url] ??= $this->nextId++;
    }

    private function extractDomainHost(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parsed = @parse_url($url);
        if ($parsed === false) {
            return '';
        }

        if (isset($parsed['host'])) {
            $host = trim($parsed['host']);
        } else {
            $parts = explode('/', $parsed['path'] ?? '', 2);
            $host = trim($parts[0]);
        }

        return str_replace('www.', '', $host);
    }
}
