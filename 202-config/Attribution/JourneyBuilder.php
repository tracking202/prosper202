<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Database\Connection;

/**
 * Builds a conversion's journey from the identity graph (plan §6.2).
 *
 * A journey is the clicks carrying the converting click's canonical visitor
 * key — every key that is, or is an alias of, the canonical one, since
 * 202_clicks_visitor keeps the key a click had when it was recorded —
 * within the journey lookback before the conversion, across all campaigns,
 * up to and including the converting click. Bot clicks are excluded (the
 * converting click itself is kept whatever its flags: the conversion
 * happened through it). A converting click with no visitor key is a
 * one-touch journey, labelled unidentified.
 *
 * Filtered clicks are NOT excluded, although the plan (§6.2) says they are.
 * Measured on a live instance: Prosper202 sets click_filtered on every click
 * from an IP the account has seen in the last 24 hours (FILTER::checkLastIps),
 * so a person's second and third clicks in a day — exactly the touches a
 * journey is made of — are all "filtered". The flag cannot say why it was
 * set; bots carry click_bot as well, and strangers never share a visitor
 * key, which is the gate that keeps them out.
 *
 * Clicks after the converting click are not part of its journey even when
 * they precede the conversion's arrival: the conversion happened through
 * the converting click, so nothing after it could have led to it.
 *
 * Never IP, user agent or campaign: the old engine's journey was the
 * account's recent clicks on the same campaign, which is strangers' clicks.
 */
final class JourneyBuilder
{
    public function __construct(private Connection $conn)
    {
    }

    public function build(int $userId, int $convClickId, int $convClickTime, int $convTime, int $lookbackDays): Journey
    {
        $convTouch = static fn (int $position): Touch => new Touch($position, $convClickId, $convClickTime);

        $key = $this->visitorKey($convClickId);
        if ($key === null) {
            return new Journey([$convTouch(0)], $lookbackDays, false, false);
        }
        $keys = $this->keysOfPerson($userId, $key);
        if ($keys === []) {
            // The click names a visitor key the graph has no row for: the
            // graph is inconsistent, and guessing would join strangers.
            throw new \RuntimeException(
                'click ' . $convClickId . ' has visitor key ' . $key . ', which account ' . $userId . ' has no visitor row for'
            );
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $from = max(0, $convTime - $lookbackDays * 86400);
        $stmt = $this->conn->prepareWrite(
            "SELECT cv.click_id, cv.click_time
             FROM 202_clicks_visitor cv
             JOIN 202_clicks c ON c.click_id = cv.click_id
             WHERE cv.user_id = ?
               AND cv.visitor_key IN ($placeholders)
               AND cv.click_time >= ?
               AND (cv.click_time < ? OR (cv.click_time = ? AND cv.click_id < ?))
               AND c.click_bot = 0
             ORDER BY cv.click_time DESC, cv.click_id DESC
             LIMIT " . Journey::MAX_TOUCHES
        );
        $this->conn->bind(
            $stmt,
            'i' . str_repeat('i', count($keys)) . 'iiii',
            array_merge([$userId], $keys, [$from, $convClickTime, $convClickTime, $convClickId])
        );
        $earlier = $this->conn->fetchAll($stmt);

        // LIMIT MAX_TOUCHES fetched one more than fits beside the converting
        // click, which is how a cut journey is told from a full one.
        $truncated = count($earlier) >= Journey::MAX_TOUCHES;
        $earlier = array_slice($earlier, 0, Journey::MAX_TOUCHES - 1);
        $earlier = array_reverse($earlier);

        $touches = [];
        foreach ($earlier as $i => $row) {
            $touches[] = new Touch($i, (int) $row['click_id'], (int) $row['click_time']);
        }
        $touches[] = $convTouch(count($touches));

        return new Journey($touches, $lookbackDays, $truncated, true);
    }

    private function visitorKey(int $clickId): ?int
    {
        $stmt = $this->conn->prepareWrite('SELECT visitor_key FROM 202_clicks_visitor WHERE click_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$clickId]);
        $row = $this->conn->fetchOne($stmt);

        return $row !== null ? (int) $row['visitor_key'] : null;
    }

    /**
     * Every visitor key of the person a key belongs to. Merges compress
     * paths (IdentityGraph::merge), so a key is at most one hop from its
     * canonical key and every alias points straight at it.
     *
     * @return list<int>
     */
    public function keysOfPerson(int $userId, int $key): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT alias_of FROM 202_identity_visitors WHERE visitor_key = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$key, $userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return [];
        }
        $canonical = $row['alias_of'] !== null ? (int) $row['alias_of'] : $key;

        $stmt = $this->conn->prepareWrite(
            'SELECT visitor_key FROM 202_identity_visitors WHERE user_id = ? AND (visitor_key = ? OR alias_of = ?) ORDER BY visitor_key'
        );
        $this->conn->bind($stmt, 'iii', [$userId, $canonical, $canonical]);

        return array_map(static fn (array $r): int => (int) $r['visitor_key'], $this->conn->fetchAll($stmt));
    }
}
