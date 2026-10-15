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

    /**
     * findOrCreateSiteUrl() for a URL built for one click: a cl.php or pci.php
     * link with its public id, an offer URL with its subid. Found or stored in
     * the database alone, as a cache entry for it would be written on every
     * click and read by none. An offer URL with no click in it is shared,
     * and costs its click this read uncached.
     */
    public function findOrCreateClickSiteUrl(string $url): int;
}
