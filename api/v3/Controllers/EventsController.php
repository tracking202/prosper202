<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Prosper202\Database\Connection;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\GoalEngineException;
use Prosper202\Goals\GoalEvent;
use Prosper202\Goals\InvalidGoalDefinition;
use Prosper202\Goals\TrafficSourceNotifier;
use Prosper202\Goals\WebEvents;

/**
 * POST /events (plan §2.2): a web campaign's events, keyed by click_id.
 *
 * The events are stored on the click and evaluated by its campaign's goals
 * in one transaction (GoalEngine::ingest()); what the goals reach is
 * recorded through the conversion ledger and, for payable goals the
 * campaign notifies for, sent to the click's traffic source as a
 * server-to-server postback. An event on its own records nothing: on a
 * campaign with no goals it is stored, and a goal added later can be
 * applied to it by re-evaluation (POST /goals/{id}/reevaluation).
 *
 * Every event carries its own `event_id`, so a retried request is answered
 * with its events as duplicates and writes nothing again; the same id with
 * different content is a 409. The body is strict (an unknown field is a
 * 422 naming it) and read raw, never cast (CLAUDE.md #4, #18).
 *
 * `revenue` is trusted on this path: an API key is the operator's own
 * statement, as it is for POST /conversions, so a goal valued
 * `from_property` pays it. `received_at` and `revenue_trusted` are the
 * intake's to decide and are refused in the body.
 */
final class EventsController
{
    public const MAX_EVENTS = 100;
    private const EVENT_FIELDS = ['event_id', 'name', 'occurred_at', 'properties', 'revenue', 'transaction_id'];

    private Connection $conn;

    /**
     * @param (callable(): int)|null $clock the request's time, read once per
     *        request (tests pass one; the router does not)
     */
    public function __construct(private readonly \mysqli $db, private readonly int $userId, private $clock = null)
    {
        $this->conn = new Connection($db);
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload): array
    {
        $unknown = array_diff(array_map('strval', array_keys($payload)), ['click_id', 'events']);
        if ($unknown !== []) {
            throw new ValidationException('Unknown field', array_fill_keys(array_values($unknown), 'is not accepted here (accepted: click_id, events)'));
        }
        $clickId = self::clickId($payload['click_id'] ?? null);
        $raw = $payload['events'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_EVENTS) {
            throw new ValidationException('Invalid events', ['events' => 'must be a list of 1-' . self::MAX_EVENTS . ' events: {"event_id", "name", "occurred_at"?, "properties"?, "revenue"?, "transaction_id"?}']);
        }

        // One reading of the clock for the whole request: the events are
        // received at it, and whether the campaign's goals evaluate them is
        // decided at it. A second read could land past a goal's end and
        // answer goals_evaluated: false beside the outcomes it wrote.
        $now = $this->clock !== null ? (int) ($this->clock)() : time();
        $events = [];
        $errors = [];
        foreach ($raw as $i => $e) {
            $path = 'events[' . $i . ']';
            if (!is_array($e) || ($e !== [] && array_is_list($e))) {
                $errors[$path] = 'must be an object';
                continue;
            }
            foreach (array_keys($e) as $key) {
                if (!in_array((string) $key, self::EVENT_FIELDS, true)) {
                    $errors[$path . '.' . $key] = in_array((string) $key, ['received_at', 'revenue_trusted'], true)
                        ? 'is set by the server, not the caller'
                        : 'is not a field here (allowed: ' . implode(', ', self::EVENT_FIELDS) . ')';
                }
            }
            if (!array_key_exists('event_id', $e)) {
                $errors[$path . '.event_id'] = 'is required: your id for this event, so a retry is recognised as the same event';
            }
            if (array_key_exists('occurred_at', $e) && !is_int($e['occurred_at'])) {
                $errors[$path . '.occurred_at'] = 'must be a unix time in seconds';
            }
            try {
                $event = GoalEvent::fromArray([
                    'occurred_at' => $e['occurred_at'] ?? $now,
                ] + array_intersect_key($e, array_flip(self::EVENT_FIELDS)) + [
                    'received_at' => $now,
                    'revenue_trusted' => true,
                ], $path);
                // Without an occurred_at the time is this server's, and a
                // retry of the event later is still the same event.
                $events[] = array_key_exists('occurred_at', $e) ? $event : $event->clockedByServer();
            } catch (InvalidGoalDefinition $ex) {
                $errors += $ex->errors();
            }
        }
        if ($errors !== []) {
            ksort($errors);
            throw new ValidationException('The events are invalid', $errors);
        }

        $notifier = new TrafficSourceNotifier($this->conn, false);
        $engine = new GoalEngine($this->conn, null, null, null, $notifier);
        $result = $this->guard(function () use ($engine, $clickId, $events, $now): array {
            $subject = $engine->clickSubject($this->userId, $clickId);
            $evaluates = (new WebEvents($this->conn))->campaignEvaluatesGoals($this->userId, (int) $subject->campaignId, $now);

            return ['evaluates' => $evaluates, 'campaign_id' => (int) $subject->campaignId] + $engine->ingest($this->userId, $subject, $events);
        });

        $data = [
            'click_id' => $clickId,
            'campaign_id' => $result['campaign_id'],
            'accepted' => $result['accepted'],
            'duplicates' => $result['duplicates'],
            'replayed' => $result['replayed'],
            'goals_evaluated' => $result['evaluates'],
            'outcomes_written' => $result['outcomes_written'],
            'outcomes_retired' => $result['outcomes_retired'],
            'outcomes' => array_map(static fn (array $o): array => [
                'goal_id' => $o['goal_id'],
                'version' => $o['version'],
                'n' => $o['n'],
                'event_id' => $o['event_id'],
                'outcome_id' => $o['outcome_id'],
                'conversion_id' => $o['conversion_id'],
                'payable' => $o['payable'],
                'amount' => $o['amount'],
                'notify' => $o['kind'],
            ], $result['outcomes']),
            'notifications' => $result['notifications'],
        ];
        if (!$result['evaluates']) {
            $data['note'] = 'Campaign ' . $result['campaign_id'] . ' has no live goals, so these events were stored and evaluated against nothing. '
                . 'Add a goal (POST /goals) and apply it to stored events with POST /goals/{id}/reevaluation.';
        }

        return ['_status' => $result['accepted'] !== [] ? 201 : 200, 'data' => $data];
    }

    /**
     * A click id as a JSON integer or a string of digits the int cast
     * leaves unchanged; 1.5, "1e3" and " 7" are refused, never rewritten
     * into another click (CLAUDE.md #18).
     */
    private static function clickId(mixed $value): int
    {
        $id = match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^[1-9]\d{0,18}$/D', $value) === 1 && (string) (int) $value === $value => (int) $value,
            default => 0,
        };
        if ($id <= 0) {
            throw new ValidationException('Invalid click_id', ['click_id' => 'is required: a click id from GET /clicks (a whole number greater than 0)']);
        }

        return $id;
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (GoalEngineException $e) {
            throw match ($e->reason) {
                GoalEngineException::NOT_FOUND => new NotFoundException($e->getMessage(), $e),
                GoalEngineException::EVENT_CONFLICT => new ConflictException($e->getMessage(), [], $e),
                GoalEngineException::EVENT_CAP => new ValidationException($e->getMessage(), ['events' => $e->getMessage()], $e),
                GoalEngineException::INVALID => new ValidationException($e->getMessage(), $e->fieldErrors, $e),
                default => self::logged(new DatabaseException('Event data could not be read', $e), $e),
            };
        } catch (\Api\V3\HttpException $e) {
            throw $e;
        } catch (\Prosper202\Database\Exceptions\QueryException $e) {
            throw self::logged(new DatabaseException('Event query failed', $e), $e);
        }
    }

    private static function logged(DatabaseException $out, \Throwable $cause): DatabaseException
    {
        error_log('p202 events: ' . $cause->getMessage());

        return $out;
    }
}
