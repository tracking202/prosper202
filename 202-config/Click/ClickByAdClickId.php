<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Conversion\Ledger\DedupeKey;
use Prosper202\Conversion\UnfilledPlaceholder;
use Prosper202\Database\Connection;
use Prosper202\Goals\GoalSubject;

/**
 * The click a server postback means when its SubID was not filled in: the
 * click that carries an ad click id the postback sends back (gclid,
 * gbraid, wbraid, ttclid, msclkid, fbclid, oppref: ClickRecord::AD_CLICK_IDS, each
 * by its own parameter name), last visited in the last 30 days.
 *
 * An ad click id names one ad click. Several clicks carrying it are that
 * visitor recorded more than once (a reloaded landing URL, a redirect that
 * ran twice), so when every click the ids find is one account's, on one
 * campaign, from one traffic source account, the conversion goes to the
 * latest of them: the greatest latest-visit time, then the greatest click
 * id (ids are not in time order, CLAUDE.md #26; a click's time is its
 * latest visit's, LatestVisit). Each of those three is what a report puts
 * the sale under, so none of them is decided by which click came last.
 * When one of the clicks already holds this sale (its transaction id, its
 * event, or, for a postback with neither, a conversion), that click is
 * taken instead, and the ledger answers the retry as the duplicate it is
 * rather than recording the sale again on a newer click.
 *
 * Otherwise one click or none, never a choice:
 * - an id is compared exactly as 202_google stores it (the index only
 *   narrows, AdClickIdIndex; gclid's collation folds case);
 * - clicks of more than one account, campaign or traffic source account
 *   are nobody's to choose between, whether one id finds them or several;
 * - an id as long as its column or longer may be one the column cut, which
 *   names every id sharing that start, so it names no click.
 * An id that no click carries is left out of the decision, and a value no
 * platform issues (`undefined`, an unfilled __GCLID__, a {{ gclid }} the page
 * left: notAnId()) is not
 * looked up, since every visitor whose page wrote it shares it.
 *
 * An id may also arrive in a parameter of the network's own naming that an
 * account lists (Prosper202\Conversion\ClickIdParameters): its value is
 * tried as every kind of id, among that account's clicks only.
 *
 * For an id under its own name the clicks of every account are read: nothing
 * in the request names an account until a click does. The caller reads the
 * click's owner and holds the request to that account's postback
 * authentication, as it does for a click a SubID names.
 *
 * @phpstan-type Click array{click_id: int, user_id: int, campaign_id: int, ppc_account_id: int,
 *     click_time: int, lead: bool}
 */
final class ClickByAdClickId
{
    /** How far back a click's latest visit may be: 30 days, as the other fallbacks look. */
    public const LOOKBACK = 2592000;

    /** More clicks than this visited in the window for one id are not told apart. */
    private const ROW_LIMIT = 20;

    /**
     * Values a page or a template writes where an id was meant to go and
     * none was there, compared without case: no ad platform issues them, and
     * every visitor whose browser wrote one would share it.
     */
    private const NOT_AN_ID = ['undefined', 'null', 'nil', 'none', 'nan', 'false', 'true', '0', 'n/a', 'na',
        '(none)', '(not set)', '(not provided)', '[object object]', 'unknown', 'empty'];

    /** The fewest characters a platform's ad click id has (notAnId()). */
    public const MIN_ID_CHARS = 8;

    public const NONE = 'none';
    public const ONE = 'one';
    public const AMBIGUOUS = 'ambiguous';

    private function __construct()
    {
    }

    /**
     * The ad click ids a request sends, by parameter, trimmed: under their
     * own names, or under $names (an account's ClickIdParameters). A
     * parameter that is absent, blank or an unfilled macro sends none, and
     * so does one that is no platform's id (notAnId()).
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
            if (self::notAnId(trim($value))) {
                continue;
            }
            $ids[$name] = trim($value);
        }

        return ['ids' => $ids, 'unreadable' => $unreadable];
    }

    /**
     * Whether $value is what was written where an id was meant to go and
     * none was there: a word for nothing (NOT_AN_ID), a macro in the form
     * __NAME__ that the network did not fill, or anything without an id's
     * shape. Every platform's id (gclid, gbraid, wbraid, ttclid, msclkid,
     * fbclid) is at least MIN_ID_CHARS of letters, digits and . _ ~ -, with
     * a letter and a digit among them; a placeholder a page writes for
     * every visitor ({{ gclid }}, $gclid, the parameter's own name, test,
     * 123) is not, and looked up it would make many visitors' clicks one.
     */
    public static function notAnId(string $value): bool
    {
        return in_array(strtolower($value), self::NOT_AN_ID, true)
            || preg_match('/\A__[A-Za-z][A-Za-z0-9_]*__\z/D', $value) === 1
            || preg_match('/\A[A-Za-z0-9._~-]{' . self::MIN_ID_CHARS . ',}\z/D', $value) !== 1
            || preg_match('/[A-Za-z]/', $value) !== 1
            || preg_match('/[0-9]/', $value) !== 1;
    }

    /**
     * @param array<string, string> $ids sent() under the ids' own names: looked up
     *        under that name, among every account's clicks
     * @param array<int, array<string, string>> $ownIds by account, sent() under the
     *        parameters it lists: looked up under every kind of id, among that
     *        account's clicks only
     * @param int $since the earliest time the click's latest visit may have
     * @param string $transactionId the sale's, '' for none
     * @param string|null $eventId the event's id as the goals engine keys it, null for no event
     * @return array{status: string, click_id: int|null, matched: list<string>, why: string, of: int,
     *         account: int|null, owners: list<int>|null}
     *         matched: the parameters whose id the click carries. of: how
     *         many clicks carry them. account: decide()'s. owners: the accounts the click may
     *         belong to, null for any (an id under its own name found it);
     *         the caller holds the click's owner to it
     * @throws \RuntimeException when a read fails: the caller answers 5xx, not "no click"
     */
    public static function find(
        Connection $conn,
        array $ids,
        array $ownIds,
        int $since,
        string $transactionId = '',
        ?string $eventId = null
    ): array {
        $clicks = [];
        $anyones = [];
        $owners = [];
        foreach ($ids as $name => $value) {
            $clicks[$name] = self::clicksCarrying($conn, [$name], $value, $since, null);
            foreach ($clicks[$name] ?? [] as $click) {
                $anyones[$click['click_id']] = true;
            }
        }
        foreach ($ownIds as $userId => $sent) {
            foreach ($sent as $name => $value) {
                $found = self::clicksCarrying($conn, array_keys(ClickRecord::AD_CLICK_IDS), $value, $since, $userId);
                foreach ($found ?? [] as $click) {
                    $owners[$click['click_id']][$userId] = true;
                }
                // Two accounts listing one parameter: its clicks in either.
                $known = array_key_exists($name, $clicks) ? $clicks[$name] : [];
                $clicks[$name] = $found === null || $known === null ? null : [...$known, ...$found];
            }
        }
        $answer = self::decide($clicks);
        if ($answer['status'] === self::ONE && $answer['of'] > 1) {
            $all = [];
            foreach ($clicks as $found) {
                foreach ($found ?? [] as $click) {
                    $all[$click['click_id']] = $click;
                }
            }
            $holding = self::holdingTheSale($conn, array_values($all), $transactionId, $eventId);
            $answer = self::decide($clicks, $holding);
        }
        $clickId = $answer['click_id'];
        $answer['owners'] = $clickId === null || isset($anyones[$clickId]) ? null : array_keys($owners[$clickId] ?? []);

        return $answer;
    }

    /**
     * @param array<string, list<Click>|null> $clicks
     *        per parameter, the clicks carrying its id; null when there are
     *        more than can be told apart
     * @param list<int> $holding the clicks that already hold this sale
     * @return array{status: string, click_id: int|null, matched: list<string>, why: string, of: int,
     *         account: int|null}
     *         account: the one account every click found is of, which can
     *         settle a conflict among them; null when they span accounts or
     *         none was read
     */
    public static function decide(array $clicks, array $holding = []): array
    {
        $all = [];
        $names = [];
        foreach ($clicks as $name => $found) {
            if ($found === null) {
                return self::answer(self::AMBIGUOUS, null, [], 'Too many clicks carry the ' . $name
                    . ' sent to tell which one converted.');
            }
            foreach ($found as $click) {
                $all[$click['click_id']] = $click;
                $names[$click['click_id']][$name] = true;
            }
        }
        if ($all === []) {
            return self::answer(self::NONE, null, [], '');
        }
        $sent = implode(', ', array_keys($clicks));
        $accounts = array_values(array_unique(array_column($all, 'user_id')));
        $account = count($accounts) === 1 ? (int) $accounts[0] : null;
        // What a report puts the sale under: none of them is decided by which click came last.
        $shown = ['user_id' => 'account', 'campaign_id' => 'campaign', 'ppc_account_id' => 'traffic source account'];
        foreach ($shown as $key => $what) {
            if (count(array_unique(array_column($all, $key))) > 1) {
                return self::answer(self::AMBIGUOUS, null, [], 'The clicks carrying the ' . $sent
                    . ' sent are on more than one ' . $what . ', so they do not say which one converted.', 0, $account);
            }
        }
        $candidates = array_intersect_key($all, array_flip($holding)) ?: $all;
        $latest = null;
        foreach ($candidates as $click) {
            if (
                $latest === null || $click['click_time'] > $latest['click_time']
                || ($click['click_time'] === $latest['click_time'] && $click['click_id'] > $latest['click_id'])
            ) {
                $latest = $click;
            }
        }
        $matched = [];
        foreach ($names as $carriedBy) {
            $matched += $carriedBy;
        }

        return self::answer(self::ONE, $latest['click_id'], array_keys($matched), '', count($all), $account);
    }

    /**
     * @param list<string> $matched
     * @return array{status: string, click_id: int|null, matched: list<string>, why: string, of: int,
     *         account: int|null}
     */
    private static function answer(
        string $status,
        ?int $clickId,
        array $matched,
        string $why,
        int $of = 0,
        ?int $account = null
    ): array {
        return [
            'status' => $status, 'click_id' => $clickId, 'matched' => $matched, 'why' => $why, 'of' => $of,
            'account' => $account,
        ];
    }

    /**
     * Which of one visitor's clicks already hold the sale the postback is
     * about: a conversion under its transaction id, its event, or, for a
     * postback that names neither, any conversion (the click is a lead).
     *
     * @param list<Click> $clicks
     *        of one account
     * @return list<int>
     */
    private static function holdingTheSale(
        Connection $conn,
        array $clicks,
        string $transactionId,
        ?string $eventId
    ): array {
        $ids = array_column($clicks, 'click_id');
        $userId = $clicks[0]['user_id'];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $holding = [];
        if ($transactionId !== '') {
            try {
                $key = DedupeKey::transaction($transactionId);
            } catch (\InvalidArgumentException) {
                $key = null; // not an id the ledger keeps: the write refuses it by name
            }
            if ($key !== null) {
                $stmt = $conn->prepareWrite(
                    'SELECT click_id, dedupe_key FROM 202_conversion_logs WHERE click_id IN (' . $in . ')'
                        . ' AND user_id = ? AND dedupe_key = ?'
                );
                $conn->bind($stmt, str_repeat('i', count($ids)) . 'is', [...$ids, $userId, $key]);
                foreach ($conn->fetchAll($stmt) as $row) {
                    if (($row['dedupe_key'] ?? null) === $key) {
                        $holding[(int) $row['click_id']] = true;
                    }
                }
            }
        }
        if ($eventId !== null) {
            $stmt = $conn->prepareWrite(
                'SELECT subject_id, event_id FROM 202_goal_events WHERE subject_type = ?'
                    . ' AND subject_id IN (' . $in . ') AND user_id = ? AND event_id = ?'
            );
            $values = [GoalSubject::CLICK, ...$ids, $userId, $eventId];
            $conn->bind($stmt, 's' . str_repeat('i', count($ids)) . 'is', $values);
            foreach ($conn->fetchAll($stmt) as $row) {
                if (($row['event_id'] ?? null) === $eventId) {
                    $holding[(int) $row['subject_id']] = true;
                }
            }
        }
        if ($transactionId === '') {
            foreach ($clicks as $click) {
                if ($click['lead']) {
                    $holding[$click['click_id']] = true;
                }
            }
        }

        return array_keys($holding);
    }

    /**
     * The clicks that store exactly $value in one of $columns and whose
     * latest visit is since $since, $userId's only when one is given, each
     * as its latest visit has it. Read on the write handle: a replica may
     * not have the click yet.
     *
     * @param list<string> $columns ad click ids, by name
     * @return list<Click>|null
     *         null: more clicks visited since $since than ROW_LIMIT may carry it
     */
    private static function clicksCarrying(
        Connection $conn,
        array $columns,
        string $value,
        int $since,
        ?int $userId
    ): ?array {
        $readable = [];
        foreach ($columns as $name) {
            $width = ClickRecord::AD_CLICK_IDS[$name] ?? null;
            if ($width === null) {
                throw new \InvalidArgumentException("$name is not an ad click id a click keeps");
            }
            if (mb_strlen($value, 'UTF-8') < $width) {
                $readable[] = $name;
            }
        }
        if ($value === '' || $readable === []) {
            return [];
        }
        $indexed = AdClickIdIndex::clicksWith($conn, $value, $since, self::ROW_LIMIT);
        if (count($indexed) > self::ROW_LIMIT) {
            return null;
        }
        if ($indexed === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($indexed), '?'));
        $stmt = $conn->prepareWrite(
            'SELECT g.click_id, g.`' . implode('`, g.`', $readable) . '` FROM 202_google AS g'
                . ' WHERE g.click_id IN (' . $in . ')'
        );
        $conn->bind($stmt, str_repeat('i', count($indexed)), $indexed);
        $carrying = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            foreach ($readable as $name) {
                // The id itself: the index holds a hash, and the server's comparison would fold case (gclid).
                if (($row[$name] ?? null) === $value) {
                    $carrying[(int) $row['click_id']] = true;
                }
            }
        }
        if ($carrying === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($carrying), '?'));
        // The click's latest visit: its time, its owner, its campaign and traffic source.
        $stmt = $conn->prepareWrite(
            'SELECT c.click_id, c.user_id, c.aff_campaign_id, c.ppc_account_id, c.click_time, '
                . LatestVisit::anyVisitIsLead('c') . ' AS click_lead'
                . ' FROM 202_clicks AS c WHERE c.click_id IN (' . $in . ') AND c.click_time >= ?'
                . ($userId === null ? '' : ' AND c.user_id = ?')
                . ' AND ' . LatestVisit::isLatest('c')
                . ' ORDER BY c.click_id, c.click_time'
        );
        $values = [...array_keys($carrying), $since];
        if ($userId !== null) {
            $values[] = $userId;
        }
        $conn->bind($stmt, str_repeat('i', count($values)), $values);
        $clicks = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            // Two rows of one click in one second (written before 1.9.79) are one click.
            $clicks[(int) $row['click_id']] = [
                'click_id' => (int) $row['click_id'],
                'user_id' => (int) $row['user_id'],
                'campaign_id' => (int) $row['aff_campaign_id'],
                'ppc_account_id' => (int) $row['ppc_account_id'],
                'click_time' => (int) $row['click_time'],
                'lead' => (int) $row['click_lead'] === 1,
            ];
        }

        return array_values($clicks);
    }
}
