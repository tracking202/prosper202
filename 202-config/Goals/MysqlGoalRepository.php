<?php

declare(strict_types=1);

namespace Prosper202\Goals;

use Prosper202\Conversion\Ledger\Amount;
use Prosper202\Database\Connection;

/**
 * Goal definitions, their versions, campaign payouts, and the reads of
 * outcomes. The engine's per-subject writes live in GoalEngine; this class
 * owns everything an operator edits.
 *
 * Every read of 202_goal_outcomes goes through liveOutcomes() (plan §5.5:
 * "one repository method"), which never returns a retired row, so no
 * report can count an outcome twice after a replay or a re-evaluation.
 */
final class MysqlGoalRepository
{
    public function __construct(private Connection $conn)
    {
    }

    // ─── Goals and versions ─────────────────────────────────────────

    /**
     * Create a goal and its version 1, effective now.
     */
    public function create(int $userId, GoalScope $scope, int $scopeId, GoalDefinition $definition, int $now): int
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_goals (user_id, scope, scope_id, name, current_version, archived_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, NULL, ?, ?)'
        );
        $this->conn->bind($stmt, 'isisii', [$userId, $scope->value, $scopeId, $definition->name, $now, $now]);
        try {
            $goalId = $this->conn->executeInsert($stmt);
        } catch (\Throwable $e) {
            throw self::nameConflictOr($e, $scope, $definition->name);
        }
        if ($goalId <= 0) {
            throw new GoalEngineException('the goal insert returned no id', GoalEngineException::INTEGRITY);
        }
        $this->insertVersion($goalId, 1, $definition, $now);

        return $goalId;
    }

    /**
     * Add a version when the definition differs from the current one.
     * Locks the goal row, so two concurrent edits get consecutive versions.
     *
     * @return array{version: int, created: bool}
     */
    public function addVersion(int $userId, int $goalId, GoalDefinition $definition, int $now): array
    {
        $goal = $this->lockGoal($userId, $goalId);
        $current = $this->version($goalId, (int) $goal['current_version']);
        if ($current !== null && $current['definition'] === $definition->toJson()) {
            return ['version' => (int) $goal['current_version'], 'created' => false];
        }
        $next = (int) $goal['current_version'] + 1;
        $this->insertVersion($goalId, $next, $definition, $now);
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_goals SET name = ?, current_version = ?, updated_at = ? WHERE goal_id = ? AND user_id = ?'
        );
        $this->conn->bind($stmt, 'siiii', [$definition->name, $next, $now, $goalId, $userId]);
        try {
            $updated = $this->conn->executeUpdate($stmt);
        } catch (\Throwable $e) {
            throw self::nameConflictOr($e, GoalScope::fromStored((string) $goal['scope']), $definition->name);
        }
        if ($updated !== 1) {
            throw new GoalEngineException('goal ' . $goalId . ': its current version was not advanced', GoalEngineException::INTEGRITY);
        }

        return ['version' => $next, 'created' => true];
    }

    /**
     * A write that collided with another live goal's name, as the CONFLICT
     * it is; anything else, unchanged.
     *
     * nameTaken() is a plain read, so two concurrent creates (or a create
     * and a rename) of one name both pass it; the `live_name` UNIQUE key is
     * what refuses the second. The key is named in the match so no other
     * duplicate is ever reported as a name clash. 202_goals has no other
     * unique key a write here can hit, but the message is the one fact that
     * says which key it was, so it is read rather than assumed.
     */
    private static function nameConflictOr(\Throwable $e, GoalScope $scope, string $name): \Throwable
    {
        if (!Connection::isMysqlError($e, 1062, 'Duplicate entry')) {
            return $e;
        }
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (preg_match("/for key '(?:[^']*\\.)?live_name'(?: \\[errno 1062\\])?$/", $current->getMessage()) === 1) {
                return new GoalEngineException(
                    'A goal named "' . $name . '" already exists for this ' . $scope->value . '; goal names are unique per owner.',
                    GoalEngineException::CONFLICT,
                    [],
                    $e
                );
            }
        }

        return $e;
    }

    private function insertVersion(int $goalId, int $version, GoalDefinition $definition, int $now): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_goal_versions (goal_id, version, definition, effective_at, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $this->conn->bind($stmt, 'iisii', [$goalId, $version, $definition->toJson(), $now, $now]);
        $this->conn->executeUpdate($stmt);
    }

    /** @return array<string, mixed> the goal row, locked */
    public function lockGoal(int $userId, int $goalId): array
    {
        $stmt = $this->conn->prepareWrite('SELECT * FROM 202_goals WHERE goal_id = ? AND user_id = ? LIMIT 1 FOR UPDATE');
        $this->conn->bind($stmt, 'ii', [$goalId, $userId]);
        $goal = $this->conn->fetchOne($stmt);
        if ($goal === null) {
            throw new GoalEngineException('Goal ' . $goalId . ' not found', GoalEngineException::NOT_FOUND);
        }

        return $goal;
    }

    /** @return array<string, mixed>|null */
    public function find(int $userId, int $goalId): ?array
    {
        $stmt = $this->conn->prepareWrite('SELECT * FROM 202_goals WHERE goal_id = ? AND user_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'ii', [$goalId, $userId]);

        return $this->conn->fetchOne($stmt);
    }

    /** @return array{goal_id: int, version: int, definition: string, effective_at: int, created_at: int}|null */
    public function version(int $goalId, int $version): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT goal_id, version, definition, effective_at, created_at FROM 202_goal_versions WHERE goal_id = ? AND version = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$goalId, $version]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        return [
            'goal_id' => (int) $row['goal_id'],
            'version' => (int) $row['version'],
            'definition' => (string) $row['definition'],
            'effective_at' => (int) $row['effective_at'],
            'created_at' => (int) $row['created_at'],
        ];
    }

    /** @return list<array{goal_id: int, version: int, definition: string, effective_at: int, created_at: int}> */
    public function versions(int $goalId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT goal_id, version, definition, effective_at, created_at FROM 202_goal_versions WHERE goal_id = ? ORDER BY version'
        );
        $this->conn->bind($stmt, 'i', [$goalId]);

        return array_map(static fn (array $r): array => [
            'goal_id' => (int) $r['goal_id'],
            'version' => (int) $r['version'],
            'definition' => (string) $r['definition'],
            'effective_at' => (int) $r['effective_at'],
            'created_at' => (int) $r['created_at'],
        ], $this->conn->fetchAll($stmt));
    }

    /**
     * The current definition of a goal, parsed. A stored definition that no
     * longer parses is an integrity failure named by goal and version.
     */
    public function currentDefinition(int $goalId, int $version): GoalDefinition
    {
        $row = $this->version($goalId, $version);
        if ($row === null) {
            throw new GoalEngineException('goal ' . $goalId . ' has no version ' . $version, GoalEngineException::INTEGRITY);
        }
        try {
            return GoalDefinition::fromJson($row['definition'], $goalId);
        } catch (InvalidGoalDefinition $e) {
            throw new GoalEngineException('goal ' . $goalId . ' version ' . $version . ' is stored invalid: ' . $e->getMessage(), GoalEngineException::INTEGRITY, [], $e);
        }
    }

    /**
     * @param array{scope?: string, scope_id?: int, include_archived?: bool} $filters
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(int $userId, array $filters, int $offset, int $limit): array
    {
        $where = ['user_id = ?'];
        $types = 'i';
        $binds = [$userId];
        if (isset($filters['scope'])) {
            $where[] = 'scope = ?';
            $types .= 's';
            $binds[] = $filters['scope'];
        }
        if (isset($filters['scope_id'])) {
            $where[] = 'scope_id = ?';
            $types .= 'i';
            $binds[] = $filters['scope_id'];
        }
        if (empty($filters['include_archived'])) {
            $where[] = 'archived_at IS NULL';
        }
        $clause = implode(' AND ', $where);

        $count = $this->conn->prepareRead('SELECT COUNT(*) AS n FROM 202_goals WHERE ' . $clause);
        $this->conn->bind($count, $types, $binds);
        $total = (int) (($this->conn->fetchOne($count) ?? [])['n'] ?? 0);

        $stmt = $this->conn->prepareRead('SELECT * FROM 202_goals WHERE ' . $clause . ' ORDER BY goal_id LIMIT ? OFFSET ?');
        $this->conn->bind($stmt, $types . 'ii', [...$binds, $limit, $offset]);

        return ['rows' => $this->conn->fetchAll($stmt), 'total' => $total];
    }

    /**
     * Live goals of one scope whose name is taken (case-insensitive, as the
     * column's collation compares), excluding one goal.
     */
    public function nameTaken(int $userId, GoalScope $scope, int $scopeId, string $name, ?int $exceptGoalId): bool
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT goal_id FROM 202_goals WHERE user_id = ? AND scope = ? AND scope_id = ? AND name = ? AND archived_at IS NULL AND goal_id <> ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'isisi', [$userId, $scope->value, $scopeId, $name, $exceptGoalId ?? 0]);

        return $this->conn->fetchOne($stmt) !== null;
    }

    public function archive(int $userId, int $goalId, int $now): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_goals SET archived_at = ?, updated_at = ? WHERE goal_id = ? AND user_id = ? AND archived_at IS NULL'
        );
        $this->conn->bind($stmt, 'iiii', [$now, $now, $goalId, $userId]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Live goals (of the same user) whose CURRENT version names this goal in
     * `after`.
     *
     * @return list<int>
     */
    public function dependents(int $userId, int $goalId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT g.goal_id, v.definition FROM 202_goals g
             JOIN 202_goal_versions v ON v.goal_id = g.goal_id AND v.version = g.current_version
             WHERE g.user_id = ? AND g.archived_at IS NULL AND g.goal_id <> ?'
        );
        $this->conn->bind($stmt, 'ii', [$userId, $goalId]);
        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $after = self::afterOf((string) $row['definition']);
            if (in_array($goalId, $after, true)) {
                $out[] = (int) $row['goal_id'];
            }
        }

        return $out;
    }

    /**
     * The `after` list of a stored definition, read without validating the
     * rest (a stored definition that no longer parses still names its
     * prerequisites for dependency checks).
     *
     * @return list<int>
     */
    public static function afterOf(string $json): array
    {
        $raw = json_decode($json, true);
        if (!is_array($raw) || !isset($raw['after']) || !is_array($raw['after'])) {
            return [];
        }

        return array_values(array_filter($raw['after'], 'is_int'));
    }

    // ─── Campaign payouts ───────────────────────────────────────────

    /** @return array<int, array<string, mixed>> goal id => the campaign_goals row */
    public function campaignTerms(int $campaignId): array
    {
        $stmt = $this->conn->prepareWrite('SELECT * FROM 202_campaign_goals WHERE campaign_id = ?');
        $this->conn->bind($stmt, 'i', [$campaignId]);
        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $out[(int) $row['goal_id']] = $row;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the campaigns paying for a goal */
    public function campaignsForGoal(int $userId, int $goalId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT cg.campaign_id, cg.goal_id, cg.payout, cg.notify_traffic_source, cg.created_at, cg.updated_at,
                    ac.aff_campaign_name, ac.payout_mode
             FROM 202_campaign_goals cg
             LEFT JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = cg.campaign_id
             WHERE cg.goal_id = ? AND cg.user_id = ? ORDER BY cg.campaign_id'
        );
        $this->conn->bind($stmt, 'ii', [$goalId, $userId]);

        return $this->conn->fetchAll($stmt);
    }

    /**
     * Attach a goal to a campaign as payable, or change its terms.
     *
     * @param int|null $payoutUnits null = the goal's own value
     */
    public function attach(int $userId, int $campaignId, int $goalId, ?int $payoutUnits, bool $notify, int $now): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_campaign_goals (campaign_id, goal_id, user_id, payout, notify_traffic_source, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE payout = VALUES(payout), notify_traffic_source = VALUES(notify_traffic_source), updated_at = VALUES(updated_at)'
        );
        $this->conn->bind($stmt, 'iiisiii', [
            $campaignId, $goalId, $userId,
            $payoutUnits === null ? null : Amount::fromUnits($payoutUnits),
            $notify ? 1 : 0, $now, $now,
        ]);
        $this->conn->executeUpdate($stmt);
    }

    public function detach(int $userId, int $campaignId, int $goalId): int
    {
        $stmt = $this->conn->prepareWrite('DELETE FROM 202_campaign_goals WHERE campaign_id = ? AND goal_id = ? AND user_id = ?');
        $this->conn->bind($stmt, 'iii', [$campaignId, $goalId, $userId]);

        return $this->conn->executeUpdate($stmt);
    }

    /** @return array<string, mixed>|null the campaign, when it is the user's */
    public function campaign(int $userId, int $campaignId): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT aff_campaign_id, aff_campaign_name, payout_mode FROM 202_aff_campaigns
             WHERE aff_campaign_id = ? AND user_id = ? AND aff_campaign_deleted = 0 LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$campaignId, $userId]);

        return $this->conn->fetchOne($stmt);
    }

    /** @return array<string, mixed>|null the registration, when it is the user's */
    public function registration(int $userId, int $registrationId): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT registration_id, platform, app_name FROM 202_app_registrations WHERE registration_id = ? AND user_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'ii', [$registrationId, $userId]);

        return $this->conn->fetchOne($stmt);
    }

    // ─── The goal set a subject evaluates ───────────────────────────

    /**
     * The goals a click on this campaign evaluates: the campaign's own goals,
     * the goals it attaches from another scope (from the moment they were
     * attached), and — transitively — the prerequisites those name, which
     * start when the goal that needs them does. Archived goals stay in the
     * set with their archive time as the end of their span, so a replay
     * sees exactly what the incremental evaluation saw.
     *
     * @return list<GoalSpec>
     */
    public function specsForCampaign(int $userId, int $campaignId): array
    {
        $stmt = $this->conn->prepareWrite(
            "SELECT g.goal_id, g.scope, g.scope_id, g.archived_at, cg.created_at AS attached_at
             FROM 202_goals g
             LEFT JOIN 202_campaign_goals cg ON cg.goal_id = g.goal_id AND cg.campaign_id = ?
             WHERE g.user_id = ? AND ((g.scope = 'campaign' AND g.scope_id = ?) OR cg.campaign_id IS NOT NULL)"
        );
        $this->conn->bind($stmt, 'iii', [$campaignId, $userId, $campaignId]);

        $starts = [];
        $ends = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $goalId = (int) $row['goal_id'];
            $own = (string) $row['scope'] === GoalScope::CAMPAIGN->value && (int) $row['scope_id'] === $campaignId;
            $starts[$goalId] = $own ? 0 : (int) $row['attached_at'];
            $ends[$goalId] = $row['archived_at'] !== null ? (int) $row['archived_at'] : null;
        }

        return $this->specsWithPrerequisites($userId, $starts, $ends);
    }

    /**
     * Build specs for a set of goals, pulling in every prerequisite the
     * versions name (same user, same scope by construction), each starting
     * when the earliest goal that needs it starts.
     *
     * @param array<int, int> $starts goal id => starts_at
     * @param array<int, int|null> $ends goal id => ends_at
     * @return list<GoalSpec>
     */
    public function specsWithPrerequisites(int $userId, array $starts, array $ends): array
    {
        // Load the goals and, breadth first, every goal their versions name.
        $versions = [];
        $pending = array_keys($starts);
        while ($pending !== []) {
            $load = array_values(array_unique(array_diff($pending, array_keys($versions))));
            $pending = [];
            if ($load === []) {
                break;
            }
            $loaded = $this->versionsOf($userId, $load);
            foreach ($load as $goalId) {
                // A named prerequisite that does not exist (or is another
                // user's) has no versions and stays out of the set; a goal
                // naming it is disabled as prerequisite_missing.
                $versions[$goalId] = $loaded[$goalId] ?? [];
                foreach ($versions[$goalId] as $row) {
                    foreach (self::afterOf($row['json']) as $prereq) {
                        $pending[] = $prereq;
                    }
                }
            }
        }

        // A prerequisite starts when the earliest goal needing it starts.
        for ($pass = 0, $changed = true; $changed && $pass <= count($versions); $pass++) {
            $changed = false;
            foreach ($versions as $goalId => $rows) {
                if (!isset($starts[$goalId])) {
                    continue;
                }
                foreach ($rows as $row) {
                    foreach (self::afterOf($row['json']) as $prereq) {
                        if (!isset($starts[$prereq]) || $starts[$prereq] > $starts[$goalId]) {
                            $starts[$prereq] = $starts[$goalId];
                            $changed = true;
                        }
                    }
                }
            }
        }

        $specs = [];
        ksort($versions);
        foreach ($versions as $goalId => $rows) {
            if ($rows === []) {
                continue; // absent from the set; a goal naming it is disabled (prerequisite_missing)
            }
            $specs[] = new GoalSpec(
                $goalId,
                array_map(static fn (array $r): array => [
                    'version' => $r['version'],
                    'effective_at' => $r['effective_at'],
                    'definition' => $r['decoded'],
                ], $rows),
                $starts[$goalId] ?? 0,
                $ends[$goalId] ?? $rows[0]['archived_at'],
            );
        }

        return $specs;
    }

    /**
     * @param list<int> $goalIds
     * @return array<int, list<array{version: int, effective_at: int, json: string, decoded: mixed, archived_at: int|null}>>
     */
    private function versionsOf(int $userId, array $goalIds): array
    {
        if ($goalIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($goalIds), '?'));
        $stmt = $this->conn->prepareWrite(
            'SELECT v.goal_id, v.version, v.definition, v.effective_at, g.archived_at
             FROM 202_goal_versions v JOIN 202_goals g ON g.goal_id = v.goal_id
             WHERE g.user_id = ? AND v.goal_id IN (' . $marks . ') ORDER BY v.goal_id, v.version'
        );
        $this->conn->bind($stmt, 'i' . str_repeat('i', count($goalIds)), [$userId, ...$goalIds]);
        $out = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $json = (string) $row['definition'];
            // Undecodable JSON is handed to the evaluator as-is (a string),
            // which disables that version as invalid_definition instead of
            // failing every goal of the subject.
            $decoded = json_decode($json, true);
            $out[(int) $row['goal_id']][] = [
                'version' => (int) $row['version'],
                'effective_at' => (int) $row['effective_at'],
                'json' => $json,
                'decoded' => $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $json : $decoded,
                'archived_at' => $row['archived_at'] !== null ? (int) $row['archived_at'] : null,
            ];
        }

        return $out;
    }

    // ─── Outcomes ───────────────────────────────────────────────────

    /**
     * THE read of 202_goal_outcomes: live rows only (superseded_at IS NULL).
     * The funnel, the report, the per-subject breakdown, the API and the
     * engine's own reconciliation all read through here.
     *
     * @param array{goal_id?: int, subject_type?: string, subject_id?: int, goal_ids?: list<int>} $filters
     * @return list<array<string, mixed>>
     */
    public function liveOutcomes(int $userId, array $filters, int $limit = 1000, int $offset = 0, bool $forUpdate = false): array
    {
        [$clause, $types, $binds] = self::outcomeFilter($userId, $filters);
        $stmt = $this->conn->prepareWrite(
            'SELECT * FROM 202_goal_outcomes WHERE ' . $clause . ' ORDER BY outcome_id LIMIT ? OFFSET ?' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $this->conn->bind($stmt, $types . 'ii', [...$binds, $limit, $offset]);

        return $this->conn->fetchAll($stmt);
    }

    /** @param array{goal_id?: int, subject_type?: string, subject_id?: int, goal_ids?: list<int>} $filters */
    public function countLiveOutcomes(int $userId, array $filters): int
    {
        [$clause, $types, $binds] = self::outcomeFilter($userId, $filters);
        $stmt = $this->conn->prepareWrite('SELECT COUNT(*) AS n FROM 202_goal_outcomes WHERE ' . $clause);
        $this->conn->bind($stmt, $types, $binds);

        return (int) (($this->conn->fetchOne($stmt) ?? [])['n'] ?? 0);
    }

    /**
     * @param array{goal_id?: int, subject_type?: string, subject_id?: int, goal_ids?: list<int>} $filters
     * @return array{0: string, 1: string, 2: list<mixed>}
     */
    private static function outcomeFilter(int $userId, array $filters): array
    {
        $where = ['user_id = ?', 'superseded_at IS NULL'];
        $types = 'i';
        $binds = [$userId];
        if (isset($filters['goal_id'])) {
            $where[] = 'goal_id = ?';
            $types .= 'i';
            $binds[] = $filters['goal_id'];
        }
        if (isset($filters['goal_ids']) && $filters['goal_ids'] !== []) {
            $where[] = 'goal_id IN (' . implode(',', array_fill(0, count($filters['goal_ids']), '?')) . ')';
            $types .= str_repeat('i', count($filters['goal_ids']));
            array_push($binds, ...$filters['goal_ids']);
        }
        if (isset($filters['subject_type'])) {
            $where[] = 'subject_type = ?';
            $types .= 's';
            $binds[] = $filters['subject_type'];
        }
        if (isset($filters['subject_id'])) {
            $where[] = 'subject_id = ?';
            $types .= 'i';
            $binds[] = $filters['subject_id'];
        }

        return [implode(' AND ', $where), $types, $binds];
    }
}
