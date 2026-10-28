<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\Repository\PositiveId;

/**
 * Every 202_ips row stored for one address, found without writing one: an
 * IPv4 address by its text, an IPv6 one through 202_ips_v6 (its packed
 * bytes), as MysqlLocationRepository::findOrCreateIp() writes them. More
 * than one row can exist for one address (the writers did not always find
 * each other's rows), so a lookup takes all of them.
 *
 * A read, never a write: a lookup by an address the click path must not
 * store — the unmasked one, under privacy (FILTER::checkUserIP) — finds the
 * rows that are already there and creates none.
 */
final class StoredAddressIds
{
    private function __construct()
    {
    }

    /** @return list<int> */
    public static function of(Connection $conn, string $address): array
    {
        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return [];
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            // Joined as text: compared as numbers, the IPv4 row '1.0.0.5'
            // is 1 and was found for the IPv6 address whose v6 row is 1.
            $stmt = $conn->prepareRead(
                'SELECT 202_ips.ip_id FROM 202_ips_v6'
                . ' INNER JOIN 202_ips ON (202_ips.ip_address'
                . ' = CAST(202_ips_v6.ip_id AS CHAR CHARACTER SET utf8mb4)'
                . ' COLLATE utf8mb4_general_ci)'
                . ' WHERE 202_ips_v6.ip_address = ?'
            );
            $conn->bind($stmt, 's', [(string) inet_pton($address)]);
        } else {
            $stmt = $conn->prepareRead('SELECT ip_id FROM 202_ips WHERE ip_address = ?');
            $conn->bind($stmt, 's', [$address]);
        }

        return array_map(
            static fn (array $row): int => PositiveId::of($row['ip_id'] ?? null)
                ?? throw new QueryException('A 202_ips row without a row id'),
            $conn->fetchAll($stmt)
        );
    }
}
