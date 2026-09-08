<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Skan\PostbackVerifier;
use Api\V3\Support\MysqliStatements;
use Api\V3\Support\ResponseSanitizer;

/**
 * Read-only access to received SKAdNetwork postbacks, plus the aggregate
 * SKAN report and an ad-hoc signature verification utility.
 *
 * Postbacks are written only by the public receiver
 * (/.well-known/skadnetwork/report-attribution/); the API never mutates
 * them. Rows belong to the user whose 202_skan_apps registration matches the
 * advertised app (user_id = 0 rows are unclaimed and become visible once the
 * app is registered — SkanAppsController claims them).
 *
 * Postback fields are authored by whoever POSTs to the open receiver, so
 * every string column is passed through ResponseSanitizer on the way out,
 * and the raw request body is deliberately never served through the API —
 * the parsed columns carry all of its meaning.
 */
class SkanPostbacksController
{
    use MysqliStatements;

    /** String columns whose content arrives from the open receiver. */
    private const UNTRUSTED_FIELDS = [
        'version',
        'ad_network_id',
        'transaction_id',
        'source_identifier',
        'coarse_conversion_value',
        'source_domain',
        'country_code',
        'remote_ip',
    ];

    private const SELECT_COLUMNS = 'postback_id, user_id, received_at, version, ad_network_id, '
        . 'transaction_id, app_id, source_identifier, campaign_id, conversion_value, '
        . 'coarse_conversion_value, postback_sequence_index, redownload, did_win, '
        . 'source_app_id, source_domain, fidelity_type, country_code, signature_valid, remote_ip';

    /** Report grouping modes: output alias => SQL expression. */
    private const GROUP_MODES = [
        'day'        => ['grp_day' => 'FLOOR(received_at / 86400) * 86400'],
        'app'        => ['grp_app_id' => 'app_id'],
        'ad-network' => ['grp_ad_network_id' => 'ad_network_id'],
        'source'     => ['grp_source_identifier' => 'source_identifier', 'grp_campaign_id' => 'campaign_id'],
        'country'    => ['grp_country_code' => 'country_code'],
        'version'    => ['grp_version' => 'version'],
    ];

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    public function list(array $params): array
    {
        $limit = self::boundedInt($params, 'limit', 50, 1, 500);
        $offset = self::boundedInt($params, 'offset', 0, 0, PHP_INT_MAX);

        [$where, $binds, $types] = $this->buildFilters($params);
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) as total FROM 202_skan_postbacks $whereClause";
        $stmt = $this->prepare($countSql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Count query failed');
        $countResult = $this->result($stmt);
        $total = (int)(($countResult->fetch_assoc()['total']) ?? 0);
        $stmt->close();

        $sql = 'SELECT ' . self::SELECT_COLUMNS . " FROM 202_skan_postbacks $whereClause"
            . ' ORDER BY postback_id DESC LIMIT ? OFFSET ?';
        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->prepare($sql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'List query failed');
        $result = $this->result($stmt);

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            // Defense in depth: list rows never carry the signature or the
            // raw body (get() serves the signature for forensics), even if
            // the select list changes later.
            unset($row['attribution_signature'], $row['raw_payload']);
            $rows[] = ResponseSanitizer::cleanRowFields($row, self::UNTRUSTED_FIELDS);
        }
        $stmt->close();

        return [
            'data' => $rows,
            'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
        ];
    }

    public function get(int $id): array
    {
        $sql = 'SELECT ' . self::SELECT_COLUMNS . ', attribution_signature'
            . ' FROM 202_skan_postbacks WHERE postback_id = ? AND user_id = ? LIMIT 1';
        $stmt = $this->prepare($sql);
        $this->bind($stmt, 'ii', $id, $this->userId);
        $this->execute($stmt, 'Query failed');
        $row = $this->result($stmt)->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('Postback not found');
        }

        $row = ResponseSanitizer::cleanRowFields($row, self::UNTRUSTED_FIELDS);
        // The signature is served for forensics (copy into /skan/verify, diff
        // against another implementation), so the default 512-char cap would
        // corrupt exactly the oversized forged values this path exists to
        // inspect. Character hygiene still applies; the length cap matches
        // what the receiver accepts.
        $row['attribution_signature'] = ResponseSanitizer::cleanVisitorString(
            (string)($row['attribution_signature'] ?? ''),
            4096
        );

        return ['data' => $row];
    }

    /**
     * Aggregate SKAN report with conversion-value decoding.
     *
     * group_by: day (default, UTC), app, ad-network, source, country,
     * version. By default every trusted metric (installs, losses,
     * redownloads, conversion-value decoding, revenue) counts ONLY
     * signature-verified postbacks — the receiver stores forged and
     * unverifiable rows flagged, and anyone can POST well-formed junk at the
     * open endpoint, so unverified rows must not move headline numbers.
     * They remain visible per group via the signature_*_count columns, and
     * an explicit ?signature= filter recomputes the metrics over exactly
     * that class (e.g. signature=invalid to inspect what forged rows claim,
     * or during integration tests with self-signed fixtures).
     */
    public function report(array $params): array
    {
        $groupBy = (string)($params['group_by'] ?? 'day');
        if (!isset(self::GROUP_MODES[$groupBy])) {
            throw new ValidationException('Invalid group_by', [
                'group_by' => 'Must be one of: ' . implode(', ', array_keys(self::GROUP_MODES)),
            ]);
        }
        $aliasMap = self::GROUP_MODES[$groupBy];
        $maxGroups = self::boundedInt($params, 'limit', 100, 1, 500);

        [$where, $binds, $types, $explicitSignature] = $this->buildFilters($params);
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // The trust gate for headline metrics. With an explicit signature
        // filter the row set is already the class the caller asked about.
        $trusted = $explicitSignature ? '1 = 1' : 'signature_valid = 1';

        $selectGroup = [];
        foreach ($aliasMap as $alias => $expr) {
            $selectGroup[] = "$expr AS $alias";
        }
        $groupByExpr = implode(', ', array_values($aliasMap));
        $firstAlias = array_key_first($aliasMap);

        $winCondition = '(did_win IS NULL OR did_win = 1)';
        $firstWindow = 'COALESCE(postback_sequence_index, 0) = 0';

        // Day groups keep the NEWEST window (DESC + limit, re-sorted
        // ascending for output); other modes keep the busiest groups.
        $orderBy = $groupBy === 'day' ? "$firstAlias DESC" : 'postbacks DESC';

        $sql = 'SELECT ' . implode(', ', $selectGroup) . ",
                COUNT(*) AS postbacks,
                SUM(CASE WHEN $trusted AND did_win = 0 THEN 1 ELSE 0 END) AS losses,
                SUM(CASE WHEN $trusted AND $winCondition AND $firstWindow AND COALESCE(redownload, 0) = 0 THEN 1 ELSE 0 END) AS installs,
                SUM(CASE WHEN $trusted AND $winCondition AND $firstWindow AND redownload = 1 THEN 1 ELSE 0 END) AS redownloads,
                SUM(CASE WHEN signature_valid = 1 THEN 1 ELSE 0 END) AS signature_valid_count,
                SUM(CASE WHEN signature_valid = 0 THEN 1 ELSE 0 END) AS signature_invalid_count,
                SUM(CASE WHEN signature_valid IS NULL THEN 1 ELSE 0 END) AS signature_unverified_count
            FROM 202_skan_postbacks
            $whereClause
            GROUP BY $groupByExpr
            ORDER BY $orderBy
            LIMIT ?";

        $stmt = $this->prepare($sql);
        $groupBinds = $binds;
        $groupTypes = $types . 'i';
        $groupBinds[] = $maxGroups + 1; // one extra row detects truncation
        $this->bind($stmt, $groupTypes, ...$groupBinds);
        $this->execute($stmt, 'Report query failed');
        $result = $this->result($stmt);

        $groupsOut = [];
        while ($row = $result->fetch_assoc()) {
            $key = $this->groupKey($aliasMap, $row);
            $groupsOut[$key] = $this->initGroupRow($groupBy, $row);
        }
        $stmt->close();

        $truncated = count($groupsOut) > $maxGroups;
        if ($truncated) {
            $groupsOut = array_slice($groupsOut, 0, $maxGroups, preserve_keys: true);
        }

        // Conversion-value decode: distribution of values per group, folded
        // through the user's rules in PHP (rule resolution — app-specific
        // over default — is not expressible as a sane single JOIN). The row
        // set is bounded to the retained group window so the query never
        // aggregates combinations the fold would discard.
        $cvWhere = $where;
        $cvBinds = $binds;
        $cvTypes = $types;
        $this->restrictToRetainedGroups($groupBy, $groupsOut, $cvWhere, $cvBinds, $cvTypes);
        $cvWhereClause = 'WHERE ' . implode(' AND ', $cvWhere);

        $cvSql = 'SELECT ' . implode(', ', $selectGroup) . ",
                app_id AS cv_app_id, conversion_value, coarse_conversion_value, COUNT(*) AS cnt
            FROM 202_skan_postbacks
            $cvWhereClause AND $trusted AND $winCondition
            GROUP BY $groupByExpr, app_id, conversion_value, coarse_conversion_value";
        $stmt = $this->prepare($cvSql);
        $this->bind($stmt, $cvTypes, ...$cvBinds);
        $this->execute($stmt, 'Report decode query failed');
        $result = $this->result($stmt);

        $rules = $this->conversionRules();
        while ($row = $result->fetch_assoc()) {
            $key = $this->groupKey($aliasMap, $row);
            if (!isset($groupsOut[$key])) {
                continue; // group beyond the retained window (source mode only)
            }
            $count = (int)$row['cnt'];
            $fine = $row['conversion_value'] === null ? null : (int)$row['conversion_value'];
            $coarse = $row['coarse_conversion_value'] === null ? null : (string)$row['coarse_conversion_value'];
            if ($fine === null && $coarse === null) {
                $groupsOut[$key]['null_conversion_values'] += $count;
                continue;
            }
            $groupsOut[$key]['measurable'] += $count;
            $rule = $this->resolveRule($rules, (int)$row['cv_app_id'], $fine, $coarse);
            if ($rule === null) {
                $groupsOut[$key]['undecoded'] += $count;
                continue;
            }
            $groupsOut[$key]['decoded'] += $count;
            $revenue = (float)$rule['revenue'] * $count;
            $groupsOut[$key]['decoded_revenue'] = round($groupsOut[$key]['decoded_revenue'] + $revenue, 5);
            $eventName = (string)$rule['event_name'];
            if (!isset($groupsOut[$key]['events'][$eventName])) {
                $groupsOut[$key]['events'][$eventName] = ['count' => 0, 'revenue' => 0.0];
            }
            $groupsOut[$key]['events'][$eventName]['count'] += $count;
            $groupsOut[$key]['events'][$eventName]['revenue'] = round($groupsOut[$key]['events'][$eventName]['revenue'] + $revenue, 5);
        }
        $stmt->close();

        if ($groupBy === 'app') {
            $this->attachAppNames($groupsOut);
        }

        $groups = array_values($groupsOut);
        if ($groupBy === 'day') {
            usort($groups, static fn(array $a, array $b): int => strcmp((string)$a['date'], (string)$b['date']));
        }
        foreach ($groups as &$group) {
            // Event names can be numeric strings; as a PHP array they would
            // JSON-encode as a list and drop the names, which the iOS
            // helper's dictionary decoder rejects. An object survives.
            $group['events'] = (object)$group['events'];
        }
        unset($group);

        return [
            'data' => [
                'group_by' => $groupBy,
                'groups' => $groups,
            ],
            'meta' => [
                'timezone' => 'UTC',
                'trusted' => $explicitSignature ? 'as-filtered' : 'verified-only',
                'groups_truncated' => $truncated,
                'notes' => ($explicitSignature
                    ? 'metrics computed over the signature class the filter selected'
                    : 'installs/losses/decoding count signature-verified postbacks only; unverified rows appear in the signature_*_count columns')
                    . '; installs = winning first-window postbacks excluding redownloads'
                    . '; conversion values decode across all three windows, so measurable/decoded can exceed installs'
                    . '; conversion values decode through /skan/conversion-values rules',
            ],
        ];
    }

    /**
     * Ad-hoc signature verification for a postback payload — lets an
     * operator or agent confirm end-to-end that a captured postback (or a
     * test fixture) verifies before trusting the pipeline. Nothing is
     * stored.
     *
     * @param array<string, mixed> $payload The postback JSON, as received.
     */
    public function verify(array $payload): array
    {
        if ($payload === []) {
            throw new ValidationException('Provide the postback JSON object as the request body');
        }
        $verifier = new PostbackVerifier();
        $state = $verifier->verify($payload);
        $message = $verifier->buildSignedMessage($payload);

        return [
            'data' => [
                'signature' => $state,
                // Base64 of the exact byte string Apple signed (fields joined
                // with U+2063) — for diffing against another implementation
                // when a signature unexpectedly fails.
                'signed_message_base64' => $message === null ? null : base64_encode($message),
                'verifiable_versions' => PostbackVerifier::VERIFIABLE_VERSIONS,
            ],
        ];
    }

    // ─── Internals ───────────────────────────────────────────────────

    /**
     * A paging bound, or a 422 naming the parameter.
     *
     * Deliberately not `max(1, min(500, (int)$v))`: casting silently turned
     * ?limit=abc into 1 and ?offset=-5 into 0, so a caller who mistyped a
     * limit got one row back and read it as "the account has one postback".
     * That is the same silent coercion buildFilters below rejects for every
     * other parameter (error patterns #4 and #5). An out-of-range NUMBER
     * still clamps — a range is a documented ceiling, not a typo.
     *
     * @param array<string, mixed> $params
     */
    private static function boundedInt(array $params, string $key, int $default, int $min, int $max): int
    {
        if (!isset($params[$key]) || $params[$key] === '') {
            return $default;
        }
        $raw = $params[$key];
        if (!is_int($raw) && !(is_string($raw) && preg_match('/^-?\d+$/D', $raw) === 1)) {
            throw new ValidationException('Invalid paging value', [
                $key => 'Must be an integer',
            ]);
        }
        return max($min, min($max, (int)$raw));
    }

    /**
     * Shared filter builder for list() and report(). Malformed values are
     * rejected loudly (error pattern #4): a coerced did_win=true would
     * silently filter for LOSING postbacks, and app_id=abc would silently
     * become app 0 — both worse than a 422 naming the field.
     *
     * @return array{0: string[], 1: mixed[], 2: string, 3: bool} where the
     *         final element reports whether an explicit signature filter was
     *         given (list() ignores it; report() keys its trust gate on it).
     */
    private function buildFilters(array $params): array
    {
        $where = ['user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        foreach (['time_from' => 'received_at >= ?', 'time_to' => 'received_at <= ?'] as $param => $condition) {
            if (isset($params[$param]) && $params[$param] !== '') {
                if (!is_numeric($params[$param])) {
                    throw new ValidationException('Invalid time filter', [
                        $param => 'Must be a unix timestamp',
                    ]);
                }
                $where[] = $condition;
                $binds[] = (int)$params[$param];
                $types .= 'i';
            }
        }
        foreach (['app_id', 'campaign_id', 'fidelity_type', 'postback_sequence_index'] as $param) {
            if (isset($params[$param]) && $params[$param] !== '') {
                if (!is_numeric($params[$param])) {
                    throw new ValidationException('Invalid filter value', [
                        $param => 'Must be an integer',
                    ]);
                }
                $where[] = "$param = ?";
                $binds[] = (int)$params[$param];
                $types .= 'i';
            }
        }
        foreach (['ad_network_id', 'version', 'transaction_id', 'country_code', 'source_identifier', 'coarse_conversion_value'] as $param) {
            if (isset($params[$param]) && $params[$param] !== '') {
                $where[] = "$param = ?";
                $binds[] = (string)$params[$param];
                $types .= 's';
            }
        }
        foreach (['did_win', 'redownload'] as $flag) {
            if (isset($params[$flag]) && $params[$flag] !== '') {
                $bool = filter_var($params[$flag], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool === null) {
                    throw new ValidationException('Invalid filter value', [
                        $flag => 'Must be a boolean (1/0/true/false)',
                    ]);
                }
                $where[] = "$flag = ?";
                $binds[] = (int)$bool;
                $types .= 'i';
            }
        }

        $explicitSignature = false;
        if (isset($params['signature']) && $params['signature'] !== '') {
            $explicitSignature = true;
            $signature = strtolower(trim((string)$params['signature']));
            if ($signature === PostbackVerifier::RESULT_VALID) {
                $where[] = 'signature_valid = 1';
            } elseif ($signature === PostbackVerifier::RESULT_INVALID) {
                $where[] = 'signature_valid = 0';
            } elseif ($signature === PostbackVerifier::RESULT_UNVERIFIABLE) {
                $where[] = 'signature_valid IS NULL';
            } else {
                throw new ValidationException('Invalid signature filter', [
                    'signature' => 'Must be one of: valid, invalid, unverifiable',
                ]);
            }
        }

        return [$where, $binds, $types, $explicitSignature];
    }

    /**
     * Bound the decode query to the groups the report retained, so it never
     * aggregates and ships combinations the fold would discard. Day mode
     * bounds by the retained time window; single-column modes bind an IN
     * list of the retained keys. Source mode (two columns, both nullable)
     * keeps the PHP-side discard — its cardinality is bounded by the ad
     * networks' own 4-digit identifier space.
     *
     * @param array<string, array<string, mixed>> $groupsOut
     * @param string[] $where
     * @param mixed[] $binds
     */
    private function restrictToRetainedGroups(string $groupBy, array $groupsOut, array &$where, array &$binds, string &$types): void
    {
        if ($groupsOut === []) {
            // No groups retained: make the decode query return nothing
            // rather than everything.
            $where[] = '1 = 0';
            return;
        }

        switch ($groupBy) {
            case 'day':
                $days = array_map(static fn(string $key): int => (int)$key, array_keys($groupsOut));
                $where[] = 'received_at >= ?';
                $binds[] = min($days);
                $types .= 'i';
                $where[] = 'received_at < ?';
                $binds[] = max($days) + 86400;
                $types .= 'i';
                return;
            case 'app':
                $keys = array_map('intval', array_keys($groupsOut));
                $where[] = 'app_id IN (' . implode(', ', array_fill(0, count($keys), '?')) . ')';
                foreach ($keys as $key) {
                    $binds[] = $key;
                    $types .= 'i';
                }
                return;
            case 'ad-network':
            case 'country':
            case 'version':
                $column = ['ad-network' => 'ad_network_id', 'country' => 'country_code', 'version' => 'version'][$groupBy];
                $values = [];
                $hasNull = false;
                foreach (array_keys($groupsOut) as $key) {
                    if ($key === '') {
                        $hasNull = true; // NULL group key (country only)
                    } else {
                        $values[] = (string)$key;
                    }
                }
                $parts = [];
                if ($values !== []) {
                    $parts[] = "$column IN (" . implode(', ', array_fill(0, count($values), '?')) . ')';
                    foreach ($values as $value) {
                        $binds[] = $value;
                        $types .= 's';
                    }
                }
                if ($hasNull) {
                    $parts[] = "$column IS NULL";
                }
                $where[] = '(' . implode(' OR ', $parts) . ')';
                return;
            case 'source':
            default:
                return;
        }
    }

    /**
     * @param array<string, string> $aliasMap
     * @param array<string, mixed> $row
     */
    private function groupKey(array $aliasMap, array $row): string
    {
        $parts = [];
        foreach (array_keys($aliasMap) as $alias) {
            $parts[] = $row[$alias] === null ? '' : (string)$row[$alias];
        }
        return implode('|', $parts);
    }

    /** @return array<string, mixed> */
    private function initGroupRow(string $groupBy, array $row): array
    {
        $group = match ($groupBy) {
            'day' => ['date' => gmdate('Y-m-d', (int)$row['grp_day'])],
            'app' => ['app_id' => (int)$row['grp_app_id']],
            'ad-network' => ['ad_network_id' => ResponseSanitizer::cleanVisitorString((string)($row['grp_ad_network_id'] ?? ''))],
            'source' => [
                'source_identifier' => $row['grp_source_identifier'] === null ? null : ResponseSanitizer::cleanVisitorString((string)$row['grp_source_identifier']),
                'campaign_id' => $row['grp_campaign_id'] === null ? null : (int)$row['grp_campaign_id'],
            ],
            'country' => ['country_code' => $row['grp_country_code'] === null ? null : ResponseSanitizer::cleanVisitorString((string)$row['grp_country_code'])],
            'version' => ['version' => ResponseSanitizer::cleanVisitorString((string)($row['grp_version'] ?? ''))],
            default => [],
        };

        return $group + [
            'postbacks' => (int)$row['postbacks'],
            'losses' => (int)$row['losses'],
            'installs' => (int)$row['installs'],
            'redownloads' => (int)$row['redownloads'],
            'signature_valid_count' => (int)$row['signature_valid_count'],
            'signature_invalid_count' => (int)$row['signature_invalid_count'],
            'signature_unverified_count' => (int)$row['signature_unverified_count'],
            'measurable' => 0,
            'decoded' => 0,
            'undecoded' => 0,
            'null_conversion_values' => 0,
            'decoded_revenue' => 0.0,
            'events' => [],
        ];
    }

    /**
     * The user's decode rules, indexed for resolution.
     *
     * @return array{fine: array<int, array<int, array{event_name: string, revenue: string}>>,
     *               coarse: array<int, array<string, array{event_name: string, revenue: string}>>}
     */
    private function conversionRules(): array
    {
        $sql = 'SELECT app_id, fine_value, coarse_value, event_name, revenue FROM 202_skan_conversion_values WHERE user_id = ?';
        $stmt = $this->prepare($sql);
        $this->bind($stmt, 'i', $this->userId);
        $this->execute($stmt, 'Rules query failed');
        $result = $this->result($stmt);

        $rules = ['fine' => [], 'coarse' => []];
        while ($row = $result->fetch_assoc()) {
            $appId = (int)$row['app_id'];
            $rule = ['event_name' => (string)$row['event_name'], 'revenue' => (string)$row['revenue']];
            if ($row['fine_value'] !== null) {
                $rules['fine'][$appId][(int)$row['fine_value']] = $rule;
            } elseif ($row['coarse_value'] !== null) {
                $rules['coarse'][$appId][(string)$row['coarse_value']] = $rule;
            }
        }
        $stmt->close();
        return $rules;
    }

    /**
     * App-specific rule wins over the app_id = 0 default; a fine value that
     * has no rule falls back to nothing (never to the coarse rules — the
     * fine value is the more precise signal and a silent remap would
     * misreport).
     *
     * @param array{fine: array<int, array<int, array{event_name: string, revenue: string}>>,
     *              coarse: array<int, array<string, array{event_name: string, revenue: string}>>} $rules
     * @return array{event_name: string, revenue: string}|null
     */
    private function resolveRule(array $rules, int $appId, ?int $fine, ?string $coarse): ?array
    {
        if ($fine !== null) {
            return $rules['fine'][$appId][$fine] ?? $rules['fine'][0][$fine] ?? null;
        }
        if ($coarse !== null) {
            return $rules['coarse'][$appId][$coarse] ?? $rules['coarse'][0][$coarse] ?? null;
        }
        return null;
    }

    /** @param array<string, array<string, mixed>> $groupsOut */
    private function attachAppNames(array &$groupsOut): void
    {
        $sql = 'SELECT app_id, app_name FROM 202_skan_apps WHERE user_id = ?';
        $stmt = $this->prepare($sql);
        $this->bind($stmt, 'i', $this->userId);
        $this->execute($stmt, 'App names query failed');
        $result = $this->result($stmt);
        $names = [];
        while ($row = $result->fetch_assoc()) {
            $names[(int)$row['app_id']] = (string)$row['app_name'];
        }
        $stmt->close();

        foreach ($groupsOut as &$group) {
            $group['app_name'] = $names[(int)($group['app_id'] ?? 0)] ?? null;
        }
        unset($group);
    }
}
