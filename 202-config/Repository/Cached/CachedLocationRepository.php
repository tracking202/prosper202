<?php

declare(strict_types=1);

namespace Prosper202\Repository\Cached;

use Closure;
use Prosper202\Repository\LocationRepositoryInterface;
use Prosper202\Repository\Mysql\MysqlLocationRepository;

final class CachedLocationRepository implements LocationRepositoryInterface
{
    private const TTL = 2592000;

    /**
     * @param Closure(string): mixed $cacheGet  getCache($key)
     * @param Closure(string, mixed, int): void $cacheSet  setCache($key, $value, $ttl)
     */
    public function __construct(
        private LocationRepositoryInterface $inner,
        private Closure $cacheGet,
        private Closure $cacheSet,
        private string $systemHash,
    ) {
    }

    public function findOrCreateCountry(string $name, string $code): int
    {
        $key = md5('country-id' . $code . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateCountry($name, $code);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateCity(string $name, int $countryId): int
    {
        $key = md5('city-id' . $name . '|' . $countryId . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateCity($name, $countryId);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateRegion(string $name, int $countryId): int
    {
        $key = md5('region-id' . $name . '|' . $countryId . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateRegion($name, $countryId);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateIsp(string $name): int
    {
        $key = md5('isp-id' . $name . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateIsp($name);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateIp(string $address): int
    {
        if ($address === '') {
            return 0;
        }

        $key = md5('ip-id' . $address . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateIp($address);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateSiteDomain(string $url): int
    {
        $host = MysqlLocationRepository::extractDomainHost($url);

        if ($host === '') {
            return 0;
        }

        $key = md5('domain-id' . $host . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateSiteDomain($url);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

    public function findOrCreateSiteUrl(string $url): int
    {
        if ($url === '') {
            return 0;
        }

        $key = md5('url-id' . $url . $this->systemHash);

        $cached = ($this->cacheGet)($key);
        if ($cached !== false) {
            return (int) $cached;
        }

        $id = $this->inner->findOrCreateSiteUrl($url);
        ($this->cacheSet)($key, $id, self::TTL);

        return $id;
    }

}
