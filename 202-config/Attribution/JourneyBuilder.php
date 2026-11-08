<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;
use Prosper202\Identity\IdentityGraph;

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
 * A click is one touch however many visits a redirector re-click gave it,
 * at the time its visitor row holds (the first visit's: the graph keeps the
 * time a click was first linked), and a bot when its latest visit is one,
 * as the reports count it. The converting click is the last touch only,
 * even when the conversion carries a later visit's time than its visitor
 * row.
 *
 * Never IP, user agent or campaign: the old engine's journey was the
 * account's recent clicks on the same campaign, which is strangers' clicks.
 */
final class JourneyBuilder
{
    /**
     * The most visitor keys one read names. A person can have more keys
     * than a prepared statement takes values (65,535; 65,536 is refused,
     * measured on MariaDB 10.11), so a person's keys are read in groups of
     * this many. Nearly every person has far fewer, and is one read.
     */
    public const KEYS_PER_STATEMENT = 500;

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

        // The latest MAX_TOUCHES of each group of keys hold the latest
        // MAX_TOUCHES of them all, so the groups' answers are merged and cut
        // again. A click has one visitor row, so no click is in two groups.
        $from = max(0, $convTime - $lookbackDays * 86400);
        $earlier = [];
        foreach (array_chunk($keys, self::KEYS_PER_STATEMENT) as $group) {
            $earlier += $this->earlierClicks($userId, $group, $from, $convClickId, $convClickTime);
        }
        ['clicks' => $earlier, 'truncated' => $truncated] = self::latestTouches($earlier);

        $touches = [];
        foreach ($earlier as $clickId => $clickTime) {
            $touches[] = new Touch(count($touches), $clickId, $clickTime);
        }
        $touches[] = $convTouch(count($touches));

        return new Journey($touches, $lookbackDays, $truncated, true);
    }

    /**
     * The clicks that fit beside the converting click, oldest first: the
     * latest MAX_TOUCHES - 1 of $clicks (click_id => click_time), by time
     * and then click id as each read orders them. Each read fetched
     * MAX_TOUCHES, one more than fits, which is how a cut journey is told
     * from a full one.
     *
     * @param array<int, int> $clicks
     * @return array{clicks: array<int, int>, truncated: bool}
     */
    public static function latestTouches(array $clicks): array
    {
        $ids = array_keys($clicks);
        usort($ids, static fn (int $a, int $b): int => [$clicks[$b], $b] <=> [$clicks[$a], $a]);
        $kept = [];
        foreach (array_reverse(array_slice($ids, 0, Journey::MAX_TOUCHES - 1)) as $id) {
            $kept[$id] = $clicks[$id];
        }

        return ['clicks' => $kept, 'truncated' => count($clicks) >= Journey::MAX_TOUCHES];
    }

    /**
     * The latest MAX_TOUCHES clicks on a group of the person's keys before
     * the converting click, click_id => click_time.
     *
     * @param list<int> $keys
     * @return array<int, int>
     */
    private function earlierClicks(int $userId, array $keys, int $from, int $convClickId, int $convClickTime): array
    {
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $this->conn->prepareWrite(
            "SELECT DISTINCT cv.click_id, cv.click_time
             FROM 202_clicks_visitor cv
             JOIN 202_clicks c ON c.click_id = cv.click_id
             WHERE cv.user_id = ?
               AND cv.visitor_key IN ($placeholders)
               AND cv.click_time >= ?
               AND (cv.click_time < ? OR (cv.click_time = ? AND cv.click_id < ?))
               AND cv.click_id <> ?
               AND c.click_bot = 0 AND " . LatestVisit::isLatest('c') . "
             ORDER BY cv.click_time DESC, cv.click_id DESC
             LIMIT " . Journey::MAX_TOUCHES
        );
        $this->conn->bind(
            $stmt,
            'i' . str_repeat('i', count($keys)) . 'iiiii',
            array_merge([$userId], $keys, [$from, $convClickTime, $convClickTime, $convClickId, $convClickId])
        );
        $clicks = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $clicks[(int) $row['click_id']] = (int) $row['click_time'];
        }

        return $clicks;
    }

    private function visitorKey(int $clickId): ?int
    {
        $stmt = $this->conn->prepareWrite('SELECT visitor_key FROM 202_clicks_visitor WHERE click_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$clickId]);
        $row = $this->conn->fetchOne($stmt);

        return $row !== null ? (int) $row['visitor_key'] : null;
    }

    /**
     * Every visitor key of the person a key belongs to (IdentityGraph owns
     * the alias structure; this only delegates to it).
     *
     * @return list<int>
     */
    public function keysOfPerson(int $userId, int $key): array
    {
        return (new IdentityGraph($this->conn))->keysOfPerson($userId, $key);
    }
}
