<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\InboundLog\InboundLog;

/**
 * Who would be refused if the account required more than it does: the
 * account's postbacks and pixels of the last WINDOW_DAYS, by what each
 * presented (202_inbound_log.auth), grouped by endpoint and the campaign of
 * the conversion it recorded. Account › API Integrations shows it beside
 * the choice, and GET /conversions/postback-auth/readiness answers it.
 *
 * "Safe" is said only of requests that were seen: with none in the window,
 * or with some whose credentials were not read, the answer is null, never
 * true. The log keeps a number of requests, not a number of days, so a busy
 * account's window can be shorter than WINDOW_DAYS; covers_since says how far
 * back the answer reaches.
 */
final class PostbackAuthReadiness
{
    public const WINDOW_DAYS = 7;
    public const MAX_SENDERS = 50;

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @return array{
     *     window_days: int, since: int, covers_since: int, requests: int, unread: int,
     *     without_credentials: int, token_only: int, signed: int, pixels: int,
     *     safe_to_require_token: bool|null, safe_to_require_signature: bool|null,
     *     senders: list<array{endpoint: string, presented: string, campaign_id: int|null, campaign_name: string|null,
     *         network_name: string|null, requests: int, conversions: int, last_received_at: int}>,
     *     more_senders: int
     * }
     */
    public function forAccount(int $userId, int $now): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('PostbackAuthReadiness: an account id is required');
        }
        $since = $now - self::WINDOW_DAYS * 86400;
        $in = "'" . implode("', '", InboundLog::POSTBACK_ENDPOINTS) . "'";

        $stmt = $this->conn->prepareRead(
            'SELECT l.endpoint, l.auth, cl.campaign_id, ac.aff_campaign_name, an.aff_network_name,'
            . ' COUNT(*) AS requests, SUM(l.conversion_id IS NOT NULL) AS conversions, MAX(l.received_at) AS last_received_at'
            . ' FROM ' . TableRegistry::INBOUND_LOG . ' AS l'
            . ' LEFT JOIN 202_conversion_logs AS cl ON (cl.conv_id = l.conversion_id AND cl.user_id = l.user_id)'
            . ' LEFT JOIN 202_aff_campaigns AS ac ON (ac.aff_campaign_id = cl.campaign_id AND ac.user_id = l.user_id)'
            . ' LEFT JOIN 202_aff_networks AS an ON (an.aff_network_id = ac.aff_network_id AND an.user_id = l.user_id)'
            . ' WHERE l.user_id = ? AND l.received_at >= ? AND l.endpoint IN (' . $in . ')'
            . ' GROUP BY l.endpoint, l.auth, cl.campaign_id, ac.aff_campaign_name, an.aff_network_name'
            . ' ORDER BY requests DESC, last_received_at DESC'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $since]);

        $counts = ['' => 0, 'none' => 0, 'invalid' => 0, 'token' => 0, 'signed' => 0];
        $pixels = 0;
        $senders = [];
        $more = 0;
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $auth = (string) $row['auth'];
            $requests = (int) $row['requests'];
            if (!array_key_exists($auth, $counts)) {
                $auth = '';
            }
            $counts[$auth] += $requests;
            $pixel = in_array((string) $row['endpoint'], ['px', 'gpx', 'upx'], true);
            if ($pixel) {
                $pixels += $requests;
            }
            // A signed postback passes every mode; a pixel passes none that asks for a signature.
            if ($auth === '' || ($auth === 'signed' && !$pixel)) {
                continue;
            }
            if (count($senders) === self::MAX_SENDERS) {
                $more++;
                continue;
            }
            $senders[] = [
                'endpoint' => (string) $row['endpoint'],
                'presented' => $auth,
                'campaign_id' => $row['campaign_id'] === null ? null : (int) $row['campaign_id'],
                'campaign_name' => $row['aff_campaign_name'] === null ? null : (string) $row['aff_campaign_name'],
                'network_name' => $row['aff_network_name'] === null ? null : (string) $row['aff_network_name'],
                'requests' => $requests,
                'conversions' => (int) $row['conversions'],
                'last_received_at' => (int) $row['last_received_at'],
            ];
        }

        $total = array_sum($counts);
        $without = $counts['none'] + $counts['invalid'];
        // Nothing seen, or something seen whose credentials were not read: not known, so not "safe".
        $known = $total > 0 && $counts[''] === 0;

        return [
            'window_days' => self::WINDOW_DAYS,
            'since' => $since,
            'covers_since' => $this->coversSince($userId, $since),
            'requests' => $total,
            'unread' => $counts[''],
            'without_credentials' => $without,
            'token_only' => $counts['token'],
            'signed' => $counts['signed'],
            'pixels' => $pixels,
            'safe_to_require_token' => $without > 0 ? false : ($known ? true : null),
            'safe_to_require_signature' => $without + $counts['token'] + $pixels > 0 ? false : ($known ? true : null),
            'senders' => $senders,
            'more_senders' => $more,
        ];
    }

    /**
     * How far back the log still holds the account's requests: $since, or
     * later when a full bucket's oldest kept row is newer than that.
     */
    private function coversSince(int $userId, int $since): int
    {
        $stmt = $this->conn->prepareRead(
            'SELECT accepted, COUNT(*) AS kept, MIN(received_at) AS oldest FROM ' . TableRegistry::INBOUND_LOG
            . ' WHERE user_id = ? GROUP BY accepted'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $covers = $since;
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $cap = (int) $row['accepted'] === 1 ? InboundLog::KEEP_PER_ACCOUNT : InboundLog::KEEP_REFUSED;
            if ((int) $row['kept'] >= $cap) {
                $covers = max($covers, (int) $row['oldest']);
            }
        }

        return $covers;
    }
}
