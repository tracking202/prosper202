<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Apps\Apple\SkanEncodingRules;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Prosper202\Database\Connection;
use Prosper202\Goals\EvaluationTooLarge;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\GoalEngineException;
use Prosper202\Goals\GoalEvaluator;
use Prosper202\Goals\GoalEvent;
use Prosper202\Goals\GoalScope;
use Prosper202\Goals\GoalSpec;
use Prosper202\Goals\GoalSubject;
use Prosper202\Goals\InvalidGoalDefinition;
use Prosper202\Goals\MysqlGoalRepository;

/**
 * /goals (plan §2.2, §5.5): goal definitions and their versions, the
 * campaigns that pay for them, their outcomes, and the two computations an
 * editor or an agent needs before writing anything — validate a definition,
 * and evaluate definitions against events.
 *
 * Every id and amount is read from the raw decoded body, never after a cast
 * (CLAUDE.md #18), and every body is strict: a field this endpoint does not
 * take is a 422 naming it, never ignored (#4).
 */
final class GoalsController
{
    private const MAX_LIMIT = 500;
    private const MAX_EVALUATE_GOALS = 50;
    private const MAX_EVALUATE_VERSIONS = 20;
    private const MAX_EVALUATE_EVENTS = 1000;
    /** The most outcomes one /goals/evaluate answer may hold. */
    private const MAX_EVALUATE_OUTCOMES = 10000;

    private Connection $conn;
    private MysqlGoalRepository $goals;

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
        $this->conn = new Connection($db);
        $this->goals = new MysqlGoalRepository($this->conn);
    }

    // ─── Reads ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    public function list(array $params): array
    {
        self::onlyKeys($params, ['scope', 'scope_id', 'campaign_id', 'registration_id', 'include_archived', 'limit', 'offset'], 'query');
        $filters = [];
        if (isset($params['campaign_id'])) {
            $filters['scope'] = GoalScope::CAMPAIGN->value;
            $filters['scope_id'] = self::id($params['campaign_id'], 'campaign_id');
        }
        if (isset($params['registration_id'])) {
            if (isset($filters['scope'])) {
                throw new ValidationException('Filter by one owner', ['registration_id' => 'campaign_id and registration_id are exclusive']);
            }
            $filters['scope'] = GoalScope::REGISTRATION->value;
            $filters['scope_id'] = self::id($params['registration_id'], 'registration_id');
        }
        if (isset($params['scope'])) {
            $scope = GoalScope::tryFrom((string) $params['scope']);
            if ($scope === null || (isset($filters['scope']) && $filters['scope'] !== $scope->value)) {
                throw new ValidationException('Invalid scope', ['scope' => 'must be campaign, registration or account']);
            }
            $filters['scope'] = $scope->value;
        }
        if (isset($params['scope_id'])) {
            $filters['scope_id'] = self::id($params['scope_id'], 'scope_id', allowZero: true);
        }
        $filters['include_archived'] = self::flag($params['include_archived'] ?? '0', 'include_archived');
        [$limit, $offset] = self::paging($params);

        $page = $this->guard(fn () => $this->goals->list($this->userId, $filters, $offset, $limit));

        return [
            'data' => array_map(fn (array $row): array => $this->present($row, false), $page['rows']),
            'pagination' => ['total' => $page['total'], 'limit' => $limit, 'offset' => $offset],
        ];
    }

    public function get(int $id): array
    {
        return ['data' => $this->present($this->mustFind($id), true)];
    }

    public function versions(int $id): array
    {
        $this->mustFind($id);
        $rows = $this->guard(fn () => $this->goals->versions($id));

        return ['data' => array_map(static fn (array $v): array => self::presentVersion($v), $rows)];
    }

    public function version(int $id, int $version): array
    {
        $this->mustFind($id);
        $row = $this->guard(fn () => $this->goals->version($id, $version));
        if ($row === null) {
            throw new NotFoundException('Goal ' . $id . ' has no version ' . $version);
        }

        return ['data' => self::presentVersion($row)];
    }

    /** @param array<string, mixed> $params */
    public function outcomes(int $id, array $params): array
    {
        self::onlyKeys($params, ['subject_type', 'subject_id', 'limit', 'offset'], 'query');
        $this->mustFind($id);
        $filters = ['goal_id' => $id];
        if (isset($params['subject_type'])) {
            if (!in_array($params['subject_type'], [GoalSubject::CLICK, GoalSubject::INSTALL], true)) {
                throw new ValidationException('Invalid subject_type', ['subject_type' => 'must be click or install']);
            }
            $filters['subject_type'] = $params['subject_type'];
        }
        if (isset($params['subject_id'])) {
            $filters['subject_id'] = self::id($params['subject_id'], 'subject_id');
        }
        [$limit, $offset] = self::paging($params);
        $rows = $this->guard(fn () => $this->goals->liveOutcomes($this->userId, $filters, $limit, $offset));
        $total = $this->guard(fn () => $this->goals->countLiveOutcomes($this->userId, $filters));

        return [
            'data' => array_map(static fn (array $r): array => [
                'outcome_id' => (int) $r['outcome_id'],
                'subject_type' => (string) $r['subject_type'],
                'subject_id' => (int) $r['subject_id'],
                'goal_id' => (int) $r['goal_id'],
                'version' => (int) $r['goal_version'],
                'n' => (int) $r['n'],
                'event_id' => (string) $r['event_id'],
                'reached_at' => (int) $r['reached_at'],
                'value' => $r['value'] !== null ? (string) $r['value'] : null,
                'value_source' => (string) $r['value_source'],
                'value_note' => $r['value_note'],
                'ineligible_reason' => $r['ineligible_reason'],
                'payable' => (int) $r['payable'] === 1,
                'campaign_id' => $r['campaign_id'] !== null ? (int) $r['campaign_id'] : null,
                'app_registration_id' => $r['app_registration_id'] !== null ? (int) $r['app_registration_id'] : null,
                'conversion_id' => $r['conversion_id'] !== null ? (int) $r['conversion_id'] : null,
            ], $rows),
            'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
        ];
    }

    public function campaigns(int $id): array
    {
        $this->mustFind($id);

        return ['data' => array_map(
            static fn (array $r): array => self::presentTerm($r),
            $this->guard(fn () => $this->goals->campaignsForGoal($this->userId, $id))
        )];
    }

    // ─── Writes ─────────────────────────────────────────────────────

    /** @param array<string, mixed> $payload */
    public function create(array $payload): array
    {
        self::onlyKeys($payload, ['scope', 'scope_id', 'definition', 'payable', 'payout', 'notify_traffic_source'], 'body');
        $scope = GoalScope::tryFrom(is_string($payload['scope'] ?? null) ? $payload['scope'] : '');
        if ($scope === null) {
            throw new ValidationException('Invalid scope', [
                'scope' => 'is required: "campaign" (a campaign\'s own goal), "registration" (an app\'s default set) or "account" (every app of the account)',
            ]);
        }
        if ($scope === GoalScope::ACCOUNT) {
            if (array_key_exists('scope_id', $payload) && self::id($payload['scope_id'], 'scope_id', allowZero: true) !== 0) {
                throw new ValidationException('Invalid scope_id', ['scope_id' => 'an account goal has no owner id: omit scope_id or send 0']);
            }
            $scopeId = 0;
        } else {
            if (!array_key_exists('scope_id', $payload)) {
                throw new ValidationException('Missing scope_id', [
                    'scope_id' => $scope === GoalScope::CAMPAIGN ? 'is required: a campaign id from GET /campaigns' : 'is required: a registration id from GET /apps',
                ]);
            }
            $scopeId = self::id($payload['scope_id'], 'scope_id');
        }
        $definition = $this->parseDefinition($payload['definition'] ?? null, null);

        $payoutGiven = array_key_exists('payout', $payload) && $payload['payout'] !== null;
        $payoutUnits = $payoutGiven ? self::amount($payload['payout'], 'payout') : null;
        $notify = array_key_exists('notify_traffic_source', $payload) ? self::bool($payload['notify_traffic_source'], 'notify_traffic_source') : true;
        $payable = array_key_exists('payable', $payload)
            ? self::bool($payload['payable'], 'payable')
            : ($payoutGiven || $definition->valueType !== 'none');
        if ($scope !== GoalScope::CAMPAIGN && (array_key_exists('payable', $payload) || $payoutGiven || array_key_exists('notify_traffic_source', $payload))) {
            throw new ValidationException('Payouts belong to campaigns', [
                'payable' => 'payable, payout and notify_traffic_source apply to a campaign goal; attach this goal to a campaign with '
                    . 'PUT /goals/{id}/campaigns/{campaign_id} to pay for it there',
            ]);
        }
        if (!$payable && ($payoutGiven || array_key_exists('notify_traffic_source', $payload))) {
            throw new ValidationException('A tracked goal has no payout', ['payout' => 'payout and notify_traffic_source need payable: true']);
        }

        $goalId = $this->guard(function () use ($scope, $scopeId, $definition, $payable, $payoutUnits, $notify): int {
            return $this->conn->transaction(function () use ($scope, $scopeId, $definition, $payable, $payoutUnits, $notify): int {
                $this->assertOwner($scope, $scopeId);
                $this->assertNameFree($scope, $scopeId, $definition->name, null);
                $this->assertPrerequisites($scope, $scopeId, $definition, null);
                $now = time();
                $goalId = $this->goals->create($this->userId, $scope, $scopeId, $definition, $now);
                if ($scope === GoalScope::CAMPAIGN && $payable) {
                    $this->goals->attach($this->userId, $scopeId, $goalId, $payoutUnits, $notify, $now);
                }

                return $goalId;
            });
        });

        return ['data' => $this->present($this->mustFind($goalId), true)];
    }

    /** @param array<string, mixed> $payload */
    public function update(int $id, array $payload): array
    {
        self::onlyKeys($payload, ['definition'], 'body');
        if (!array_key_exists('definition', $payload)) {
            throw new ValidationException('Nothing to update', ['definition' => 'is required: the goal\'s whole new definition (an edit is a new version)']);
        }
        $definition = $this->parseDefinition($payload['definition'], $id);

        $result = $this->guard(function () use ($id, $definition): array {
            return $this->conn->transaction(function () use ($id, $definition): array {
                $goal = $this->goals->lockGoal($this->userId, $id);
                if ($goal['archived_at'] !== null) {
                    throw new ConflictException('Goal ' . $id . ' is archived; archived goals are kept for their history and cannot be edited.');
                }
                self::refuseBuiltin($goal, 'edited');
                $scope = GoalScope::fromStored($goal['scope']);
                $scopeId = (int) $goal['scope_id'];
                $this->assertNameFree($scope, $scopeId, $definition->name, $id);
                $this->assertPrerequisites($scope, $scopeId, $definition, $id);
                $this->assertEncodedGoalsStayReachable($id, $definition);

                return $this->goals->addVersion($this->userId, $id, $definition, time());
            });
        });

        return ['data' => $this->present($this->mustFind($id), true) + ['version_created' => $result['created']]];
    }

    /**
     * Archive the goal: it stops evaluating events received from now on and
     * keeps its history (versions, outcomes, conversions). Refused while an
     * SKAN encoding or another live goal's `after` names it.
     */
    public function delete(int $id): void
    {
        $this->guard(function () use ($id): void {
            $this->conn->transaction(function () use ($id): void {
                $goal = $this->goals->lockGoal($this->userId, $id);
                if ($goal['archived_at'] !== null) {
                    return;
                }
                self::refuseBuiltin($goal, 'archived');
                $blocked = $this->blockers($id);
                if ($blocked['skan_encodings'] !== [] || $blocked['dependents'] !== []) {
                    throw new ConflictException($this->blockerMessage($id, $blocked), $blocked);
                }
                $this->goals->archive($this->userId, $id, time());
            });
        });
    }

    public function deletePreview(int $id): array
    {
        $goal = $this->mustFind($id);
        $blocked = $this->blockers($id);

        return ['data' => [
            'goal' => $this->present($goal, true),
            'action' => $goal['archived_at'] !== null ? 'none (already archived)' : 'archive',
            'blocked_by' => $blocked,
            'can_delete' => $blocked['skan_encodings'] === [] && $blocked['dependents'] === [],
            'kept' => 'versions, outcomes and their conversions are kept; the goal stops evaluating events received after the archive',
        ]];
    }

    /** @param array<string, mixed> $payload */
    public function attachCampaign(int $id, int $campaignId, array $payload): array
    {
        self::onlyKeys($payload, ['payout', 'notify_traffic_source'], 'body');
        $payoutUnits = array_key_exists('payout', $payload) && $payload['payout'] !== null ? self::amount($payload['payout'], 'payout') : null;
        $notify = array_key_exists('notify_traffic_source', $payload) ? self::bool($payload['notify_traffic_source'], 'notify_traffic_source') : true;

        $this->guard(function () use ($id, $campaignId, $payoutUnits, $notify): void {
            $this->conn->transaction(function () use ($id, $campaignId, $payoutUnits, $notify): void {
                $goal = $this->goals->lockGoal($this->userId, $id);
                if ($goal['archived_at'] !== null) {
                    throw new ConflictException('Goal ' . $id . ' is archived and cannot be attached to a campaign.');
                }
                if ($this->goals->campaign($this->userId, $campaignId) === null) {
                    throw new NotFoundException('Campaign ' . $campaignId . ' not found');
                }
                $scope = GoalScope::fromStored($goal['scope']);
                if ($scope === GoalScope::CAMPAIGN && (int) $goal['scope_id'] !== $campaignId) {
                    throw new ValidationException('A campaign goal belongs to one campaign', [
                        'campaign_id' => 'goal ' . $id . ' is campaign ' . (int) $goal['scope_id'] . '\'s own goal; create a goal on campaign '
                            . $campaignId . ', or use a registration or account goal to share one between campaigns',
                    ]);
                }
                $this->goals->attach($this->userId, $campaignId, $id, $payoutUnits, $notify, time());
            });
        });

        foreach ($this->goals->campaignsForGoal($this->userId, $id) as $row) {
            if ((int) $row['campaign_id'] === $campaignId) {
                return ['data' => self::presentTerm($row)];
            }
        }
        throw new DatabaseException('The campaign goal was written but could not be read back');
    }

    public function detachCampaignPreview(int $id, int $campaignId): array
    {
        $this->mustFind($id);
        foreach ($this->guard(fn () => $this->goals->campaignsForGoal($this->userId, $id)) as $row) {
            if ((int) $row['campaign_id'] === $campaignId) {
                return ['data' => [
                    'record' => self::presentTerm($row),
                    'action' => 'delete',
                    'effect' => 'outcomes of goal ' . $id . ' on campaign ' . $campaignId . ' are tracked, not paid, from now on; '
                        . 'conversions already recorded are kept',
                ]];
            }
        }
        throw new NotFoundException('Campaign ' . $campaignId . ' does not pay for goal ' . $id);
    }

    public function detachCampaign(int $id, int $campaignId): void
    {
        $this->mustFind($id);
        $removed = $this->guard(fn () => $this->goals->detach($this->userId, $campaignId, $id));
        if ($removed === 0) {
            throw new NotFoundException('Campaign ' . $campaignId . ' does not pay for goal ' . $id);
        }
    }

    // ─── Computations ───────────────────────────────────────────────

    /** @param array<string, mixed> $payload */
    public function validate(array $payload): array
    {
        self::onlyKeys($payload, ['definition', 'goal_id'], 'body');
        $selfId = array_key_exists('goal_id', $payload) ? self::id($payload['goal_id'], 'goal_id') : null;
        $definition = $this->parseDefinition($payload['definition'] ?? null, $selfId);

        return ['data' => [
            'valid' => true,
            'definition' => $definition->toArray(),
            'plain_event' => $definition->isPlainEvent(),
        ]];
    }

    /**
     * Evaluate goal definitions against events, writing nothing: the
     * vectors' format (tests/fixtures/app-sdk-contract/goals/README.md).
     * A goal may be given as {"goal_id", "definition"} for a single version
     * effective from 0.
     *
     * @param array<string, mixed> $payload
     */
    public function evaluate(array $payload): array
    {
        self::onlyKeys($payload, ['goals', 'subject', 'events'], 'body');
        $goals = $payload['goals'] ?? null;
        if (!is_array($goals) || !array_is_list($goals) || $goals === [] || count($goals) > self::MAX_EVALUATE_GOALS) {
            throw new ValidationException('Invalid goals', ['goals' => 'must be a list of 1-' . self::MAX_EVALUATE_GOALS . ' goals']);
        }
        $specs = [];
        $seen = [];
        foreach ($goals as $i => $g) {
            $path = 'goals[' . $i . ']';
            if (!is_array($g) || array_is_list($g)) {
                throw new ValidationException('Invalid goal', [$path => 'must be an object']);
            }
            self::onlyKeys($g, ['goal_id', 'definition', 'versions', 'starts_at', 'ends_at'], $path);
            $goalId = self::id($g['goal_id'] ?? null, $path . '.goal_id');
            if (isset($seen[$goalId])) {
                throw new ValidationException('Invalid goal', [$path . '.goal_id' => 'goal ' . $goalId . ' is listed twice']);
            }
            $seen[$goalId] = true;
            if (array_key_exists('definition', $g) === array_key_exists('versions', $g)) {
                throw new ValidationException('Invalid goal', [$path => 'give either definition or versions']);
            }
            $versions = array_key_exists('definition', $g)
                ? [['version' => 1, 'effective_at' => 0, 'definition' => $g['definition']]]
                : $g['versions'];
            if (!is_array($versions) || !array_is_list($versions) || $versions === [] || count($versions) > self::MAX_EVALUATE_VERSIONS) {
                throw new ValidationException('Invalid goal', [$path . '.versions' => 'must be a list of 1-' . self::MAX_EVALUATE_VERSIONS . ' versions']);
            }
            $clean = [];
            foreach ($versions as $j => $v) {
                $vp = $path . '.versions[' . $j . ']';
                if (!is_array($v) || array_is_list($v)) {
                    throw new ValidationException('Invalid version', [$vp => 'must be an object']);
                }
                self::onlyKeys($v, ['version', 'effective_at', 'definition'], $vp);
                $clean[] = [
                    'version' => self::id($v['version'] ?? null, $vp . '.version'),
                    'effective_at' => self::time($v['effective_at'] ?? null, $vp . '.effective_at'),
                    'definition' => $v['definition'] ?? null,
                ];
            }
            $specs[] = new GoalSpec(
                $goalId,
                $clean,
                array_key_exists('starts_at', $g) ? self::time($g['starts_at'], $path . '.starts_at') : 0,
                array_key_exists('ends_at', $g) && $g['ends_at'] !== null ? self::time($g['ends_at'], $path . '.ends_at') : null,
            );
        }

        $s = $payload['subject'] ?? null;
        if (!is_array($s) || array_is_list($s)) {
            throw new ValidationException('Invalid subject', ['subject' => 'must be an object: {"type": "click" | "install", "click_at", "install_at"}']);
        }
        self::onlyKeys($s, ['type', 'click_at', 'install_at', 'rebases'], 'subject');
        if (!in_array($s['type'] ?? null, [GoalSubject::CLICK, GoalSubject::INSTALL], true)) {
            throw new ValidationException('Invalid subject', ['subject.type' => 'must be click or install']);
        }
        $rebases = [];
        if (isset($s['rebases'])) {
            if (!is_array($s['rebases']) || ($s['rebases'] !== [] && array_is_list($s['rebases']))) {
                throw new ValidationException('Invalid subject', ['subject.rebases' => 'must be an object: {"<goal id>": <version>}']);
            }
            foreach ($s['rebases'] as $goalId => $version) {
                $rebases[self::id((string) $goalId, 'subject.rebases')] = self::id($version, 'subject.rebases.' . $goalId);
            }
        }
        $subject = new GoalSubject(
            (string) $s['type'],
            1,
            isset($s['click_at']) ? self::time($s['click_at'], 'subject.click_at') : null,
            isset($s['install_at']) ? self::time($s['install_at'], 'subject.install_at') : null,
            $rebases,
        );

        $rawEvents = $payload['events'] ?? null;
        if (!is_array($rawEvents) || !array_is_list($rawEvents) || count($rawEvents) > self::MAX_EVALUATE_EVENTS) {
            throw new ValidationException('Invalid events', ['events' => 'must be a list of up to ' . self::MAX_EVALUATE_EVENTS . ' events']);
        }
        $events = [];
        $ids = [];
        foreach ($rawEvents as $i => $e) {
            try {
                $event = GoalEvent::fromArray($e, 'events[' . $i . ']');
            } catch (InvalidGoalDefinition $ex) {
                throw new ValidationException('Invalid event', $ex->errors());
            }
            if (isset($ids[$event->eventId])) {
                throw new ValidationException('Invalid event', ['events[' . $i . '].event_id' => 'event id "' . $event->eventId . '" appears twice']);
            }
            $ids[$event->eventId] = true;
            $events[] = $event;
        }
        foreach ($rebases as $goalId => $version) {
            $known = false;
            foreach ($specs as $spec) {
                foreach ($spec->goalId === $goalId ? $spec->versions : [] as $v) {
                    $known = $known || (int) $v['version'] === $version;
                }
            }
            if (!$known) {
                throw new ValidationException('Invalid subject', ['subject.rebases.' . $goalId => 'names a goal version not in goals']);
            }
        }

        try {
            $result = GoalEvaluator::evaluateAll($specs, $subject, $events, self::MAX_EVALUATE_OUTCOMES);
        } catch (EvaluationTooLarge $e) {
            throw new ValidationException('The evaluation is too large', [
                'events' => 'these goals and events reach more than ' . $e->limit . ' outcomes, the most one evaluation answers; '
                    . 'evaluate fewer events or goals at a time',
            ]);
        }

        return ['data' => $result->toArray()];
    }

    /** @param array<string, mixed> $params */
    public function reevaluationPreview(int $id, array $params): array
    {
        self::onlyKeys($params, ['version', 'limit', 'after', 'subject_type'], 'query');

        return ['data' => $this->reevaluation($id, $params, false)];
    }

    /** @param array<string, mixed> $payload */
    public function reevaluate(int $id, array $payload): array
    {
        self::onlyKeys($payload, ['version', 'limit', 'after', 'subject_type'], 'body');

        return ['data' => $this->reevaluation($id, $payload, true)];
    }

    /** @param array<string, mixed> $input */
    private function reevaluation(int $id, array $input, bool $apply): array
    {
        $this->mustFind($id);
        $version = array_key_exists('version', $input) ? self::id($input['version'], 'version') : null;
        $limit = array_key_exists('limit', $input) ? self::id($input['limit'], 'limit') : 100;
        if ($limit > GoalEngine::MAX_SUBJECTS_PER_CALL) {
            throw new ValidationException('Invalid limit', ['limit' => 'at most ' . GoalEngine::MAX_SUBJECTS_PER_CALL . ' subjects per call']);
        }
        $after = array_key_exists('after', $input) ? self::id($input['after'], 'after', allowZero: true) : 0;
        $subjectType = null;
        if (array_key_exists('subject_type', $input)) {
            if (!in_array($input['subject_type'], [GoalSubject::CLICK, GoalSubject::INSTALL], true)) {
                throw new ValidationException('Invalid subject_type', ['subject_type' => 'must be click or install (default: click for a campaign goal, install otherwise)']);
            }
            $subjectType = (string) $input['subject_type'];
        }

        // An outcome the old version never reached is new to the traffic
        // source and is announced like any other; a replacement is not
        // (GoalEngine decides which is which).
        $engine = new GoalEngine($this->conn, $this->goals, null, null, new \Prosper202\Goals\TrafficSourceNotifier($this->conn, false));

        return $this->guard(fn () => $engine->reevaluate($this->userId, $id, $version, $apply, $limit, $after, $subjectType));
    }

    // ─── Checks ─────────────────────────────────────────────────────

    private function parseDefinition(mixed $raw, ?int $selfId): GoalDefinition
    {
        if ($raw === null) {
            throw new ValidationException('Missing definition', ['definition' => 'is required: {"name": …, "trigger": …}']);
        }
        try {
            $definition = GoalDefinition::parse($raw, $selfId);
        } catch (InvalidGoalDefinition $e) {
            $errors = [];
            foreach ($e->errors() as $path => $message) {
                $errors[$path === 'definition' ? 'definition' : 'definition.' . $path] = $message;
            }
            throw new ValidationException('The goal definition is invalid', $errors);
        }
        if (strlen($definition->toJson()) > GoalDefinition::MAX_JSON_BYTES) {
            throw new ValidationException('The goal definition is invalid', ['definition' => 'is longer than ' . GoalDefinition::MAX_JSON_BYTES . ' bytes']);
        }

        return $definition;
    }

    private function assertOwner(GoalScope $scope, int $scopeId): void
    {
        if ($scope === GoalScope::CAMPAIGN && $this->goals->campaign($this->userId, $scopeId) === null) {
            throw new ValidationException('Invalid scope_id', ['scope_id' => 'No campaign ' . $scopeId . ' in this account. Use an id from GET /campaigns.']);
        }
        if ($scope === GoalScope::REGISTRATION && $this->goals->registration($this->userId, $scopeId) === null) {
            throw new ValidationException('Invalid scope_id', ['scope_id' => 'No app registration ' . $scopeId . ' in this account. Use an id from GET /apps.']);
        }
    }

    private function assertNameFree(GoalScope $scope, int $scopeId, string $name, ?int $exceptId): void
    {
        if ($this->goals->nameTaken($this->userId, $scope, $scopeId, $name, $exceptId)) {
            throw new ConflictException('A goal named "' . $name . '" already exists for this ' . $scope->value . '; goal names are unique per owner.');
        }
    }

    /**
     * Every goal in `after` exists, is live, is this user's, has the same
     * owner, and the edge does not close a cycle over the current versions.
     */
    private function assertPrerequisites(GoalScope $scope, int $scopeId, GoalDefinition $definition, ?int $selfId): void
    {
        foreach ($definition->after as $i => $prereqId) {
            $prereq = $this->goals->find($this->userId, $prereqId);
            if ($prereq === null || $prereq['archived_at'] !== null) {
                throw new ValidationException('Invalid after', ['definition.after[' . $i . ']' => 'No live goal ' . $prereqId . ' in this account. Use an id from GET /goals.']);
            }
            if ((string) $prereq['scope'] !== $scope->value || (int) $prereq['scope_id'] !== $scopeId) {
                throw new ValidationException('Invalid after', [
                    'definition.after[' . $i . ']' => 'goal ' . $prereqId . ' belongs to another ' . (string) $prereq['scope']
                        . '; after may only name goals with the same owner',
                ]);
            }
        }
        if ($selfId === null || $definition->after === []) {
            return; // a new goal has no dependents yet, so it cannot close a cycle
        }
        // Walk the prerequisites' current versions; reaching the goal itself is a cycle.
        $stack = $definition->after;
        $seen = [];
        while ($stack !== []) {
            $goalId = array_pop($stack);
            if ($goalId === $selfId) {
                throw new ValidationException('Invalid after', ['definition.after' => 'this would make goal ' . $selfId . ' wait for itself through its prerequisites']);
            }
            if (isset($seen[$goalId])) {
                continue;
            }
            $seen[$goalId] = true;
            $goal = $this->goals->find($this->userId, $goalId);
            if ($goal === null) {
                continue;
            }
            $current = $this->goals->version($goalId, (int) $goal['current_version']);
            foreach ($current !== null ? MysqlGoalRepository::afterOf($current['definition']) : [] as $next) {
                $stack[] = $next;
            }
        }
    }

    /**
     * An SKAN encoding names a goal the iOS SDK evaluates on the device, so
     * an edit must leave every encoded goal reachable there: the goal
     * itself, and every goal waiting for it through `after`
     * (SkanEncodingRules — the same question the encoding writer asks).
     */
    private function assertEncodedGoalsStayReachable(int $id, GoalDefinition $definition): void
    {
        $rules = new SkanEncodingRules($this->conn->writeConnection(), $this->userId);
        foreach ([$id, ...$rules->dependentsOf($id)] as $goalId) {
            $encodings = $this->encodingsNaming($goalId);
            if ($encodings === []) {
                continue;
            }
            $why = $rules->deviceUnreachable($goalId, [$id => $definition]);
            if ($why !== null) {
                throw new ValidationException('An SKAN encoding depends on this goal', [
                    'definition' => 'SKAN encodings ' . implode(', ', $encodings) . ' name goal ' . $goalId
                        . ($goalId === $id ? '' : ', which waits for this one') . ', and the edit would leave it unreachable on a device: '
                        . $why . ' Point the encodings at another goal first (PUT /apps/skan-encodings/{id}).',
                ]);
            }
        }
    }

    /** @return list<int> */
    private function encodingsNaming(int $goalId): array
    {
        $stmt = $this->conn->prepareWrite('SELECT encoding_id FROM 202_app_skan_encodings WHERE goal_id = ? AND user_id = ? ORDER BY encoding_id');
        $this->conn->bind($stmt, 'ii', [$goalId, $this->userId]);

        return array_map(static fn (array $r): int => (int) $r['encoding_id'], $this->conn->fetchAll($stmt));
    }

    /** @return array{skan_encodings: list<int>, dependents: list<int>} */
    private function blockers(int $goalId): array
    {
        return $this->guard(fn () => [
            'skan_encodings' => $this->encodingsNaming($goalId),
            'dependents' => $this->goals->dependents($this->userId, $goalId),
        ]);
    }

    /** @param array{skan_encodings: list<int>, dependents: list<int>} $blocked */
    private function blockerMessage(int $goalId, array $blocked): string
    {
        $parts = [];
        if ($blocked['skan_encodings'] !== []) {
            $parts[] = 'SKAN encodings ' . implode(', ', $blocked['skan_encodings']) . ' name it (point them at another goal or delete them)';
        }
        if ($blocked['dependents'] !== []) {
            $parts[] = 'goals ' . implode(', ', $blocked['dependents']) . ' wait for it in `after` (edit them first)';
        }

        return 'Goal ' . $goalId . ' cannot be archived: ' . implode('; ', $parts) . '.';
    }

    // ─── Presentation ───────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private function present(array $row, bool $withCampaigns): array
    {
        $goalId = (int) $row['goal_id'];
        $current = $this->guard(fn () => $this->goals->version($goalId, (int) $row['current_version']));
        $definition = null;
        $valid = false;
        if ($current !== null) {
            $decoded = json_decode($current['definition'], true);
            $definition = is_array($decoded) ? $decoded : null;
            try {
                GoalDefinition::fromJson($current['definition'], $goalId);
                $valid = true;
            } catch (InvalidGoalDefinition) {
                $valid = false;
            }
        }
        $out = [
            'goal_id' => $goalId,
            'scope' => (string) $row['scope'],
            'scope_id' => (int) $row['scope_id'],
            'name' => (string) $row['name'],
            'current_version' => (int) $row['current_version'],
            'builtin' => $row['builtin'] !== null ? (string) $row['builtin'] : null,
            'definition' => $definition,
            'definition_valid' => $valid,
            'effective_at' => $current['effective_at'] ?? null,
            'archived_at' => $row['archived_at'] !== null ? (int) $row['archived_at'] : null,
            'created_at' => (int) $row['created_at'],
            'updated_at' => (int) $row['updated_at'],
        ];
        if ($withCampaigns) {
            $out['campaigns'] = array_map(
                static fn (array $r): array => self::presentTerm($r),
                $this->guard(fn () => $this->goals->campaignsForGoal($this->userId, $goalId))
            );
            $out['live_outcomes'] = $this->guard(fn () => $this->goals->countLiveOutcomes($this->userId, ['goal_id' => $goalId]));
        }

        return $out;
    }

    /** @param array{goal_id: int, version: int, definition: string, effective_at: int, created_at: int} $v */
    private static function presentVersion(array $v): array
    {
        $decoded = json_decode($v['definition'], true);

        return [
            'goal_id' => $v['goal_id'],
            'version' => $v['version'],
            'definition' => is_array($decoded) ? $decoded : null,
            'effective_at' => $v['effective_at'],
            'created_at' => $v['created_at'],
        ];
    }

    /** @param array<string, mixed> $r */
    private static function presentTerm(array $r): array
    {
        return [
            'campaign_id' => (int) $r['campaign_id'],
            'goal_id' => (int) $r['goal_id'],
            'campaign_name' => $r['aff_campaign_name'] ?? null,
            'payout_mode' => $r['payout_mode'] ?? null,
            'payout' => $r['payout'] !== null ? (string) $r['payout'] : null,
            'notify_traffic_source' => (int) $r['notify_traffic_source'] === 1,
            'created_at' => (int) $r['created_at'],
            'updated_at' => (int) $r['updated_at'],
        ];
    }

    // ─── Input helpers ──────────────────────────────────────────────

    /**
     * An id from the URL path: digits the int cast leaves unchanged, or 0
     * (which no goal, campaign or version has, so it answers 404). The
     * router hands path segments over as strings, and `(int) '1e3'` is 1000.
     */
    public static function pathId(mixed $segment): int
    {
        $segment = (string) $segment;

        return preg_match('/^[1-9]\d{0,9}$/D', $segment) === 1 && (int) $segment <= 4294967295 ? (int) $segment : 0;
    }

    /** @return array<string, mixed> */
    private function mustFind(int $id): array
    {
        $goal = $this->guard(fn () => $this->goals->find($this->userId, $id));
        if ($goal === null) {
            throw new NotFoundException('Goal ' . $id . ' not found');
        }

        return $goal;
    }

    /**
     * Run a repository call, turning engine refusals into HTTP answers and a
     * database failure into a 500 (its detail goes to the log).
     *
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
                GoalEngineException::INVALID => new ValidationException($e->getMessage(), $e->fieldErrors, $e),
                GoalEngineException::CONFLICT, GoalEngineException::EVENT_CONFLICT => new ConflictException($e->getMessage(), [], $e),
                GoalEngineException::EVENT_CAP => new ValidationException($e->getMessage(), ['events' => $e->getMessage()], $e),
                default => self::logged(new DatabaseException('Goal data could not be read', $e), $e),
            };
        } catch (\Api\V3\HttpException $e) {
            throw $e;
        } catch (\Prosper202\Database\Exceptions\QueryException $e) {
            throw self::logged(new DatabaseException('Goal query failed', $e), $e);
        }
    }

    /**
     * The built-in install goal is the system's, not the operator's: it has
     * one fixed definition and lives as long as its registration. What an
     * operator decides about it is per campaign (attach it with a payout, or
     * list other goals and leave it off).
     *
     * @param array<string, mixed> $goal
     */
    private static function refuseBuiltin(array $goal, string $verb): void
    {
        if (($goal['builtin'] ?? null) !== null) {
            throw new ConflictException('Goal ' . (int) $goal['goal_id'] . ' is the built-in ' . (string) $goal['builtin']
                . ' goal and cannot be ' . $verb . '; attach it to a campaign (PUT /goals/{id}/campaigns/{campaign_id}) to set what an install pays there.');
        }
    }

    private static function logged(DatabaseException $out, \Throwable $cause): DatabaseException
    {
        error_log('p202 goals: ' . $cause->getMessage());

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $allowed
     */
    private static function onlyKeys(array $input, array $allowed, string $where): void
    {
        $errors = [];
        foreach (array_keys($input) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                $errors[$where === 'body' || $where === 'query' ? (string) $key : $where . '.' . $key] = 'is not accepted here (accepted: ' . implode(', ', $allowed) . ')';
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Unknown field', $errors);
        }
    }

    /**
     * A positive id as a JSON integer or a string of digits that the int
     * cast leaves unchanged; 1.5, "1e3", " 1" and a 20-digit string are
     * refused rather than rewritten into some other id (CLAUDE.md #18).
     */
    private static function id(mixed $value, string $field, bool $allowZero = false): int
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9]\d{0,18})$/D', $value) === 1 && (string) (int) $value === $value) {
            $id = (int) $value;
        } else {
            throw new ValidationException('Invalid ' . $field, [$field => 'must be a whole number' . ($allowZero ? '' : ' greater than 0')]);
        }
        if ($id < 0 || (!$allowZero && $id === 0) || $id > 4294967295) {
            throw new ValidationException('Invalid ' . $field, [$field => 'must be a whole number' . ($allowZero ? '' : ' greater than 0')]);
        }

        return $id;
    }

    private static function time(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0 || $value > 4294967295) {
            throw new ValidationException('Invalid ' . $field, [$field => 'must be a unix time in seconds']);
        }

        return $value;
    }

    private static function amount(mixed $value, string $field): int
    {
        $units = GoalDefinition::amountUnits($value);
        if ($units === null) {
            throw new ValidationException('Invalid ' . $field, [$field => 'must be an amount from 0 to 999999.99999 with at most 5 decimal places, or null for the goal\'s own value']);
        }

        return $units;
    }

    private static function bool(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new ValidationException('Invalid ' . $field, [$field => 'must be true or false']);
        }

        return $value;
    }

    private static function flag(mixed $value, string $field): bool
    {
        $v = strtolower(trim((string) $value));
        if (in_array($v, ['1', 'true', 'yes'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'no', ''], true)) {
            return false;
        }
        throw new ValidationException('Invalid ' . $field, [$field => 'must be 1 or 0']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: int, 1: int}
     */
    private static function paging(array $params): array
    {
        $limit = array_key_exists('limit', $params) ? self::id($params['limit'], 'limit') : 50;
        if ($limit > self::MAX_LIMIT) {
            throw new ValidationException('Invalid limit', ['limit' => 'at most ' . self::MAX_LIMIT]);
        }
        $offset = array_key_exists('offset', $params) ? self::id($params['offset'], 'offset', allowZero: true) : 0;

        return [$limit, $offset];
    }
}
