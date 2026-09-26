<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Api\V3\Apps\AppRegistration;
use Api\V3\Exception\ValidationException;
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
 * The body is {"events": [ … ]}, 1 to MAX_EVENTS per request (an offline
 * queue flushes in batches). Each event is {event_id, name, occurred_at,
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

    /** @param (callable(): int)|null $clock */
    public function __construct(\mysqli $db, private $clock = null, ?GoalEngine $engine = null)
    {
        $this->conn = new Connection($db);
        $this->engine = $engine ?? new GoalEngine($this->conn, new MysqlGoalRepository($this->conn), null, $clock);
        $this->installs = new InstallIntake($db, $clock, $this->engine);
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
            $events = self::parseEvents($decoded, $registration->policy->trustClientRevenue, $this->now());
        } catch (ValidationException $e) {
            return InstallIntake::error(400, $e->getMessage(), ['field_errors' => $e->getFieldErrors()]);
        }

        $rowId = (int) $install['install_row_id'];
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

        try {
            $result = $this->engine->ingest($registration->userId, $this->engine->installSubject($registration->userId, $rowId), $events, $markHasEvents);
        } catch (GoalEngineException $e) {
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

            return InstallIntake::error(503, 'The server is busy with this install; retry shortly.', [], ['Retry-After' => '5']);
        }

        return ['status' => 200, 'body' => ['data' => [
            'install_uuid' => $installUuid,
            'accepted' => $result['accepted'],
            'duplicates' => $result['duplicates'],
        ]]];
    }

    /**
     * The events of a request body, validated (plan §5.5, the vectors in
     * tests/fixtures/app-sdk-contract/android/events-requests.json): the
     * server stamps received_at and revenue trust on every event, and a
     * device that sends either is refused by name.
     *
     * @return list<GoalEvent>
     * @throws ValidationException naming every bad field
     */
    public static function parseEvents(mixed $decoded, bool $revenueTrusted, int $now): array
    {
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ValidationException('The events body must be a JSON object: {"events": [ … ]}', ['body' => 'must be a JSON object']);
        }
        $unknown = array_diff(array_map('strval', array_keys($decoded)), ['events']);
        if ($unknown !== []) {
            throw new ValidationException('The events body is invalid', array_fill_keys(
                array_values($unknown),
                'is not a field here (the body is {"events": [ … ]})'
            ));
        }
        $list = $decoded['events'] ?? null;
        if (!is_array($list) || !array_is_list($list) || $list === [] || count($list) > self::MAX_EVENTS) {
            throw new ValidationException('The events body is invalid', [
                'events' => 'is required: a list of 1-' . self::MAX_EVENTS . ' events',
            ]);
        }
        $errors = [];
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

        return $events;
    }
}
