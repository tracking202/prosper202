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
 *
 * A masked address names a /24 (a /48 for IPv6), not a visitor: an office,
 * a carrier's block. Under privacy the click path sets no cookie, so every
 * pixel there reaches this fallback, and "the latest click from the /24"
 * credited a sale, its campaign and its keyword to whichever neighbour
 * clicked last. Before the lookup compared like with like it found nothing
 * under privacy at all. Now a masked address answers only when one click in
 * the window matches it, which no neighbour can share; two or more are
 * nobody's to choose between, and the miss is logged (CLAUDE.md #17, #30).
 */
final class LastClickFromAddress
{
    private function __construct()
    {
    }

    /**
     * The visitor's most recent click for $userId since $since (a unix time),
     * or null when there is none. $masked says $address is a masked one
     * (p202StoredVisitorIp() under privacy: pass !trackingEnabled()); then a
     * click is answered only when it is the one click in the window.
     *
     * @return array{click_id: int, ppc_account_id: int, click_id_public: string, keyword_id: int}|null
     */
    public static function find(Connection $conn, string $address, int $userId, int $since, bool $masked): ?array
    {
        $ipIds = StoredAddressIds::of($conn, $address);
        if ($ipIds === []) {
            return null;
        }

        $in = implode(',', array_fill(0, count($ipIds), '?'));
        $stmt = $conn->prepareRead(
            // A re-click gives a click another 202_clicks row: one click, not two.
            'SELECT c.click_id, MAX(c.ppc_account_id) AS ppc_account_id, MAX(a.keyword_id) AS keyword_id,'
            . ' MAX(r.click_id_public) AS click_id_public'
            . ' FROM 202_clicks_advance AS a'
            . ' INNER JOIN 202_clicks AS c ON (c.click_id = a.click_id)'
            . ' LEFT JOIN 202_clicks_record AS r ON (r.click_id = c.click_id)'
            . ' WHERE a.ip_id IN (' . $in . ') AND c.user_id = ? AND c.click_time >= ?'
            . ' GROUP BY c.click_id ORDER BY c.click_id DESC LIMIT 2'
        );
        $conn->bind($stmt, str_repeat('i', count($ipIds)) . 'ii', [...$ipIds, $userId, $since]);
        $rows = $conn->fetchAll($stmt);
        if ($rows === []) {
            return null;
        }
        if ($masked && count($rows) > 1) {
            error_log('p202 last click by address: more than one of account ' . $userId
                . '\'s clicks since ' . $since . ' is from the masked address ' . $address
                . '; it names a block, not a visitor, so no click is credited');

            return null;
        }
        $row = $rows[0];

        return [
            'click_id' => (int) $row['click_id'],
            'ppc_account_id' => (int) ($row['ppc_account_id'] ?? 0),
            'click_id_public' => (string) ($row['click_id_public'] ?? ''),
            'keyword_id' => (int) ($row['keyword_id'] ?? 0),
        ];
    }
}
