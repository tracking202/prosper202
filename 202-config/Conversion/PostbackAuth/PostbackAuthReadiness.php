<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

use Prosper202\Click\ClickId;
use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;
use Prosper202\Database\Schema\TableRegistry;
use Prosper202\InboundLog\InboundLog;
use Prosper202\InboundLog\Redactor;

/**
 * Who would be refused if the account required more than it does: the
 * account's postbacks and pixels of the last WINDOW_DAYS, by what each
 * presented (202_inbound_log.auth), grouped by endpoint and campaign.
 * Account › API Integrations shows it beside the choice, and GET
 * /conversions/postback-auth/readiness answers it.
 *
 * A request's campaign is the one its conversion was recorded on; for one
 * that recorded nothing, the campaign its URL names (acip, on pb.php and
 * px.php) or its click's (the click the endpoint found, else its subid),
 * each only where it is the account's own. A group no campaign could be
 * told for carries a few of the subids its requests named instead.
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
    /** Subids shown for a group no campaign could be told for. */
    public const MAX_SUBIDS = 3;
    /** Clicks looked up for their campaign; requests past these are grouped without one. */
    public const MAX_CLICK_LOOKUPS = 2000;
    private const CHUNK = 200;

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @return array{
     *     window_days: int, since: int, covers_since: int, requests: int, unread: int,
     *     without_credentials: int, token_only: int, signed: int, pixels: int,
     *     safe_to_require_token: bool|null, safe_to_require_signature: bool|null,
     *     senders: list<array{endpoint: string, presented: string, campaign_id: int|null, campaign_name: string|null,
     *         network_name: string|null, subids: list<string>, requests: int, conversions: int, last_received_at: int}>,
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

        // The log is bounded per account, so this reads a bounded number of rows.
        $stmt = $this->conn->prepareRead(
            'SELECT endpoint, auth, click_id, conversion_id, received_at, IF(conversion_id IS NULL, fields, NULL) AS fields'
            . ' FROM ' . TableRegistry::INBOUND_LOG
            . ' WHERE user_id = ? AND received_at >= ? AND endpoint IN (' . $in . ') ORDER BY inbound_id DESC'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $since]);
        $rows = $this->conn->fetchAll($stmt);

        $counts = ['' => 0, 'none' => 0, 'invalid' => 0, 'token' => 0, 'signed' => 0];
        $pixels = 0;
        $listed = [];
        foreach ($rows as $row) {
            $auth = array_key_exists((string) $row['auth'], $counts) ? (string) $row['auth'] : '';
            $counts[$auth]++;
            $pixel = in_array((string) $row['endpoint'], ['px', 'gpx', 'upx'], true);
            $pixels += $pixel ? 1 : 0;
            // A signed postback passes every mode; a pixel passes none that asks for a signature.
            if ($auth === '' || ($auth === 'signed' && !$pixel)) {
                continue;
            }
            $fields = is_string($row['fields']) ? json_decode($row['fields'], true) : null;
            $fields = is_array($fields) ? $fields : [];
            $listed[] = [
                'endpoint' => (string) $row['endpoint'],
                'presented' => $auth,
                'received_at' => (int) $row['received_at'],
                'conversion_id' => $row['conversion_id'] === null ? null : (int) $row['conversion_id'],
                'acip' => in_array($row['endpoint'], ['pb', 'px'], true) ? ClickId::parse($fields['acip'] ?? null) : null,
                'click_id' => $row['click_id'] !== null ? (int) $row['click_id']
                    : (ClickId::parse($fields['subid'] ?? null) ?? ClickId::parse($fields['sid'] ?? null)),
                'subid' => is_string($fields['subid'] ?? null) && $fields['subid'] !== Redactor::REDACTED ? $fields['subid'] : null,
            ];
        }

        $byConversion = $this->campaignsOf($userId, 'conversion', array_column($listed, 'conversion_id'));
        $byPublicId = $this->campaignsOf($userId, 'acip', array_column($listed, 'acip'));
        $unnamed = array_filter($listed, static fn (array $r): bool => !isset($byConversion[$r['conversion_id'] ?? 0])
            && !isset($byPublicId[$r['acip'] ?? 0]));
        $byClick = $this->campaignsOf($userId, 'click', array_slice(array_column($unnamed, 'click_id'), 0, self::MAX_CLICK_LOOKUPS));

        $groups = [];
        foreach ($listed as $r) {
            $campaign = $byConversion[$r['conversion_id'] ?? 0] ?? $byPublicId[$r['acip'] ?? 0] ?? $byClick[$r['click_id'] ?? 0] ?? null;
            $key = $r['endpoint'] . '|' . $r['presented'] . '|' . ($campaign ?? 0);
            $groups[$key] ??= [
                'endpoint' => $r['endpoint'], 'presented' => $r['presented'], 'campaign_id' => $campaign, 'campaign_name' => null,
                'network_name' => null, 'subids' => [], 'requests' => 0, 'conversions' => 0, 'last_received_at' => 0,
            ];
            $groups[$key]['requests']++;
            $groups[$key]['conversions'] += $r['conversion_id'] !== null ? 1 : 0;
            $groups[$key]['last_received_at'] = max($groups[$key]['last_received_at'], $r['received_at']);
            $subid = $r['subid'] ?? ($r['click_id'] !== null ? (string) $r['click_id'] : null);
            if ($campaign === null && $subid !== null && count($groups[$key]['subids']) < self::MAX_SUBIDS
                && !in_array($subid, $groups[$key]['subids'], true)) {
                $groups[$key]['subids'][] = $subid;
            }
        }
        $names = $this->campaignNames($userId, array_column($groups, 'campaign_id'));
        foreach ($groups as &$group) {
            [$group['campaign_name'], $group['network_name']] = $names[$group['campaign_id'] ?? 0] ?? [null, null];
        }
        unset($group);
        usort($groups, static fn (array $a, array $b): int => [$b['requests'], $b['last_received_at']] <=> [$a['requests'], $a['last_received_at']]);

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
            'senders' => array_slice($groups, 0, self::MAX_SENDERS),
            'more_senders' => max(0, count($groups) - self::MAX_SENDERS),
        ];
    }

    /**
     * The account's own campaign for each id: of a conversion (its row's),
     * of an acip (the campaign with that public id) or of a click (a visit's).
     *
     * @param list<int|null> $ids
     * @return array<int, int> id => campaign id
     */
    private function campaignsOf(int $userId, string $kind, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (?int $id): bool => $id !== null && $id > 0)));
        $sql = match ($kind) {
            'conversion' => 'SELECT conv_id AS id, campaign_id FROM 202_conversion_logs WHERE user_id = ? AND conv_id IN (%s)',
            'acip' => 'SELECT aff_campaign_id_public AS id, aff_campaign_id AS campaign_id FROM 202_aff_campaigns'
                . ' WHERE user_id = ? AND aff_campaign_id_public IN (%s)',
            // The click's latest visit, whose campaign a conversion would be credited to.
            'click' => 'SELECT c.click_id AS id, MAX(c.aff_campaign_id) AS campaign_id FROM 202_clicks AS c'
                . ' WHERE c.user_id = ? AND c.click_id IN (%s) AND ' . LatestVisit::isLatest('c') . ' GROUP BY c.click_id',
        };
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $stmt = $this->conn->prepareRead(sprintf($sql, implode(',', array_fill(0, count($chunk), '?'))));
            $this->conn->bind($stmt, 'i' . str_repeat('i', count($chunk)), [$userId, ...$chunk]);
            foreach ($this->conn->fetchAll($stmt) as $row) {
                if ((int) $row['campaign_id'] > 0) {
                    $out[(int) $row['id']] = (int) $row['campaign_id'];
                }
            }
        }

        return $out;
    }

    /**
     * @param list<int|null> $campaignIds
     * @return array<int, array{0: string, 1: string|null}> campaign id => its name and its network's
     */
    private function campaignNames(int $userId, array $campaignIds): array
    {
        $ids = array_values(array_unique(array_filter($campaignIds, static fn (?int $id): bool => $id !== null && $id > 0)));
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $stmt = $this->conn->prepareRead(
                'SELECT ac.aff_campaign_id, ac.aff_campaign_name, an.aff_network_name FROM 202_aff_campaigns AS ac'
                . ' LEFT JOIN 202_aff_networks AS an ON (an.aff_network_id = ac.aff_network_id AND an.user_id = ac.user_id)'
                . ' WHERE ac.user_id = ? AND ac.aff_campaign_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $this->conn->bind($stmt, 'i' . str_repeat('i', count($chunk)), [$userId, ...$chunk]);
            foreach ($this->conn->fetchAll($stmt) as $row) {
                $out[(int) $row['aff_campaign_id']] = [
                    (string) $row['aff_campaign_name'],
                    $row['aff_network_name'] === null ? null : (string) $row['aff_network_name'],
                ];
            }
        }

        return $out;
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
