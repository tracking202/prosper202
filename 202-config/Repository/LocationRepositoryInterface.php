<?php

declare(strict_types=1);

namespace Prosper202\Repository;

interface LocationRepositoryInterface
{
    public function findOrCreateCountry(string $name, string $code): int;

    public function findOrCreateCity(string $name, int $countryId): int;

    public function findOrCreateRegion(string $name, int $countryId): int;

    public function findOrCreateIsp(string $name): int;

    public function findOrCreateIp(string $address): int;

    public function findOrCreateSiteDomain(string $url): int;

    public function findOrCreateSiteUrl(string $url): int;
}
