<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Api\V3\Apps\Android\Integrity\IntegrityState;
use Api\V3\Controllers\AppPostbacksController;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\MysqliStatements;
use Api\V3\Support\ResponseSanitizer;

/**
 * The Android half of GET /apps/report (plan §5.6): what the installs the
 * SDK reported, and the goals they reached, add up to.
 *
 * Android numbers are read by install — a cohort: an install's goal
 * outcomes count in the group its install falls in (the day it was
 * received, its app, its campaign), however much later they were reached.
 * Nothing here is delayed, aggregated or privacy-thresholded the way
 * Apple's postbacks are.
 *
 * Trust, as everywhere an aggregate reads rows a public endpoint writes
 * (CLAUDE.md #16): the app token that lets an SDK report an install ships
 * inside the app, so anyone can post an "organic" install. The headline
 * figures therefore count trusted installs only — `installs` is the
 * trusted (attributed) ones, and `events`, `goals_reached` and `revenue`
 * are the goals those installs reached — and every other class stays
 * visible beside them: `organic`, `refuted_count`, `unvouched_count`,
 * `test_count`, `pending`, and the full `match_states` and
 * `integrity_states` breakdowns. An explicit `trusted=` filter recomputes
 * the goal figures over exactly that class, as the iOS report's
 * `signature=` does (`meta.trusted: as-filtered`).
 *
 * `revenue` is what the campaigns were credited: the value of payable
 * outcomes, the ledger's view. A goal reached but not paid on the
 * install's campaign counts in `events` and `goals_reached` with no
 * revenue.
 *
 * group_by `goal` is the funnel's reading: one row per goal with the
 * installs that reached it (distinct, per trust class), the outcomes and
 * the revenue, plus the goal's name, owner and `after` so a caller can
 * order the steps.
 */
final class InstallReport
{
    use MysqliStatements;

    /**
     * The grouping modes: the expression grouped on, the response field it
     * is returned under, and how it renders. `goal` is not here: it groups
     * outcomes, not installs, and has its own query (goalGroups()).
     */
    private const GROUP_MODES = [
        'day'             => ['expr' => 'FLOOR(i.received_at / 86400) * 86400', 'key' => 'date', 'kind' => 'day'],
        'registration'    => ['expr' => 'i.registration_id', 'key' => 'registration_id', 'kind' => 'int'],
        'platform'        => ['expr' => "'android'", 'key' => 'platform', 'kind' => 'string'],
        'campaign'        => ['expr' => 'c.aff_campaign_id', 'key' => 'aff_campaign_id', 'kind' => 'nullable-int'],
        'match-state'     => ['expr' => 'i.match_state', 'key' => 'match_state', 'kind' => 'string'],
        'integrity-state' => ['expr' => 'i.integrity_state', 'key' => 'integrity_state', 'kind' => 'string'],
    ];

    /** Every grouping this report answers, `goal` included. */
    public const GROUPINGS = ['day', 'registration', 'platform', 'campaign', 'match-state', 'integrity-state', 'goal'];

    /** The Android-only filters, beside the shared time/registration ones. */
    public const FILTERS = ['match_state', 'integrity_state', 'trusted', 'test', 'aff_campaign_id'];

    /**
     * The install-level metrics, alias => aggregate. One list, read by the
     * grouped query, the totals and the row builder, so the three cannot
     * disagree about what a column means.
     */
    private const METRICS = [
        'received' => 'COUNT(*)',
        'installs' => 'SUM(CASE WHEN i.trusted = 1 THEN 1 ELSE 0 END)',
        'organic' => "SUM(CASE WHEN i.match_state = 'organic' THEN 1 ELSE 0 END)",
        'pending' => "SUM(CASE WHEN i.match_state IN ('pending_click', 'pending_integrity') THEN 1 ELSE 0 END)",
        'trusted_count' => 'SUM(CASE WHEN i.trusted = 1 THEN 1 ELSE 0 END)',
        'refuted_count' => 'SUM(CASE WHEN i.trusted = 0 THEN 1 ELSE 0 END)',
        'unvouched_count' => 'SUM(CASE WHEN i.trusted IS NULL THEN 1 ELSE 0 END)',
        'test_count' => 'SUM(CASE WHEN i.is_test = 1 THEN 1 ELSE 0 END)',
    ];

    /** Joined for the campaign dimension and filter: the click's campaign, the owner's own clicks only. */
    private const CLICK_JOIN = 'LEFT JOIN 202_clicks c ON c.click_id = i.click_id AND c.user_id = i.user_id';

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    /**
     * @param array<string, mixed> $params group_by, limit, time_from, time_to, registration_id(s), and FILTERS
     * @return array{groups: list<array<string, mixed>>, totals: array<string, mixed>, truncated: bool, trusted: string}
     */
    public function report(array $params): array
    {
        $groupBy = (string)($params['group_by'] ?? 'day');
        if (!in_array($groupBy, self::GROUPINGS, true)) {
            throw new ValidationException('Invalid group_by', [
                'group_by' => 'Must be one of: ' . implode(', ', self::GROUPINGS),
            ]);
        }
        $limit = AppPostbacksController::boundedInt($params, 'limit', 100, 1, 500);
        [$where, $binds, $types, $gate, $explicitTrust] = $this->buildFilters($params);

        if ($groupBy === 'goal') {
            [$groups, $truncated] = $this->goalGroups($where, $binds, $types, $gate, $limit);
        } else {
            [$groups, $truncated] = $this->installGroups($groupBy, $where, $binds, $types, $gate, $limit);
        }

        return [
            'groups' => $groups,
            'totals' => $this->totals($where, $binds, $types, $gate),
            'truncated' => $truncated,
            'trusted' => $explicitTrust ? 'as-filtered' : 'trusted-only',
        ];
    }

    /**
     * Groups over installs, newest days first for `day` (then re-sorted
     * oldest first, as the iOS report does), busiest first otherwise.
     *
     * @param list<string> $where
     * @param list<mixed> $binds
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function installGroups(string $groupBy, array $where, array $binds, string $types, string $gate, int $limit): array
    {
        $mode = self::GROUP_MODES[$groupBy];
        $expr = $mode['expr'];
        $order = $mode['kind'] === 'day' ? 'grp DESC' : 'received DESC, grp';
        $sql = 'SELECT ' . $expr . ' AS grp, ' . self::metricList()
            . ' FROM 202_app_installs i ' . self::CLICK_JOIN
            . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY grp ORDER BY ' . $order . ' LIMIT ?';
        $rows = $this->fetchAll($sql, $types . 'i', [...$binds, $limit + 1]);
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $groups = [];
        foreach ($rows as $row) {
            $key = self::keyOf($row['grp']);
            $groups[$key] = [$mode['key'] => self::render($mode['kind'], $row['grp'])]
                + ['platform' => 'android']
                + self::metricsOf($row)
                + ['match_states' => [], 'integrity_states' => [], 'goals_reached' => 0, 'revenue' => 0.0, 'events' => []];
        }
        if ($groups === []) {
            return [[], $truncated];
        }

        // The breakdowns and the goals, bounded to the groups kept: the day
        // mode by the kept days' span, the others by the kept keys.
        [$kw, $kb, $kt] = self::restrictTo($mode, array_keys($groups));
        $scopedWhere = [...$where, ...$kw];
        $scopedBinds = [...$binds, ...$kb];
        $scopedTypes = $types . $kt;

        foreach (['match_states' => 'i.match_state', 'integrity_states' => 'i.integrity_state'] as $field => $column) {
            $sql = 'SELECT ' . $expr . ' AS grp, ' . $column . ' AS state, COUNT(*) AS n'
                . ' FROM 202_app_installs i ' . self::CLICK_JOIN
                . ' WHERE ' . implode(' AND ', $scopedWhere)
                . ' GROUP BY grp, state';
            foreach ($this->fetchAll($sql, $scopedTypes, $scopedBinds) as $row) {
                $key = self::keyOf($row['grp']);
                if (isset($groups[$key])) {
                    $groups[$key][$field][(string)$row['state']] = (int)$row['n'];
                }
            }
        }

        $names = [];
        $sql = 'SELECT ' . $expr . ' AS grp, o.goal_id, COUNT(*) AS outcomes,'
            . ' COALESCE(SUM(CASE WHEN o.payable = 1 THEN o.value END), 0) AS revenue'
            . ' FROM 202_goal_outcomes o'
            . ' JOIN 202_app_installs i ON i.install_row_id = o.subject_id AND i.user_id = o.user_id ' . self::CLICK_JOIN
            . " WHERE o.user_id = ? AND o.subject_type = 'install' AND o.superseded_at IS NULL AND " . $gate
            . ' AND ' . implode(' AND ', $scopedWhere)
            . ' GROUP BY grp, o.goal_id';
        $outcomeRows = $this->fetchAll($sql, 'i' . $scopedTypes, [$this->userId, ...$scopedBinds]);
        $names = $this->goalNames(array_map(static fn (array $r): int => (int)$r['goal_id'], $outcomeRows));
        foreach ($outcomeRows as $row) {
            $key = self::keyOf($row['grp']);
            if (!isset($groups[$key])) {
                continue;
            }
            self::addOutcome($groups[$key], $names[(int)$row['goal_id']]['name'] ?? ('goal ' . (int)$row['goal_id']), (int)$row['outcomes'], (string)$row['revenue']);
        }

        $out = [];
        foreach ($groups as $group) {
            $out[] = self::finish($group);
        }
        if ($mode['kind'] === 'day') {
            usort($out, static fn (array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));
        }
        if ($groupBy === 'registration') {
            $this->attachRegistrations($out);
        } elseif ($groupBy === 'campaign') {
            $this->attachCampaigns($out);
        }

        return [$out, $truncated];
    }

    /**
     * One row per goal the matching installs reached: the funnel's reading.
     *
     * @param list<string> $where
     * @param list<mixed> $binds
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function goalGroups(array $where, array $binds, string $types, string $gate, int $limit): array
    {
        // The trust classes are counted per install (DISTINCT), so an
        // install that reached a repeating goal three times is one install
        // at that step; `goals_reached` counts the outcomes.
        $sql = 'SELECT o.goal_id,'
            . ' COUNT(DISTINCT CASE WHEN ' . $gate . ' THEN o.subject_id END) AS installs,'
            . ' COUNT(DISTINCT CASE WHEN i.trusted = 1 THEN o.subject_id END) AS trusted_count,'
            . ' COUNT(DISTINCT CASE WHEN i.trusted = 0 THEN o.subject_id END) AS refuted_count,'
            . ' COUNT(DISTINCT CASE WHEN i.trusted IS NULL THEN o.subject_id END) AS unvouched_count,'
            . ' COUNT(DISTINCT CASE WHEN i.is_test = 1 THEN o.subject_id END) AS test_count,'
            . ' SUM(CASE WHEN ' . $gate . ' THEN 1 ELSE 0 END) AS outcomes,'
            . ' COALESCE(SUM(CASE WHEN ' . $gate . ' AND o.payable = 1 THEN o.value END), 0) AS revenue'
            . ' FROM 202_goal_outcomes o'
            . ' JOIN 202_app_installs i ON i.install_row_id = o.subject_id AND i.user_id = o.user_id ' . self::CLICK_JOIN
            . " WHERE o.user_id = ? AND o.subject_type = 'install' AND o.superseded_at IS NULL"
            . ' AND ' . implode(' AND ', $where)
            . ' GROUP BY o.goal_id ORDER BY installs DESC, o.goal_id LIMIT ?';
        $rows = $this->fetchAll($sql, 'i' . $types . 'i', [$this->userId, ...$binds, $limit + 1]);
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $names = $this->goalNames(array_map(static fn (array $r): int => (int)$r['goal_id'], $rows));

        $out = [];
        foreach ($rows as $row) {
            $goalId = (int)$row['goal_id'];
            $meta = $names[$goalId] ?? null;
            $name = $meta['name'] ?? ('goal ' . $goalId);
            $group = [
                'goal_id' => $goalId,
                'goal_name' => $name,
                'goal_scope' => $meta['scope'] ?? null,
                'goal_scope_id' => $meta['scope_id'] ?? null,
                'builtin' => $meta['builtin'] ?? null,
                'after' => $meta['after'] ?? [],
                'archived' => $meta['archived'] ?? false,
                'platform' => 'android',
                'installs' => (int)$row['installs'],
                'trusted_count' => (int)$row['trusted_count'],
                'refuted_count' => (int)$row['refuted_count'],
                'unvouched_count' => (int)$row['unvouched_count'],
                'test_count' => (int)$row['test_count'],
                'goals_reached' => 0,
                'revenue' => 0.0,
                'events' => [],
            ];
            self::addOutcome($group, $name, (int)$row['outcomes'], (string)$row['revenue']);
            $out[] = self::finish($group);
        }

        return [$out, $truncated];
    }

    /**
     * The same figures over every matching install, ungrouped — never a sum
     * of the groups, which a truncation would shorten.
     *
     * @param list<string> $where
     * @param list<mixed> $binds
     * @return array<string, mixed>
     */
    private function totals(array $where, array $binds, string $types, string $gate): array
    {
        $whereSql = implode(' AND ', $where);
        $rows = $this->fetchAll(
            'SELECT ' . self::metricList() . ' FROM 202_app_installs i ' . self::CLICK_JOIN . ' WHERE ' . $whereSql,
            $types,
            $binds
        );
        $totals = ['platform' => 'android'] + self::metricsOf($rows[0] ?? [])
            + ['match_states' => [], 'integrity_states' => [], 'goals_reached' => 0, 'revenue' => 0.0, 'events' => []];

        foreach (['match_states' => 'i.match_state', 'integrity_states' => 'i.integrity_state'] as $field => $column) {
            $sql = 'SELECT ' . $column . ' AS state, COUNT(*) AS n FROM 202_app_installs i ' . self::CLICK_JOIN
                . ' WHERE ' . $whereSql . ' GROUP BY state';
            foreach ($this->fetchAll($sql, $types, $binds) as $row) {
                $totals[$field][(string)$row['state']] = (int)$row['n'];
            }
        }

        $outcomeRows = $this->fetchAll(
            'SELECT o.goal_id, COUNT(*) AS outcomes, COALESCE(SUM(CASE WHEN o.payable = 1 THEN o.value END), 0) AS revenue'
            . ' FROM 202_goal_outcomes o'
            . ' JOIN 202_app_installs i ON i.install_row_id = o.subject_id AND i.user_id = o.user_id ' . self::CLICK_JOIN
            . " WHERE o.user_id = ? AND o.subject_type = 'install' AND o.superseded_at IS NULL AND " . $gate
            . ' AND ' . $whereSql . ' GROUP BY o.goal_id',
            'i' . $types,
            [$this->userId, ...$binds]
        );
        $names = $this->goalNames(array_map(static fn (array $r): int => (int)$r['goal_id'], $outcomeRows));
        foreach ($outcomeRows as $row) {
            self::addOutcome($totals, $names[(int)$row['goal_id']]['name'] ?? ('goal ' . (int)$row['goal_id']), (int)$row['outcomes'], (string)$row['revenue']);
        }

        return self::finish($totals);
    }

    /**
     * The filters, strictly: a value the report cannot use is a 422 naming
     * it, never a filter quietly dropped (error pattern #4). Returns the
     * WHERE parts over `i` (and `c`), their binds, and the trust gate the
     * goal figures are counted under.
     *
     * @param array<string, mixed> $params
     * @return array{0: list<string>, 1: list<mixed>, 2: string, 3: string, 4: bool}
     */
    private function buildFilters(array $params): array
    {
        $where = ['i.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        foreach (['time_from' => 'i.received_at >= ?', 'time_to' => 'i.received_at <= ?'] as $param => $condition) {
            if (isset($params[$param]) && $params[$param] !== '') {
                $where[] = $condition;
                $binds[] = AppPostbacksController::strictInt($params[$param], $param, 'Invalid time filter', 'Must be a unix timestamp');
                $types .= 'i';
            }
        }
        foreach (['registration_id' => 'i.registration_id = ?', 'aff_campaign_id' => 'c.aff_campaign_id = ?'] as $param => $condition) {
            if (isset($params[$param]) && $params[$param] !== '') {
                $where[] = $condition;
                $binds[] = AppPostbacksController::strictInt($params[$param], $param, 'Invalid filter value', 'Must be an integer');
                $types .= 'i';
            }
        }
        if (isset($params['registration_ids']) && $params['registration_ids'] !== '' && $params['registration_ids'] !== []) {
            $raw = $params['registration_ids'];
            if (!is_array($raw)) {
                if (!is_scalar($raw)) {
                    throw new ValidationException('Invalid filter value', ['registration_ids' => 'Must be a list of registration ids, or a comma-separated string of them']);
                }
                $raw = explode(',', (string)$raw);
            }
            $ids = [];
            foreach ($raw as $element) {
                if (!is_scalar($element)) {
                    throw new ValidationException('Invalid filter value', ['registration_ids' => 'Each registration id must be an integer']);
                }
                $ids[] = AppPostbacksController::strictInt(is_string($element) ? trim($element) : $element, 'registration_ids', 'Invalid filter value', 'Each registration id must be an integer');
            }
            if (count($ids) > 500) {
                throw new ValidationException('Invalid filter value', ['registration_ids' => 'At most 500 registration ids']);
            }
            $ids = array_values(array_unique($ids));
            $where[] = 'i.registration_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            foreach ($ids as $id) {
                $binds[] = $id;
                $types .= 'i';
            }
        }
        foreach (['match_state' => [MatchState::values(), 'i.match_state = ?'], 'integrity_state' => [IntegrityState::values(), 'i.integrity_state = ?']] as $param => [$allowed, $condition]) {
            if (isset($params[$param]) && $params[$param] !== '') {
                $value = strtolower(trim((string)$params[$param]));
                if (!in_array($value, $allowed, true)) {
                    throw new ValidationException('Invalid filter value', [$param => 'Must be one of: ' . implode(', ', $allowed)]);
                }
                $where[] = $condition;
                $binds[] = $value;
                $types .= 's';
            }
        }
        if (isset($params['test']) && $params['test'] !== '') {
            $test = (string)$params['test'];
            if ($test !== '0' && $test !== '1') {
                throw new ValidationException('Invalid filter value', ['test' => 'Must be 1 (test installs only) or 0 (real ones only)']);
            }
            $where[] = 'i.is_test = ?';
            $binds[] = (int)$test;
            $types .= 'i';
        }

        // The trust class the goal figures count. Default: trusted installs
        // only. An explicit filter narrows the installs to one class AND
        // counts that class's goals, so what the figures describe is what
        // the filter selected — said in meta.trusted.
        $gate = 'i.trusted = 1';
        $explicit = false;
        if (isset($params['trusted']) && $params['trusted'] !== '') {
            $class = match (strtolower(trim((string)$params['trusted']))) {
                '1', 'trusted' => 'i.trusted = 1',
                '0', 'refuted' => 'i.trusted = 0',
                'unvouched' => 'i.trusted IS NULL',
                default => throw new ValidationException('Invalid filter value', ['trusted' => 'Must be trusted (1), refuted (0) or unvouched']),
            };
            $where[] = $class;
            $gate = $class;
            $explicit = true;
        }

        return [$where, $binds, $types, $gate, $explicit];
    }

    /**
     * WHERE parts that keep a follow-up query to the groups the report kept.
     *
     * @param array{expr: string, key: string, kind: string} $mode
     * @param list<string> $keys
     * @return array{0: list<string>, 1: list<mixed>, 2: string}
     */
    private static function restrictTo(array $mode, array $keys): array
    {
        if ($mode['kind'] === 'day') {
            $days = array_map('intval', $keys);
            return [['i.received_at >= ?', 'i.received_at < ?'], [min($days), max($days) + 86400], 'ii'];
        }
        if ($mode['kind'] === 'string' && $mode['expr'][0] === "'") {
            return [[], [], '']; // the constant platform group: one group, nothing to narrow
        }
        $values = [];
        $hasNull = false;
        foreach ($keys as $key) {
            if ($key === '') {
                $hasNull = true;
            } else {
                $values[] = $key;
            }
        }
        $type = in_array($mode['kind'], ['int', 'nullable-int'], true) ? 'i' : 's';
        $parts = [];
        $binds = [];
        if ($values !== []) {
            $parts[] = $mode['expr'] . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
            foreach ($values as $value) {
                $binds[] = $type === 'i' ? (int)$value : (string)$value;
            }
        }
        if ($hasNull) {
            $parts[] = $mode['expr'] . ' IS NULL';
        }

        return [['(' . implode(' OR ', $parts) . ')'], $binds, str_repeat($type, count($binds))];
    }

    private static function metricList(): string
    {
        $parts = [];
        foreach (self::METRICS as $alias => $expression) {
            $parts[] = $expression . ' AS ' . $alias;
        }
        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, int>
     */
    private static function metricsOf(array $row): array
    {
        $out = [];
        foreach (array_keys(self::METRICS) as $key) {
            $out[$key] = (int)($row[$key] ?? 0);
        }
        return $out;
    }

    /** A group key as a string; a NULL group (no campaign) is ''. */
    private static function keyOf(mixed $value): string
    {
        return $value === null ? '' : (string)$value;
    }

    private static function render(string $kind, mixed $value): mixed
    {
        return match ($kind) {
            'day' => gmdate('Y-m-d', (int)$value),
            'int' => (int)$value,
            'nullable-int' => $value === null ? null : (int)$value,
            'string' => (string)$value,
        };
    }

    /**
     * Fold one goal's outcomes into a group. Revenue is kept in integer
     * units while folding (Amount's 5 decimal places), so a sum over many
     * goals does not drift in floating point.
     *
     * @param array<string, mixed> $group
     */
    private static function addOutcome(array &$group, string $name, int $outcomes, string $revenue): void
    {
        $units = \Prosper202\Conversion\Ledger\Amount::toUnits($revenue);
        $group['goals_reached'] += $outcomes;
        $group['_revenue_units'] = ($group['_revenue_units'] ?? 0) + $units;
        $group['events'][$name] ??= ['count' => 0, '_units' => 0];
        $group['events'][$name]['count'] += $outcomes;
        $group['events'][$name]['_units'] += $units;
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private static function finish(array $group): array
    {
        $group['revenue'] = (float)\Prosper202\Conversion\Ledger\Amount::fromUnits((int)($group['_revenue_units'] ?? 0));
        unset($group['_revenue_units']);
        $events = [];
        foreach ($group['events'] as $name => $event) {
            $events[(string)$name] = ['count' => (int)$event['count'], 'revenue' => (float)\Prosper202\Conversion\Ledger\Amount::fromUnits((int)$event['_units'])];
        }
        // Objects, not lists: a numeric goal name or state would otherwise
        // encode as a JSON array and lose its key (the iOS report does the
        // same for its events).
        $group['events'] = (object)$events;
        foreach (['match_states', 'integrity_states'] as $field) {
            if (array_key_exists($field, $group)) {
                $group[$field] = (object)$group[$field];
            }
        }

        return $group;
    }

    /**
     * Each goal's name, owner, builtin marker, archived flag and `after`, by
     * current version. A goal an outcome names but that cannot be read is
     * left out, and its outcomes are labelled by id.
     *
     * @param list<int> $goalIds
     * @return array<int, array{name: string, scope: string, scope_id: int, builtin: string|null, archived: bool, after: list<int>}>
     */
    private function goalNames(array $goalIds): array
    {
        $goalIds = array_values(array_unique($goalIds));
        if ($goalIds === []) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($goalIds), '?'));
        $rows = $this->fetchAll(
            "SELECT g.goal_id, g.name, g.scope, g.scope_id, g.builtin, g.archived_at, v.definition
             FROM 202_goals g
             LEFT JOIN 202_goal_versions v ON v.goal_id = g.goal_id AND v.version = g.current_version
             WHERE g.user_id = ? AND g.goal_id IN ($marks)",
            'i' . str_repeat('i', count($goalIds)),
            [$this->userId, ...$goalIds]
        );
        $out = [];
        foreach ($rows as $row) {
            $after = [];
            if (is_string($row['definition'])) {
                $decoded = json_decode($row['definition'], true);
                if (is_array($decoded) && is_array($decoded['after'] ?? null)) {
                    foreach ($decoded['after'] as $id) {
                        if (is_int($id)) {
                            $after[] = $id;
                        }
                    }
                }
            }
            $out[(int)$row['goal_id']] = [
                'name' => ResponseSanitizer::cleanVisitorString((string)$row['name']),
                'scope' => (string)$row['scope'],
                'scope_id' => (int)$row['scope_id'],
                'builtin' => $row['builtin'] === null ? null : (string)$row['builtin'],
                'archived' => $row['archived_at'] !== null,
                'after' => $after,
            ];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $groups */
    private function attachRegistrations(array &$groups): void
    {
        $rows = $this->fetchAll('SELECT registration_id, app_name, platform, app_key FROM 202_app_registrations WHERE user_id = ?', 'i', [$this->userId]);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['registration_id']] = $row;
        }
        foreach ($groups as &$group) {
            $registration = $byId[(int)$group['registration_id']] ?? null;
            $group['app_name'] = $registration === null ? null : ResponseSanitizer::cleanVisitorString((string)$registration['app_name']);
            $group['app_key'] = $registration === null ? null : (string)$registration['app_key'];
        }
        unset($group);
    }

    /** @param list<array<string, mixed>> $groups */
    private function attachCampaigns(array &$groups): void
    {
        $ids = array_values(array_filter(array_map(static fn (array $g): int => (int)($g['aff_campaign_id'] ?? 0), $groups)));
        $names = [];
        if ($ids !== []) {
            $marks = implode(', ', array_fill(0, count($ids), '?'));
            foreach ($this->fetchAll(
                "SELECT aff_campaign_id, aff_campaign_name FROM 202_aff_campaigns WHERE user_id = ? AND aff_campaign_id IN ($marks)",
                'i' . str_repeat('i', count($ids)),
                [$this->userId, ...$ids]
            ) as $row) {
                $names[(int)$row['aff_campaign_id']] = ResponseSanitizer::cleanVisitorString((string)$row['aff_campaign_name']);
            }
        }
        foreach ($groups as &$group) {
            $id = $group['aff_campaign_id'] ?? null;
            $group['aff_campaign_name'] = $id === null ? null : ($names[(int)$id] ?? null);
        }
        unset($group);
    }

    /**
     * @param list<mixed> $binds
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, string $types, array $binds): array
    {
        $stmt = $this->prepare($sql);
        if ($types !== '') {
            $this->bind($stmt, $types, ...$binds);
        }
        $this->execute($stmt, 'Install report query failed');
        $result = $this->result($stmt);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
