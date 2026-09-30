<?php

declare(strict_types=1);

namespace Prosper202\Goals;

use Prosper202\Database\Connection;
use Prosper202\Identity\IdentityGraph;
use Prosper202\Identity\IdentityKeys;
use Prosper202\Identity\IdentitySignal;
use Prosper202\Identity\SignalType;

/**
 * Web events (plan §2.2): the events a web campaign's click reports, from
 * the three places they arrive —
 *
 *   - a pixel or postback carrying `event=` (gpb, gpx, upx, pb, px),
 *   - POST /api/v3/events, keyed by click_id,
 *   - p202.js's `p202.track(name, props)` on a landing or thank-you page,
 *     tied to the click by the page's first-party visitor id (§6.2).
 *
 * All three store the event on the click subject and evaluate the click's
 * goals through GoalEngine::ingest(), in one transaction; goal outcomes
 * reach the ledger only through the engine (and so only through
 * MysqlConversionRepository). This class decides nothing about goals; it
 * reads requests into GoalEvents, and finds the click a page's visitor id
 * names.
 *
 * Event ids. An event id is what makes a retried event a duplicate rather
 * than a second event (GoalEngine dedupes on it, and a reused id with
 * different content is refused). The API and p202.js send their own. A
 * pixel or postback may send `event_id`; when it does not, the id is
 * derived — `@tx:<transaction id>` when the request carries one (the same
 * sale retried is one event), otherwise `@once:<event name>` (the event
 * happens once per click, the pixel rule for requests that carry no id).
 * A derived id starts with "@", which no caller-supplied id may, so the two
 * can never name the same event by accident (CLAUDE.md #17).
 */
final class WebEvents
{
    /** The longest transaction id used in a derived event id as it is (longer ones are hashed). */
    public const MAX_TX_IN_ID = 124;

    private const EVENT_ID = '/^[\x21-\x3F\x41-\x7E][\x21-\x7E]{0,127}$/D';
    private const AMOUNT = '/^-?(?:0|[1-9]\d{0,11})(?:\.\d{1,5})?$/D';
    private const LPID = '/^[0-9a-f]{32}$/D';
    /** How far back a page's visitor id names a click: the pixel IP fallback's window. */
    public const VISITOR_LOOKBACK = 2592000;

    public function __construct(private Connection $conn)
    {
    }

    /**
     * Whether a query string carries an event: a non-empty `event`.
     *
     * @param array<string, mixed> $get
     */
    public static function requested(array $get): bool
    {
        return array_key_exists('event', $get) && !(is_string($get['event']) && trim($get['event']) === '');
    }

    /**
     * The `event` of a query string when it is a valid event name, else
     * null — for the rows a campaign without goals records as a plain
     * conversion, which carry the name for the breakdown and nothing else.
     *
     * @param array<string, mixed> $get
     */
    public static function nameFrom(array $get): ?string
    {
        $name = $get['event'] ?? null;
        if (!is_string($name) || preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.:\-]{0,63}$/D', $name) !== 1) {
            return null;
        }

        return $name;
    }

    /**
     * Read the event a pixel or postback carries.
     *
     *   event        the event name (required)
     *   event_id     the caller's id for it (optional; derived otherwise)
     *   event_props  a JSON object of properties (optional)
     *   amount       what it is worth: the event's revenue (optional)
     *
     * Everything is read strictly: a value that is present and malformed is
     * refused by name, never dropped or cast (CLAUDE.md #4, #18).
     *
     * @param array<string, mixed> $get
     * @param string $transactionId the request's transaction id ('' for none)
     * @throws InvalidGoalDefinition naming every bad parameter
     */
    public static function fromQuery(array $get, string $transactionId, int $now, bool $revenueTrusted): GoalEvent
    {
        $errors = [];
        $name = $get['event'] ?? null;
        if (!is_string($name)) {
            $errors['event'] = 'must be one event name';
            $name = '';
        }

        $explicitId = null;
        if (array_key_exists('event_id', $get) && $get['event_id'] !== '') {
            if (!is_string($get['event_id']) || preg_match(self::EVENT_ID, $get['event_id']) !== 1) {
                $errors['event_id'] = 'must be 1-128 printable characters without spaces, not starting with "@"';
            } else {
                $explicitId = $get['event_id'];
            }
        }

        $props = [];
        if (array_key_exists('event_props', $get) && $get['event_props'] !== '') {
            $decoded = is_string($get['event_props']) ? json_decode($get['event_props'], true, 4) : null;
            if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
                $errors['event_props'] = 'must be a JSON object of properties, e.g. {"plan":"pro","seats":3}';
            } else {
                $props = $decoded;
            }
        }

        $revenue = null;
        if (array_key_exists('amount', $get) && $get['amount'] !== '') {
            $revenue = self::number($get['amount']);
            if ($revenue === null) {
                $errors['amount'] = 'must be a decimal number with at most 5 decimal places';
            }
        }

        // The rest of the checks are the event's own (name, properties),
        // made by the same code that reads an API event.
        try {
            $probe = GoalEvent::fromArray(array_filter([
                'event_id' => $explicitId ?? 'probe',
                'name' => $name,
                'occurred_at' => $now,
                'received_at' => $now,
                'properties' => $props === [] ? null : $props,
                'revenue' => $revenue,
                'revenue_trusted' => $revenueTrusted,
                'transaction_id' => $transactionId !== '' ? $transactionId : null,
            ], static fn ($v): bool => $v !== null), 'event');
        } catch (InvalidGoalDefinition $e) {
            foreach ($e->errors() as $path => $message) {
                $param = match (true) {
                    $path === 'event.name' => 'event',
                    str_starts_with($path, 'event.properties') => 'event_props' . substr($path, strlen('event.properties')),
                    $path === 'event.transaction_id' => 'transaction_id',
                    default => $path,
                };
                $errors[$param] ??= $message;
            }
            $probe = null;
        }
        if ($errors !== [] || $probe === null) {
            ksort($errors);
            throw new InvalidGoalDefinition($errors, 'The event is invalid');
        }

        return new GoalEvent(
            $explicitId ?? self::derivedId($transactionId, (string) $probe->name),
            $probe->name,
            $now,
            $now,
            $probe->properties,
            $probe->revenue,
            $revenueTrusted,
            $probe->transactionId,
            false,
            // A pixel says nothing about when the event happened: the time
            // is the server's, so a retry later is the same event.
            true,
        );
    }

    /** The id of an event whose sender gave none (see the class docblock). */
    public static function derivedId(string $transactionId, string $name): string
    {
        $tx = trim($transactionId);
        if ($tx === '') {
            return '@once:' . $name;
        }
        if (preg_match('/^[\x21-\x7E]{1,' . self::MAX_TX_IN_ID . '}$/D', $tx) === 1) {
            return '@tx:' . $tx;
        }

        // Too long for the id column, or not printable ASCII: its digest,
        // under a prefix the plain form can never produce.
        return '@txh:' . hash('sha256', $tx);
    }

    /** A decimal amount from a query string as a JSON-like number, or null. */
    private static function number(mixed $raw): int|float|null
    {
        if (!is_string($raw) || preg_match(self::AMOUNT, $raw) !== 1) {
            return null;
        }

        return str_contains($raw, '.') ? (float) $raw : (int) $raw;
    }

    // ─── Reading the click ──────────────────────────────────────────

    /**
     * The click's owner and campaign, or null when there is no such click.
     *
     * @return array{user_id: int, campaign_id: int, click_time: int}|null
     */
    public function click(int $clickId): ?array
    {
        $stmt = $this->conn->prepareRead('SELECT user_id, aff_campaign_id, click_time FROM 202_clicks WHERE click_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$clickId]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : [
            'user_id' => (int) $row['user_id'],
            'campaign_id' => (int) $row['aff_campaign_id'],
            'click_time' => (int) $row['click_time'],
        ];
    }

    /**
     * Whether a click on this campaign evaluates goals for an event received
     * at $at: some goal of the campaign (its own, or one it pays for) is
     * live then. A campaign without one records `event=` hits as it always
     * has — one plain conversion — so no existing setup changes behaviour
     * until goals are added (plan §2.2: goals are opt-in per campaign).
     */
    public function campaignEvaluatesGoals(int $userId, int $campaignId, int $at): bool
    {
        if ($campaignId <= 0) {
            return false;
        }
        foreach ((new MysqlGoalRepository($this->conn))->specsForCampaign($userId, $campaignId) as $spec) {
            if ($spec->startsAt <= $at && ($spec->endsAt === null || $at < $spec->endsAt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The click a landing page's visitor id names, for p202.track(): the
     * newest click of the page's account that was observed carrying this id
     * (the p202lpid the page sent with its pageview or on a link into the
     * tracker), within VISITOR_LOOKBACK, and — for a page that belongs to
     * one campaign — on that campaign.
     *
     * The id is 128 random bits held in the page's own storage, so a caller
     * can only name the clicks of a browser it controls; a click id, which
     * anyone can count up to, is never accepted from a page (CLAUDE.md
     * #16). A quarantined id (one that joined too many visitors: a shared
     * kiosk, a leaked id) names nothing.
     *
     * @return array{user_id: int, click_id: int, campaign_id: int}|null
     */
    public function clickForVisitor(string $landingPagePublicId, string $lpid, int $now): ?array
    {
        if (preg_match(self::LPID, $lpid) !== 1 || preg_match('/^[1-9]\d{0,9}$/D', $landingPagePublicId) !== 1 || (int) $landingPagePublicId > 4294967295) {
            return null;
        }
        $stmt = $this->conn->prepareRead(
            'SELECT user_id, aff_campaign_id, landing_page_type FROM 202_landing_pages
             WHERE landing_page_id_public = ? AND landing_page_deleted = 0 LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [(int) $landingPagePublicId]);
        $page = $this->conn->fetchOne($stmt);
        if ($page === null) {
            return null;
        }
        $userId = (int) $page['user_id'];
        // A simple landing page belongs to one campaign; an advanced one
        // sends visitors to several, so any of the account's campaigns.
        $campaignId = (int) $page['landing_page_type'] === 0 ? (int) $page['aff_campaign_id'] : 0;

        $hash = IdentityGraph::hash((new IdentityKeys($this->conn))->forUser($userId)['hash'], new IdentitySignal(SignalType::LANDING_PAGE, $lpid));

        $quarantine = $this->conn->prepareRead(
            'SELECT quarantined_at FROM 202_identity_signals WHERE user_id = ? AND signal_type = ? AND signal_hash = ? LIMIT 1'
        );
        $this->conn->bind($quarantine, 'iss', [$userId, SignalType::LANDING_PAGE->value, $hash]);
        $signal = $this->conn->fetchOne($quarantine);
        if ($signal !== null && $signal['quarantined_at'] !== null) {
            return null;
        }

        $sql = 'SELECT c.click_id, c.aff_campaign_id FROM 202_identity_observations AS o
                JOIN 202_clicks AS c ON c.click_id = o.click_id
                WHERE o.signal_type = ? AND o.signal_hash = ? AND c.user_id = ? AND c.click_time >= ?'
            . ($campaignId > 0 ? ' AND c.aff_campaign_id = ?' : '')
            . ' ORDER BY c.click_time DESC, c.click_id DESC LIMIT 1';
        $stmt = $this->conn->prepareRead($sql);
        $params = [SignalType::LANDING_PAGE->value, $hash, $userId, $now - self::VISITOR_LOOKBACK];
        if ($campaignId > 0) {
            $params[] = $campaignId;
        }
        $this->conn->bind($stmt, 'ssii' . ($campaignId > 0 ? 'i' : ''), $params);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : ['user_id' => $userId, 'click_id' => (int) $row['click_id'], 'campaign_id' => (int) $row['aff_campaign_id']];
    }
}
