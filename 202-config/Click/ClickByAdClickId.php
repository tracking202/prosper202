<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Conversion\UnfilledPlaceholder;
use Prosper202\Database\Connection;

/**
 * The click a server postback means when its SubID was not filled in: the
 * one click that carries an ad click id the postback sends back (gclid,
 * gbraid, wbraid, ttclid, msclkid, fbclid: ClickRecord::AD_CLICK_IDS, each
 * by its own parameter name), last visited in the last 30 days.
 *
 * One click or none, never a choice:
 * - an id is compared exactly as it is stored (202_google.gclid's collation
 *   folds case, so the stored value is read back and compared here);
 * - a click visited several times is one click; two clicks carrying the id
 *   are nobody's to choose between (a reloaded landing URL makes two);
 * - ids sent together that name different clicks name none;
 * - an id as long as its column or longer may be one the column cut, which
 *   names every id sharing that start, so it names no click.
 * An id that no click carries is left out of the decision.
 *
 * An id may also arrive in a parameter of the network's own naming that an
 * account lists (Prosper202\Conversion\ClickIdParameters): its value is
 * tried as every kind of id, among that account's clicks only.
 *
 * For an id under its own name the clicks of every account are read: nothing
 * in the request names an account until a click does. The caller reads the
 * click's owner and holds the request to that account's postback
 * authentication, as it does for a click a SubID names.
 */
final class ClickByAdClickId
{
    /** How far back a click's latest visit may be: 30 days, as the other fallbacks look. */
    public const LOOKBACK = 2592000;

    /** More rows than this for one id are not told apart. */
    private const ROW_LIMIT = 20;

    public const NONE = 'none';
    public const ONE = 'one';
    public const AMBIGUOUS = 'ambiguous';

    private function __construct()
    {
    }

    /**
     * The ad click ids a request sends, by parameter, trimmed: under their
     * own names, or under $names (an account's ClickIdParameters). A
     * parameter that is absent, blank or an unfilled macro sends none.
     * `unreadable` names the first parameter holding something that is not
     * text an id could be.
     *
     * @param array<array-key, mixed> $request
     * @param list<string>|null $names
     * @return array{ids: array<string, string>, unreadable: string|null}
     */
    public static function sent(array $request, ?array $names = null): array
    {
        $ids = [];
        $unreadable = null;
        foreach ($names ?? array_keys(ClickRecord::AD_CLICK_IDS) as $name) {
            $value = $request[$name] ?? null;
            if (UnfilledPlaceholder::isMissing($value)) {
                continue;
            }
            if (
                !is_string($value) || !mb_check_encoding($value, 'UTF-8')
                || preg_match('/[\x00-\x1F\x7F]/', trim($value)) === 1
            ) {
                $unreadable ??= $name;
                continue;
            }
            $ids[$name] = trim($value);
        }

        return ['ids' => $ids, 'unreadable' => $unreadable];
    }

    /**
     * @param array<string, string> $ids sent() under the ids' own names: looked up
     *        under that name, among every account's clicks
     * @param array<int, array<string, string>> $ownIds by account, sent() under the
     *        parameters it lists: looked up under every kind of id, among that
     *        account's clicks only
     * @param int $since the earliest time a visit of the click may have
     * @return array{status: string, click_id: int|null, matched: list<string>, why: string, owners: list<int>|null}
     *         matched: the parameters whose id the click carries. owners: the
     *         accounts the click may belong to, null for any (an id under its
     *         own name found it); the caller holds the click's owner to it
     * @throws \RuntimeException when a read fails: the caller answers 5xx, not "no click"
     */
    public static function find(Connection $conn, array $ids, array $ownIds, int $since): array
    {
        $clicks = [];
        $anyones = [];
        $owners = [];
        foreach ($ids as $name => $value) {
            $clicks[$name] = self::clicksCarrying($conn, [$name], $value, $since, null);
            foreach ($clicks[$name] ?? [] as $clickId) {
                $anyones[$clickId] = true;
            }
        }
        foreach ($ownIds as $userId => $sent) {
            foreach ($sent as $name => $value) {
                $found = self::clicksCarrying($conn, array_keys(ClickRecord::AD_CLICK_IDS), $value, $since, $userId);
                foreach ($found ?? [] as $clickId) {
                    $owners[$clickId][$userId] = true;
                }
                // Two accounts listing one parameter: its clicks in either.
                $known = array_key_exists($name, $clicks) ? $clicks[$name] : [];
                $clicks[$name] = $found === null || $known === null ? null : [...$known, ...$found];
            }
        }
        $answer = self::decide($clicks);
        $clickId = $answer['click_id'];
        $answer['owners'] = $clickId === null || isset($anyones[$clickId]) ? null : array_keys($owners[$clickId] ?? []);

        return $answer;
    }

    /**
     * @param array<string, list<int>|null> $clicks per parameter, the clicks
     *        carrying its id; null when there are more than can be told apart
     * @return array{status: string, click_id: int|null, matched: list<string>, why: string}
     */
    public static function decide(array $clicks): array
    {
        $all = [];
        foreach ($clicks as $name => $found) {
            if ($found === null) {
                return self::answer(self::AMBIGUOUS, null, [], 'Too many clicks carry the ' . $name
                    . ' sent to tell which one converted.');
            }
            if (count(array_unique($found)) > 1) {
                return self::answer(self::AMBIGUOUS, null, [], 'More than one click carries the ' . $name
                    . ' sent, so it does not say which one converted.');
            }
            foreach ($found as $clickId) {
                $all[$clickId][] = $name;
            }
        }
        if ($all === []) {
            return self::answer(self::NONE, null, [], '');
        }
        if (count($all) > 1) {
            $names = implode(', ', array_merge(...array_values($all)));

            $why = 'The click ids sent (' . $names . ') name different clicks.';

            return self::answer(self::AMBIGUOUS, null, [], $why);
        }
        $clickId = array_key_first($all);

        return self::answer(self::ONE, $clickId, array_values(array_unique($all[$clickId])), '');
    }

    /**
     * @param list<string> $matched
     * @return array{status: string, click_id: int|null, matched: list<string>, why: string}
     */
    private static function answer(string $status, ?int $clickId, array $matched, string $why): array
    {
        return ['status' => $status, 'click_id' => $clickId, 'matched' => $matched, 'why' => $why];
    }

    /**
     * The clicks that store exactly $value in one of $columns and whose
     * latest visit is since $since, $userId's only when one is given. Read on the
     * write handle: a replica may not have the click yet.
     *
     * @param list<string> $columns ad click ids, by name
     * @return list<int>|null null: more rows than ROW_LIMIT carry it
     */
    private static function clicksCarrying(
        Connection $conn,
        array $columns,
        string $value,
        int $since,
        ?int $userId
    ): ?array {
        $candidates = [];
        foreach ($columns as $name) {
            $width = ClickRecord::AD_CLICK_IDS[$name] ?? null;
            if ($width === null) {
                throw new \InvalidArgumentException("$name is not an ad click id a click keeps");
            }
            if ($value === '' || mb_strlen($value, 'UTF-8') >= $width) {
                continue;
            }
            $stmt = $conn->prepareWrite(
                "SELECT g.click_id, g.`$name` AS stored FROM 202_google AS g WHERE g.`$name` = ?"
                    . ' ORDER BY g.click_id DESC LIMIT ' . (self::ROW_LIMIT + 1)
            );
            $conn->bind($stmt, 's', [$value]);
            $rows = $conn->fetchAll($stmt);
            if (count($rows) > self::ROW_LIMIT) {
                return null;
            }
            foreach ($rows as $row) {
                // The server's comparison folds case (gclid) and trailing spaces; this one does not.
                if (($row['stored'] ?? null) === $value) {
                    $candidates[(int) $row['click_id']] = true;
                }
            }
        }
        if ($candidates === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($candidates), '?'));
        // The click's latest visit: its time, and its owner. A re-click gives a click another row: one click, not two.
        $stmt = $conn->prepareWrite(
            'SELECT c.click_id FROM 202_clicks AS c WHERE c.click_id IN (' . $in . ') AND c.click_time >= ?'
                . ($userId === null ? '' : ' AND c.user_id = ?')
                . ' AND ' . LatestVisit::isLatest('c')
                . ' GROUP BY c.click_id ORDER BY c.click_id'
        );
        $values = [...array_keys($candidates), $since];
        if ($userId !== null) {
            $values[] = $userId;
        }
        $conn->bind($stmt, str_repeat('i', count($values)), $values);

        return array_map(static fn (array $row): int => (int) $row['click_id'], $conn->fetchAll($stmt));
    }
}
