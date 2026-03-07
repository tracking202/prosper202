<?php

declare(strict_types=1);

namespace Prosper202\Repository;

final class NullLocationRepository implements LocationRepositoryInterface
{
    public function findOrCreateCountry(string $name, string $code): int
    {
        return 0;
    }

    public function findOrCreateCity(string $name, int $countryId): int
    {
        return 0;
    }

    public function findOrCreateRegion(string $name, int $countryId): int
    {
        return 0;
    }

    public function findOrCreateIsp(string $name): int
    {
        return 0;
    }

    public function findOrCreateIp(string $address): int
    {
        return 0;
    }

    public function findOrCreateSiteDomain(string $url): int
    {
        return 0;
    }

    public function findOrCreateSiteUrl(string $url): int
    {
        return 0;
    }
}
