<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Api\V3\Apps\AppRegistration;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\QuotaStore;
use Prosper202\Database\Connection;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\GoalEngineException;
use Prosper202\Goals\GoalEvent;
use Prosper202\Goals\InvalidGoalDefinition;
use Prosper202\Goals\MysqlGoalRepository;

/**
 * POST /apps/installs/{install_uuid}/events: what an installed app reports
 * after the install (plan §5.5). An event on its own records nothing; the
 * goal engine evaluates it against the install's goals, in event-time order,
 * idempotent on (install, event_id), and decides what is reached and paid.
 *
 * The body is {"events": [ … ], "customer": …}, 1 to MAX_EVENTS per
 * request (an offline queue flushes in batches). `customer` is optional:
 * the signed customer id the app's user signed in as (CustomerClaim),
 * linked to the install's click after the events commit; a body may carry
 * it alone, with no events, so an app that reports no events can still
 * send it. Each event is {event_id, name, occurred_at,
 * properties?, revenue?, transaction_id?}: the server stamps received_at
 * and decides revenue trust from the registration's trust_client_revenue —
 * a device that sends either is refused by name, never believed.
 *
 * Which installs accept events:
 *  - a pending install (pending_click, pending_integrity) answers 503 with
 *    Retry-After: its goals are evaluated when it settles, and the events
 *    wait on the device until then (the SDK retries 5xx);
 *  - a refuted install (bad_token, foreign_click, implausible) answers 409:
 *    a forged or implausible claim reaches no goals, and a 4xx is terminal
 *    for the SDK;
 *  - every other install evaluates its events — an attributed, trusted one
 *    with its click (ledger rows, payouts, notifications), the rest for the
 *    funnel only.
 *
 * One install may post at most its registration's `event_cap_per_minute`
 * events a minute (AppLimits); a batch that would pass it is answered 429
 * with Retry-After and stored nowhere, and the SDK keeps it and retries.
 * The cap counts events stored, not events sent: a batch is charged for
 * the ids the install does not already hold (so a replay of a stored batch
 * costs nothing and is answered with its duplicates even at the cap), and
 * whatever the engine then does not accept — ids another request stored
 * first, a batch it refuses, a failure — is refunded. A body carrying only
 * a customer id costs one.
 */
final class InstallEventsIntake
{
    public const MAX_BODY_BYTES = 65536;
    public const MAX_EVENTS = 100;
    private const DEVICE_FIELDS = ['event_id', 'name', 'occurred_at', 'properties', 'revenue', 'transaction_id'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    private Connection $conn;
    private GoalEngine $engine;
    private InstallIntake $installs;

    /**
     * @param (callable(): int)|null $clock
     * @param QuotaStore|null $quota the event cap's store (InstallIntake::admit())
     */
    public function __construct(\mysqli $db, private $clock = null, ?GoalEngine $engine = null, ?QuotaStore $quota = null)
    {
        $this->conn = new Connection($db);
        $this->engine = $engine ?? new GoalEngine($this->conn, new MysqlGoalRepository($this->conn), null, $clock);
        $this->installs = new InstallIntake($db, $clock, $this->engine, null, $quota);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    /**
     * @return array{status: int, body: array<string, mixed>, headers?: array<string, string>}
     */
    public function receive(?string $token, string $installUuid, string $rawBody): array
    {
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return InstallIntake::error(413, 'The events body is larger than ' . self::MAX_BODY_BYTES . ' bytes; send fewer events per request');
        }
        $registration = $this->installs->registration($token);
        if (!$registration instanceof AppRegistration) {
            return $registration;
        }
        if (preg_match(self::UUID, $installUuid) !== 1) {
            return InstallIntake::error(400, 'The install id in the path must be the install_uuid the install was reported with (canonical lower-case UUID)');
        }
        $install = $this->installs->stored($registration->registrationId, $installUuid);
        if ($install === null) {
            return InstallIntake::error(404, 'Unknown install ' . $installUuid . ' for this app: report it to POST /apps/installs before its events');
        }
        $state = MatchState::tryFrom((string) $install['match_state']);
        if ($state === null) {
            throw new \RuntimeException('install ' . (int) $install['install_row_id'] . ' has an unknown match_state "' . (string) $install['match_state'] . '"');
        }
        if ($state->isPending()) {
            return InstallIntake::error(503, 'Install ' . $installUuid . ' is still ' . $state->value . '; its events are evaluated once it settles. Retry later.', [
                'match' => $state->value,
            ], ['Retry-After' => '60']);
        }
        if ($state->isRefuted()) {
            return InstallIntake::error(409, 'Install ' . $installUuid . ' was classified ' . $state->value . ' (' . (string) $install['match_reason']
                . '); events for it are not recorded.', ['match' => $state->value]);
        }

        try {
            $decoded = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return InstallIntake::error(400, 'The events body is not valid JSON');
        }
        try {
            $parsed = self::parseBody($decoded, $registration->policy->trustClientRevenue, $this->now());
        } catch (ValidationException $e) {
            return InstallIntake::error(400, $e->getMessage(), ['field_errors' => $e->getFieldErrors()]);
        }
        $events = $parsed['events'];
        $rowId = (int) $install['install_row_id'];
        $bucket = 'app-install-event-cap:r' . $registration->registrationId . ':i' . $rowId;
        $overMessage = 'Install ' . $installUuid . ' has reached its cap on events a minute';
        if ($events === []) {
            // A customer id alone: nothing for the goals, one link, and one
            // unit of the install's event cap — it is a write a public token
            // can make, so it is capped like the events it rides with.
            $charge = $this->installs->admit($bucket, $registration->limits->eventCapPerMinute, 1, $registration, 'event_cap_per_minute', $overMessage);
            if (is_array($charge)) {
                return $charge;
            }

            return ['status' => 200, 'body' => ['data' => [
                'install_uuid' => $installUuid,
                'accepted' => [],
                'duplicates' => [],
            ] + $this->installs->customer($install, $parsed['customer'])]];
        }

        // The install's event cap (plan §7.1), counted per event and spent
        // only by a body that parsed, so a refused batch stores nothing and
        // counts nothing. The bucket is (registration, install row), both
        // ours: an install's budget is its own, and no spelling of the path's
        // uuid reaches another's (CLAUDE.md #16, #17). It is charged for the
        // ids the install does not hold yet — a replayed batch whose answer
        // was lost costs nothing, and is answered even at the cap — and
        // after the engine runs, whatever it did not accept goes back
        // (refund()): ids a concurrent request stored first, a batch it
        // refused, and every way this request fails.
        $subject = $this->engine->installSubject($registration->userId, $rowId);
        $ids = array_values(array_unique(array_map(static fn (GoalEvent $e): string => $e->eventId, $events)));
        $known = count($this->engine->storedEventIds($subject, $ids));
        $cost = count($ids) - $known;
        $charge = null;
        if ($cost > 0) {
            $charge = $this->installs->admit($bucket, $registration->limits->eventCapPerMinute, $cost, $registration, 'event_cap_per_minute', $overMessage);
            if (is_array($charge)) {
                return $charge;
            }
        }

        // A hint for retention only (installs with events are kept). It is
        // written inside the engine's transaction, once the engine has
        // stored at least one new event: set ahead of the evaluation, a batch
        // the engine refuses (a conflicting event id, the event cap) stored
        // nothing and still exempted the install from retention for good;
        // set after the commit, a crash between the two would leave stored
        // events on a row retention may prune.
        $markHasEvents = function (array $accepted) use ($rowId): void {
            $flag = $this->conn->prepareWrite('UPDATE 202_app_installs SET has_events = 1 WHERE install_row_id = ? AND has_events = 0');
            $this->conn->bind($flag, 'i', [$rowId]);
            $this->conn->executeUpdate($flag);
        };

        // How many of the charged events this request stored: from the
        // engine's answer when it gives one, 0 when it refused the batch in
        // its transaction (rolled back), and otherwise measured — an
        // exception says nothing about whether the commit landed, and
        // ingest() can throw after it (CLAUDE.md #13).
        $stored = null;
        try {
            try {
                $result = $this->engine->ingest($registration->userId, $subject, $events, $markHasEvents);
            } catch (GoalEngineException $e) {
                if ($e->reason === GoalEngineException::EVENT_CONFLICT || $e->reason === GoalEngineException::EVENT_CAP) {
                    $stored = 0;
                }

                return match ($e->reason) {
                    GoalEngineException::EVENT_CONFLICT => InstallIntake::error(409, $e->getMessage()),
                    GoalEngineException::EVENT_CAP => InstallIntake::error(422, $e->getMessage()),
                    default => throw $e,
                };
            } catch (\Throwable $e) {
                if (!\Prosper202\Database\Connection::isRetryableLockError($e)) {
                    throw $e;
                }
                // The engine retried once and lost the lock again; the batch
                // rolled back, so the SDK's retry is safe and is told to make it.
                error_log('p202 android events: install ' . $installUuid . ' lost a lock twice; answered 503: ' . $e->getMessage());
                $stored = 0;

                return InstallIntake::error(503, 'The server is busy with this install; retry shortly.', [], ['Retry-After' => '5']);
            }
            $stored = count($result['accepted']);
        } finally {
            if ($charge !== null) {
                $stored ??= $this->landed($subject, $ids, $known, $installUuid);
                if ($charge->cost > $stored) {
                    $this->installs->refund($charge, $charge->cost - $stored, 'install ' . $installUuid . '\'s batch stored ' . $stored . ' of the ' . $charge->cost . ' events charged');
                }
            }
        }

        return ['status' => 200, 'body' => ['data' => [
            'install_uuid' => $installUuid,
            'accepted' => $result['accepted'],
            'duplicates' => $result['duplicates'],
        ] + $this->installs->customer($install, $parsed['customer'])]];
    }

    /**
     * How many of $ids the install holds now beyond the $known it held
     * before the batch, after a failure that did not say whether the batch
     * committed. Ids another request stored meanwhile count as landed: the
     * error is in the direction that refunds less. A read that fails
     * refunds nothing, for the same reason, and is logged.
     *
     * @param list<string> $ids
     */
    private function landed(\Prosper202\Goals\GoalSubject $subject, array $ids, int $known, string $installUuid): int
    {
        try {
            return max(0, count($this->engine->storedEventIds($subject, $ids)) - $known);
        } catch (\Throwable $e) {
            error_log('p202 android events: install ' . $installUuid . '\'s batch failed and what it stored could not be read, so nothing is refunded: ' . $e->getMessage());

            return PHP_INT_MAX;
        }
    }

    /**
     * A request body, validated (plan §5.5, the vectors in
     * tests/fixtures/app-sdk-contract/android/events-requests.json): its
     * events — the server stamps received_at and revenue trust on every
     * one, and a device that sends either is refused by name — and its
     * customer claim. `events` may be left out only when `customer` is
     * there; when present it holds 1 to MAX_EVENTS events.
     *
     * @return array{events: list<GoalEvent>, customer: CustomerClaim|null}
     * @throws ValidationException naming every bad field
     */
    public static function parseBody(mixed $decoded, bool $revenueTrusted, int $now): array
    {
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ValidationException('The events body must be a JSON object: {"events": [ … ]}', ['body' => 'must be a JSON object']);
        }
        $unknown = array_diff(array_map('strval', array_keys($decoded)), ['events', 'customer']);
        if ($unknown !== []) {
            throw new ValidationException('The events body is invalid', array_fill_keys(
                array_values($unknown),
                'is not a field here (the body is {"events": [ … ], "customer": …})'
            ));
        }
        $errors = [];
        $customer = CustomerClaim::fromWire($decoded['customer'] ?? null, 'customer', $errors);
        $list = $decoded['events'] ?? null;
        if ($list === null && ($decoded['customer'] ?? null) !== null) {
            // A customer on its own: valid, or refused for its own fields.
            if ($errors !== []) {
                ksort($errors);
                throw new ValidationException('The events body is invalid', $errors);
            }

            return ['events' => [], 'customer' => $customer];
        }
        if (!is_array($list) || !array_is_list($list) || $list === [] || count($list) > self::MAX_EVENTS) {
            $errors['events'] = 'is required: a list of 1-' . self::MAX_EVENTS
                . ' events (it may be left out only when the body carries a customer)';
            ksort($errors);
            throw new ValidationException('The events body is invalid', $errors);
        }
        $events = [];
        foreach ($list as $i => $raw) {
            $path = 'events[' . $i . ']';
            if (is_array($raw) && !array_is_list($raw)) {
                foreach (array_keys($raw) as $key) {
                    if (!in_array((string) $key, self::DEVICE_FIELDS, true)) {
                        $errors[$path . '.' . $key] = in_array((string) $key, ['received_at', 'revenue_trusted'], true)
                            ? 'is set by the server, never by the app'
                            : 'is not an event field (allowed: ' . implode(', ', self::DEVICE_FIELDS) . ')';
                    }
                }
                $raw['received_at'] = $now;
                $raw['revenue_trusted'] = $revenueTrusted;
            }
            try {
                $events[] = GoalEvent::fromArray($raw, $path);
            } catch (InvalidGoalDefinition $e) {
                $errors += $e->errors();
            }
        }
        if ($errors !== []) {
            ksort($errors);
            throw new ValidationException('The events are invalid', $errors);
        }

        return ['events' => $events, 'customer' => $customer];
    }
}
