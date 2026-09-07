<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\DatabaseException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Skan\PostbackVerifier;
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
        'attribution_signature',
    ];

    private const SELECT_COLUMNS = 'postback_id, user_id, received_at, version, ad_network_id, '
        . 'transaction_id, app_id, source_identifier, campaign_id, conversion_value, '
        . 'coarse_conversion_value, postback_sequence_index, redownload, did_win, '
        . 'source_app_id, source_domain, fidelity_type, country_code, signature_valid, remote_ip';

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    public function list(array $params): array
    {
        $limit = max(1, min(500, (int)($params['limit'] ?? 50)));
        $offset = max(0, (int)($params['offset'] ?? 0));

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
            unset($row['attribution_signature']);
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

        return ['data' => ResponseSanitizer::cleanRowFields($row, self::UNTRUSTED_FIELDS)];
    }

    /**
     * Aggregate SKAN report with conversion-value decoding.
     *
     * group_by: day (default, UTC), app, ad-network, source, country,
     * version. Winning postbacks' conversion values are decoded through the
     * user's 202_skan_conversion_values rules (app-specific rules first,
     * then the app_id = 0 defaults) into named events and revenue.
     */
    public function report(array $params): array
    {
        $groupBy = (string)($params['group_by'] ?? 'day');
        $groups = [
            'day'        => ['expr' => 'FLOOR(received_at / 86400) * 86400', 'cols' => ['grp_day']],
            'app'        => ['expr' => 'app_id', 'cols' => ['grp_app_id']],
            'ad-network' => ['expr' => 'ad_network_id', 'cols' => ['grp_ad_network_id']],
            'source'     => ['expr' => 'source_identifier, campaign_id', 'cols' => ['grp_source_identifier', 'grp_campaign_id']],
            'country'    => ['expr' => 'country_code', 'cols' => ['grp_country_code']],
            'version'    => ['expr' => 'version', 'cols' => ['grp_version']],
        ];
        if (!isset($groups[$groupBy])) {
            throw new ValidationException('Invalid group_by', [
                'group_by' => 'Must be one of: ' . implode(', ', array_keys($groups)),
            ]);
        }
        $maxGroups = max(1, min(500, (int)($params['limit'] ?? 100)));

        [$where, $binds, $types] = $this->buildFilters($params);
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $groupExpr = $groups[$groupBy]['expr'];
        $groupCols = $groups[$groupBy]['cols'];
        $selectGroup = [];
        $exprParts = explode(', ', $groupExpr);
        foreach ($exprParts as $i => $expr) {
            $selectGroup[] = "$expr AS {$groupCols[$i]}";
        }

        $winCondition = '(did_win IS NULL OR did_win = 1)';
        $firstWindow = 'COALESCE(postback_sequence_index, 0) = 0';

        $sql = 'SELECT ' . implode(', ', $selectGroup) . ",
                COUNT(*) AS postbacks,
                SUM(CASE WHEN did_win = 0 THEN 1 ELSE 0 END) AS losses,
                SUM(CASE WHEN $winCondition AND $firstWindow AND COALESCE(redownload, 0) = 0 THEN 1 ELSE 0 END) AS installs,
                SUM(CASE WHEN $winCondition AND $firstWindow AND redownload = 1 THEN 1 ELSE 0 END) AS redownloads,
                SUM(CASE WHEN signature_valid = 1 THEN 1 ELSE 0 END) AS signature_valid_count,
                SUM(CASE WHEN signature_valid = 0 THEN 1 ELSE 0 END) AS signature_invalid_count,
                SUM(CASE WHEN signature_valid IS NULL THEN 1 ELSE 0 END) AS signature_unverified_count
            FROM 202_skan_postbacks
            $whereClause
            GROUP BY $groupExpr
            ORDER BY " . ($groupBy === 'day' ? 'grp_day ASC' : 'postbacks DESC') . '
            LIMIT ?';

        $stmt = $this->prepare($sql);
        $groupBinds = $binds;
        $groupTypes = $types . 'i';
        $groupBinds[] = $maxGroups;
        $this->bind($stmt, $groupTypes, ...$groupBinds);
        $this->execute($stmt, 'Report query failed');
        $result = $this->result($stmt);

        $groupsOut = [];
        while ($row = $result->fetch_assoc()) {
            $key = $this->groupKey($groupBy, $row);
            $groupsOut[$key] = $this->initGroupRow($groupBy, $row);
        }
        $stmt->close();

        // Conversion-value decode: distribution of values per group, folded
        // through the user's rules in PHP (rule resolution — app-specific
        // over default — is not expressible as a sane single JOIN).
        $cvSql = 'SELECT ' . implode(', ', $selectGroup) . ",
                app_id AS cv_app_id, conversion_value, coarse_conversion_value, COUNT(*) AS cnt
            FROM 202_skan_postbacks
            $whereClause AND $winCondition
            GROUP BY $groupExpr, app_id, conversion_value, coarse_conversion_value";
        $stmt = $this->prepare($cvSql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Report decode query failed');
        $result = $this->result($stmt);

        $rules = $this->conversionRules();
        while ($row = $result->fetch_assoc()) {
            $key = $this->groupKey($groupBy, $row);
            if (!isset($groupsOut[$key])) {
                continue; // group beyond the LIMIT window
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

        return [
            'data' => [
                'group_by' => $groupBy,
                'groups' => array_values($groupsOut),
            ],
            'meta' => [
                'timezone' => 'UTC',
                'notes' => 'installs = winning first-window postbacks excluding redownloads; '
                    . 'conversion values decode through /skan/conversion-values rules',
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
     * Shared filter builder for list() and report().
     *
     * @return array{0: string[], 1: mixed[], 2: string}
     */
    private function buildFilters(array $params): array
    {
        $where = ['user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        if (!empty($params['time_from'])) {
            $where[] = 'received_at >= ?';
            $binds[] = (int)$params['time_from'];
            $types .= 'i';
        }
        if (!empty($params['time_to'])) {
            $where[] = 'received_at <= ?';
            $binds[] = (int)$params['time_to'];
            $types .= 'i';
        }
        foreach (['app_id' => 'app_id', 'campaign_id' => 'campaign_id', 'fidelity_type' => 'fidelity_type', 'postback_sequence_index' => 'postback_sequence_index'] as $param => $column) {
            if (isset($params[$param]) && $params[$param] !== '') {
                $where[] = "$column = ?";
                $binds[] = (int)$params[$param];
                $types .= 'i';
            }
        }
        foreach (['ad_network_id' => 'ad_network_id', 'version' => 'version', 'transaction_id' => 'transaction_id', 'country_code' => 'country_code', 'source_identifier' => 'source_identifier', 'coarse_conversion_value' => 'coarse_conversion_value'] as $param => $column) {
            if (isset($params[$param]) && $params[$param] !== '') {
                $where[] = "$column = ?";
                $binds[] = (string)$params[$param];
                $types .= 's';
            }
        }
        foreach (['did_win', 'redownload'] as $flag) {
            if (isset($params[$flag]) && $params[$flag] !== '') {
                $where[] = "$flag = ?";
                $binds[] = (int)(bool)(int)$params[$flag];
                $types .= 'i';
            }
        }
        if (isset($params['signature']) && $params['signature'] !== '') {
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

        return [$where, $binds, $types];
    }

    private function groupKey(string $groupBy, array $row): string
    {
        return match ($groupBy) {
            'day' => (string)(int)$row['grp_day'],
            'app' => (string)(int)$row['grp_app_id'],
            'ad-network' => (string)($row['grp_ad_network_id'] ?? ''),
            'source' => (string)($row['grp_source_identifier'] ?? '') . '|' . (string)($row['grp_campaign_id'] ?? ''),
            'country' => (string)($row['grp_country_code'] ?? ''),
            'version' => (string)($row['grp_version'] ?? ''),
            default => '',
        };
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

    private function prepare(string $sql): \mysqli_stmt
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            throw new DatabaseException('Prepare failed');
        }
        return $stmt;
    }

    private function bind(\mysqli_stmt $stmt, string $types, mixed ...$values): void
    {
        // @phpstan-ignore-next-line prosper202.directStmtCall — this IS the centralized ref-safe bind wrapper (no Connection instance in scope; routing through $this->conn would self-recurse)
        if (!$stmt->bind_param($types, ...$values)) {
            $stmt->close();
            throw new DatabaseException('Bind failed');
        }
    }

    private function execute(\mysqli_stmt $stmt, string $message): void
    {
        // @phpstan-ignore-next-line prosper202.directStmtCall — this IS the centralized checked-execute wrapper (no Connection instance; routing through $this->conn would self-recurse)
        if (!$stmt->execute()) {
            $stmt->close();
            throw new DatabaseException($message);
        }
    }

    /**
     * get_result() returning false is indistinguishable from an empty result
     * set at the call site (error pattern #1's get_result variant), so it is
     * checked centrally here.
     */
    private function result(\mysqli_stmt $stmt): \mysqli_result
    {
        $result = $stmt->get_result();
        if ($result === false) {
            $stmt->close();
            throw new DatabaseException('Result retrieval failed');
        }
        return $result;
    }
}
