<?php

declare(strict_types=1);

namespace Prosper202\Repository\Mysql;

use Prosper202\Database\Connection;
use Prosper202\Repository\LocationRepositoryInterface;

final class MysqlLocationRepository implements LocationRepositoryInterface
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public function findOrCreateCountry(string $name, string $code): int
    {
        $stmt = $this->conn->prepareRead(
            'SELECT country_id FROM 202_locations_country WHERE country_code = ?'
        );
        $stmt->bind_param('s', $code);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['country_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_locations_country SET country_code = ?, country_name = ?'
        );
        $stmt->bind_param('ss', $code, $name);

        return $this->conn->executeInsert($stmt);
    }

    public function findOrCreateCity(string $name, int $countryId): int
    {
        $stmt = $this->conn->prepareRead(
            'SELECT city_id FROM 202_locations_city WHERE city_name = ? AND main_country_id = ?'
        );
        $stmt->bind_param('si', $name, $countryId);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['city_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_locations_city SET city_name = ?, main_country_id = ?'
        );
        $stmt->bind_param('si', $name, $countryId);

        return $this->conn->executeInsert($stmt);
    }

    public function findOrCreateRegion(string $name, int $countryId): int
    {
        $stmt = $this->conn->prepareRead(
            'SELECT region_id FROM 202_locations_region WHERE region_name = ? AND main_country_id = ?'
        );
        $stmt->bind_param('si', $name, $countryId);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['region_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_locations_region SET region_name = ?, main_country_id = ?'
        );
        $stmt->bind_param('si', $name, $countryId);

        return $this->conn->executeInsert($stmt);
    }

    public function findOrCreateIsp(string $name): int
    {
        $stmt = $this->conn->prepareRead(
            'SELECT isp_id FROM 202_locations_isp WHERE isp_name = ?'
        );
        $stmt->bind_param('s', $name);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['isp_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_locations_isp SET isp_name = ?'
        );
        $stmt->bind_param('s', $name);

        return $this->conn->executeInsert($stmt);
    }

    public function findOrCreateIp(string $address): int
    {
        if ($address === '') {
            return 0;
        }

        $isIpv6 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if ($isIpv6) {
            $encoded = function_exists('inet6_aton') ? inet6_aton($address) : inet_pton($address);

            $stmt = $this->conn->prepareRead(
                'SELECT 202_ips.ip_id FROM 202_ips_v6 '
                . 'INNER JOIN 202_ips ON (202_ips_v6.ip_id = 202_ips.ip_address COLLATE utf8mb4_general_ci) '
                . 'WHERE 202_ips_v6.ip_address = ? ORDER BY 202_ips.ip_id DESC LIMIT 1'
            );
            $stmt->bind_param('s', $encoded);
        } else {
            $stmt = $this->conn->prepareRead(
                'SELECT ip_id FROM 202_ips WHERE ip_address = ?'
            );
            $stmt->bind_param('s', $address);
        }

        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['ip_id'];
        }

        return $this->insertIp($address, $isIpv6);
    }

    public function findOrCreateSiteDomain(string $url): int
    {
        $host = self::extractDomainHost($url);

        if ($host === '') {
            return 0;
        }

        $stmt = $this->conn->prepareRead(
            'SELECT site_domain_id FROM 202_site_domains WHERE site_domain_host = ?'
        );
        $stmt->bind_param('s', $host);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['site_domain_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_site_domains SET site_domain_host = ?'
        );
        $stmt->bind_param('s', $host);

        return $this->conn->executeInsert($stmt);
    }

    public function findOrCreateSiteUrl(string $url): int
    {
        if ($url === '') {
            return 0;
        }

        $domainId = $this->findOrCreateSiteDomain($url);

        $stmt = $this->conn->prepareRead(
            'SELECT site_url_id FROM 202_site_urls WHERE site_domain_id = ? AND site_url_address = ? LIMIT 1'
        );
        $stmt->bind_param('is', $domainId, $url);
        $row = $this->conn->fetchOne($stmt);

        if ($row !== null) {
            return (int) $row['site_url_id'];
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_site_urls SET site_domain_id = ?, site_url_address = ?'
        );
        $stmt->bind_param('is', $domainId, $url);

        return $this->conn->executeInsert($stmt);
    }

    private function insertIp(string $address, bool $isIpv6): int
    {
        if ($isIpv6) {
            $encoded = function_exists('inet6_aton') ? inet6_aton($address) : inet_pton($address);

            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_ips SET ip_address = ?'
            );
            $stmt->bind_param('s', $address);
            $ipId = $this->conn->executeInsert($stmt);

            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_ips_v6 SET ip_address = ?, ip_id = ?'
            );
            $stmt->bind_param('si', $encoded, $ipId);
            $this->conn->execute($stmt);
            $stmt->close();

            return $ipId;
        }

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_ips SET ip_address = ?'
        );
        $stmt->bind_param('s', $address);

        return $this->conn->executeInsert($stmt);
    }

    public static function extractDomainHost(string $url): string
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
