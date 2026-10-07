<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;

/**
 * Every 202_ips row stored for one address, found without writing one: an
 * IPv4 address by its text, an IPv6 one through 202_ips_v6 (its packed
 * bytes), as findOrCreateIp() and INDEXES::get_ip_id() write them. More than
 * one row can exist for one address (the writers did not always find each
 * other's rows), so a lookup takes all of them.
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
            $stmt = $conn->prepareRead(
                'SELECT 202_ips.ip_id FROM 202_ips_v6'
                . ' INNER JOIN 202_ips ON (202_ips_v6.ip_id = 202_ips.ip_address COLLATE utf8mb4_general_ci)'
                . ' WHERE 202_ips_v6.ip_address = ?'
            );
            $conn->bind($stmt, 's', [(string) inet_pton($address)]);
        } else {
            $stmt = $conn->prepareRead('SELECT ip_id FROM 202_ips WHERE ip_address = ?');
            $conn->bind($stmt, 's', [$address]);
        }

        return array_map(static fn (array $row): int => (int) $row['ip_id'], $conn->fetchAll($stmt));
    }
}
