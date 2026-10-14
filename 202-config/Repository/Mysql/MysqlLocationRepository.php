<?php

declare(strict_types=1);

namespace Prosper202\Repository\Mysql;

use InvalidArgumentException;
use Prosper202\Database\Connection;
use Prosper202\Repository\LocationRepositoryInterface;
use Prosper202\Repository\StoredText;

/**
 * Every value is cut to its column before it is looked up: the server cuts
 * what it stores, so an uncut lookup of a long value never found its row
 * and stored another on every uncached click.
 */
final readonly class MysqlLocationRepository implements LocationRepositoryInterface
{
    public const COUNTRY_CODE_CHARS = 3;

    /** country_name, city_name, region_name and isp_name. */
    public const NAME_CHARS = 50;

    public const DOMAIN_HOST_CHARS = 100;

    /** site_url_address is a TEXT, which holds 65535 bytes. */
    public const SITE_URL_BYTES = 65535;

    private IdQuery $ids;

    public function __construct(private Connection $conn)
    {
        $this->ids = new IdQuery($conn);
    }

    public static function storedCountryCode(string $code): string
    {
        return StoredText::chars($code, self::COUNTRY_CODE_CHARS);
    }

    public static function storedName(string $name): string
    {
        return StoredText::chars($name, self::NAME_CHARS);
    }

    /** The site domain row a URL is filed under, as it is stored. */
    public static function storedDomainHost(string $url): string
    {
        $host = self::extractDomainHost($url);

        return $host === ''
            ? ''
            : StoredText::chars($host, self::DOMAIN_HOST_CHARS);
    }

    public static function storedSiteUrl(string $url): string
    {
        return StoredText::bytes($url, self::SITE_URL_BYTES);
    }

    /** An address filed through 202_ips_v6 rather than by its text. */
    public static function isIpv6(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    #[\Override]
    public function findOrCreateCountry(string $name, string $code): int
    {
        $code = self::storedCountryCode($code);

        return $this->ids->first(
            'SELECT country_id FROM 202_locations_country WHERE country_code = ?',
            's',
            [$code],
            'country_id'
        ) ?? $this->ids->insert(
            'INSERT INTO 202_locations_country SET country_code = ?, country_name = ?',
            'ss',
            [$code, self::storedName($name)]
        );
    }

    #[\Override]
    public function findOrCreateCity(string $name, int $countryId): int
    {
        $name = self::storedName($name);

        return $this->ids->first(
            'SELECT city_id FROM 202_locations_city'
                . ' WHERE city_name = ? AND main_country_id = ?',
            'si',
            [$name, $countryId],
            'city_id'
        ) ?? $this->ids->insert(
            'INSERT INTO 202_locations_city SET city_name = ?, main_country_id = ?',
            'si',
            [$name, $countryId]
        );
    }

    #[\Override]
    public function findOrCreateRegion(string $name, int $countryId): int
    {
        $name = self::storedName($name);

        return $this->ids->first(
            'SELECT region_id FROM 202_locations_region'
                . ' WHERE region_name = ? AND main_country_id = ?',
            'si',
            [$name, $countryId],
            'region_id'
        ) ?? $this->ids->insert(
            'INSERT INTO 202_locations_region SET region_name = ?, main_country_id = ?',
            'si',
            [$name, $countryId]
        );
    }

    #[\Override]
    public function findOrCreateIsp(string $name): int
    {
        $name = self::storedName($name);

        return $this->ids->first(
            'SELECT isp_id FROM 202_locations_isp WHERE isp_name = ?',
            's',
            [$name],
            'isp_id'
        ) ?? $this->ids->insert(
            'INSERT INTO 202_locations_isp SET isp_name = ?',
            's',
            [$name]
        );
    }

    /**
     * An IPv4 address is a 202_ips row by its text. An IPv6 one is a
     * 202_ips_v6 row by its packed bytes, plus a 202_ips row whose
     * ip_address is that row's id written in decimal; the two are joined as
     * text, because compared as numbers the IPv4 row '1.0.0.5' is 1.
     *
     * location_id is written, not left to its default: the 1.9.51 rung made
     * 202_ips_v6.location_id with no default, which a strict sql_mode refuses
     * to leave out.
     *
     * A new IPv6 address's two rows are one transaction: a 202_ips_v6 row
     * whose 202_ips row was refused is found by no lookup, so every click
     * from the address would add another. Not atomic against another click:
     * nothing unique stands behind either address column, so concurrent
     * first clicks from one new address can each insert a pair; each click
     * names a pair that is found again. Its callers look the address up
     * outside any transaction of theirs (the error pages end the caller's
     * first), which a nested begin would commit.
     */
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
        if (!self::isIpv6($address)) {
            return $this->ids->first(
                'SELECT ip_id FROM 202_ips WHERE ip_address = ?',
                's',
                [$address],
                'ip_id'
            ) ?? $this->ids->insert(
                'INSERT INTO 202_ips SET ip_address = ?, location_id = 0',
                's',
                [$address]
            );
        }

        $packed = self::packed($address);
        $found = $this->ids->first(
            'SELECT 202_ips.ip_id FROM 202_ips_v6'
                . ' INNER JOIN 202_ips ON (202_ips.ip_address'
                . ' = CAST(202_ips_v6.ip_id AS CHAR CHARACTER SET utf8mb4)'
                . ' COLLATE utf8mb4_general_ci)'
                . ' WHERE 202_ips_v6.ip_address = ?'
                . ' ORDER BY 202_ips.ip_id DESC LIMIT 1',
            's',
            [$packed],
            'ip_id'
        );
        if ($found !== null) {
            return $found;
        }

        return $this->conn->transaction(function () use ($packed): int {
            $v6Id = $this->ids->insert(
                'INSERT INTO 202_ips_v6 SET ip_address = ?, location_id = 0',
                's',
                [$packed]
            );

            return $this->ids->insert(
                'INSERT INTO 202_ips SET ip_address = ?, location_id = 0',
                's',
                [(string) $v6Id]
            );
        });
    }

    #[\Override]
    public function findOrCreateSiteDomain(string $url): int
    {
        $host = self::storedDomainHost($url);
        if ($host === '') {
            return 0;
        }

        return $this->ids->first(
            'SELECT site_domain_id FROM 202_site_domains WHERE site_domain_host = ?',
            's',
            [$host],
            'site_domain_id'
        ) ?? $this->ids->insert(
            'INSERT INTO 202_site_domains SET site_domain_host = ?',
            's',
            [$host]
        );
    }

    /**
     * Not atomic: nothing unique stands behind the address (the table is
     * partitioned by site_url_id, which every unique key would then have to
     * include), so concurrent first clicks on one new URL can each insert a
     * row. A URL with no host is filed under site domain 0.
     */
    #[\Override]
    public function findOrCreateSiteUrl(string $url): int
    {
        if ($url === '') {
            return 0;
        }
        $url = self::storedSiteUrl($url);

        $domainId = $this->findOrCreateSiteDomain($url);

        return $this->ids->first(
            'SELECT site_url_id FROM 202_site_urls'
                . ' WHERE site_domain_id = ? AND site_url_address = ? LIMIT 1',
            'is',
            [$domainId, $url],
            'site_url_id'
        ) ?? $this->ids->insert(
            'INSERT INTO 202_site_urls SET site_domain_id = ?, site_url_address = ?',
            'is',
            [$domainId, $url]
        );
    }

    public static function extractDomainHost(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parsed = parse_url($url);
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

    private static function packed(string $address): string
    {
        $packed = inet_pton($address);
        if ($packed === false) {
            throw new InvalidArgumentException(
                'Not an IP address: "' . $address . '"'
            );
        }

        return $packed;
    }
}
