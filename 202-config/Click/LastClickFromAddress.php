<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;

/**
 * "This visitor's last click": the fallback a pixel or redirect uses when the
 * request names no click (no subid, no cookie), found by the address the
 * click was stored under.
 *
 * Callers pass p202StoredVisitorIp(): the address the click path stores
 * (VisitorIp, masked under privacy), so the lookup compares like with like.
 * The five copies of this query (off.php, px.php, gpx.php, upx.php, rtr.php's
 * ?lpr=) each matched `202_ips.ip_address` against REMOTE_ADDR — or, in
 * rtr.php, the unmasked visitor address — so behind a CDN or load balancer
 * the pixel looked for the proxy's address, which no click is stored under,
 * and never found the click it fired for; under privacy it looked for an
 * unmasked address among masked rows. And 202_ips.ip_address holds an IPv6
 * click's 202_ips_v6 row id, not its address, so no IPv6 visitor ever
 * matched. The address is resolved the way findOrCreateIp() stores it.
 */
final class LastClickFromAddress
{
    private function __construct()
    {
    }

    /**
     * The visitor's most recent click for $userId since $since (a unix time),
     * or null when there is none.
     *
     * @return array{click_id: int, ppc_account_id: int, click_id_public: string, keyword_id: int}|null
     */
    public static function find(Connection $conn, string $address, int $userId, int $since): ?array
    {
        $ipIds = self::ipIds($conn, $address);
        if ($ipIds === []) {
            return null;
        }

        $in = implode(',', array_fill(0, count($ipIds), '?'));
        $stmt = $conn->prepareRead(
            'SELECT c.click_id, c.ppc_account_id, a.keyword_id, r.click_id_public'
            . ' FROM 202_clicks_advance AS a'
            . ' INNER JOIN 202_clicks AS c ON (c.click_id = a.click_id)'
            . ' LEFT JOIN 202_clicks_record AS r ON (r.click_id = c.click_id)'
            . ' WHERE a.ip_id IN (' . $in . ') AND c.user_id = ? AND c.click_time >= ?'
            . ' ORDER BY c.click_id DESC LIMIT 1'
        );
        $conn->bind($stmt, str_repeat('i', count($ipIds)) . 'ii', [...$ipIds, $userId, $since]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        return [
            'click_id' => (int) $row['click_id'],
            'ppc_account_id' => (int) ($row['ppc_account_id'] ?? 0),
            'click_id_public' => (string) ($row['click_id_public'] ?? ''),
            'keyword_id' => (int) ($row['keyword_id'] ?? 0),
        ];
    }

    /**
     * Every 202_ips row stored for $address: an IPv4 address by its text, an
     * IPv6 one through 202_ips_v6 (its packed bytes), as findOrCreateIp()
     * and INDEXES::get_ip_id() write them. More than one row can exist for
     * one address (the two writers did not always find each other's rows).
     *
     * @return list<int>
     */
    private static function ipIds(Connection $conn, string $address): array
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
