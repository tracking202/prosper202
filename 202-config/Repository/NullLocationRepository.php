<?php

declare(strict_types=1);

namespace Prosper202\Repository;

final class NullLocationRepository implements LocationRepositoryInterface
{
    #[\Override]
    public function findOrCreateCountry(string $name, string $code): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateCity(string $name, int $countryId): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateRegion(string $name, int $countryId): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateIsp(string $name): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateIp(string $address): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateSiteDomain(string $url): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateSiteUrl(string $url): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateClickSiteUrl(string $url): int
    {
        return 0;
    }

    #[\Override]
    public function findOrCreateSiteUrlInDomain(int $domainId, string $url): int
    {
        return 0;
    }
}
