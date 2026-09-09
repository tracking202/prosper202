<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use Api\V3\Attribution\AdAttributionKitProtocol;
use Api\V3\Attribution\JwsVerifier;
use Api\V3\Attribution\PostbackVerifier;
use Api\V3\Attribution\Protocols;
use Api\V3\Attribution\SignatureState;
use Api\V3\Attribution\SkadnetworkProtocol;
use Api\V3\Support\MysqliStatements;
use Api\V3\Support\ResponseSanitizer;

/**
 * Read-only access to received attribution postbacks (SKAdNetwork and
 * AdAttributionKit share one table, told apart by `protocol`), plus the
 * aggregate report and an ad-hoc signature verification utility.
 *
 * Postbacks are written only by the public receivers under /.well-known/;
 * the API never mutates them. Rows belong to the user whose 202_attribution_apps registration matches the
 * advertised app (user_id = 0 rows are unclaimed and become visible once the
 * app is registered — AttributionAppsController claims them).
 *
 * Postback fields are authored by whoever POSTs to the open receiver, so
 * every string column is passed through ResponseSanitizer on the way out,
 * and the raw request body is deliberately never served through the API —
 * the parsed columns carry all of its meaning.
 */
class AttributionPostbacksController
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
        'marketplace_id',
        'country_code',
        'key_id',
        'remote_ip',
    ];

    private const SELECT_COLUMNS = 'postback_id, user_id, received_at, protocol, version, ad_network_id, '
        . 'transaction_id, app_id, source_identifier, campaign_id, conversion_value, '
        . 'coarse_conversion_value, postback_sequence_index, conversion_type, redownload, did_win, '
        . 'ad_interaction_type, source_app_id, source_domain, marketplace_id, fidelity_type, country_code, '
        . 'signature_state, signature_valid, key_id, remote_ip';

    /**
     * Report grouping modes — the ONE table the SELECT list, the GROUP BY,
     * the retained-group restriction and the output row are all derived
     * from, so adding a mode is a single entry here.
     *
     * Each mode is its list of grouped columns, in output order:
     *   alias  SQL alias the group value comes back under
     *   expr   SQL expression grouped on (a column, or day's bucket)
     *   key    field name in the response group row
     *   kind   how the value renders and how the decode query is bounded
     *
     * kind is a closed set — day, int, nullable-int, string,
     * nullable-string — and both places that consume it (initGroupRow() and
     * restrictToRetainedGroups()) match on it WITHOUT a default arm, so a
     * kind added here and forgotten there fails loudly instead of silently
     * dropping the key column or unbounding the decode query.
     */
    private const GROUP_MODES = [
        'day'             => [['alias' => 'grp_day', 'expr' => 'FLOOR(received_at / 86400) * 86400', 'key' => 'date', 'kind' => 'day']],
        'app'             => [['alias' => 'grp_app_id', 'expr' => 'app_id', 'key' => 'app_id', 'kind' => 'int']],
        'ad-network'      => [['alias' => 'grp_ad_network_id', 'expr' => 'ad_network_id', 'key' => 'ad_network_id', 'kind' => 'string']],
        'source'          => [
            ['alias' => 'grp_source_identifier', 'expr' => 'source_identifier', 'key' => 'source_identifier', 'kind' => 'nullable-string'],
            ['alias' => 'grp_campaign_id', 'expr' => 'campaign_id', 'key' => 'campaign_id', 'kind' => 'nullable-int'],
        ],
        'country'         => [['alias' => 'grp_country_code', 'expr' => 'country_code', 'key' => 'country_code', 'kind' => 'nullable-string']],
        'version'         => [['alias' => 'grp_version', 'expr' => 'version', 'key' => 'version', 'kind' => 'nullable-string']],
        'protocol'        => [['alias' => 'grp_protocol', 'expr' => 'protocol', 'key' => 'protocol', 'kind' => 'string']],
        'conversion-type' => [['alias' => 'grp_conversion_type', 'expr' => 'conversion_type', 'key' => 'conversion_type', 'kind' => 'nullable-string']],
    ];

    /**
     * What makes two rows the same postback: the framework's postback id,
     * namespaced by protocol and ad network, per conversion window. Apple
     * says to count unique postback ids, and the receiver deliberately
     * stores a replay whose UNSIGNED fields differ (a different
     * conversion value on the same signed postback) as its own row rather
     * than letting either copy block the other — so the report, not the
     * store, is where a replayed postback collapses to one.
     *
     * Length-prefixed and compared as bytes, for the reason
     * PostbackReceiver::dedupeHash() gives about its own serialization:
     * neither protocol restricts the characters in an ad-network-id or a
     * transaction-id, so joining them with a plain '|' made
     * ('acme.skadnetwork|A9F3', 'B7C2') and ('acme.skadnetwork',
     * 'A9F3|B7C2') — two genuinely different postbacks, stored as two rows
     * — one identity string that counted once. The prefixes pin the field
     * boundaries whatever the strings contain. LENGTH (bytes) rather than
     * CHAR_LENGTH (characters): either is injective used consistently, and
     * bytes are what dedupeHash()'s strlen() counts and what the CAST
     * compares. The CAST is that comparison — the columns collate
     * utf8mb4_general_ci, under which COUNT(DISTINCT ...) folds
     * 'ACME.skadnetwork' into 'acme.skadnetwork', the same two-rows-counted-
     * as-one collapse by a different route.
     */
    private const IDENTITY = "CAST(CONCAT_WS('|',"
        . " CONCAT(LENGTH(protocol), ':', protocol),"
        . " CONCAT(LENGTH(ad_network_id), ':', ad_network_id),"
        . " CONCAT(LENGTH(transaction_id), ':', transaction_id),"
        . ' COALESCE(postback_sequence_index, -1)) AS BINARY)';

    public function __construct(private readonly \mysqli $db, private readonly int $userId)
    {
    }

    public function list(array $params): array
    {
        $limit = self::boundedInt($params, 'limit', 50, 1, 500);
        $offset = self::boundedInt($params, 'offset', 0, 0, PHP_INT_MAX);

        [$where, $binds, $types] = $this->buildFilters($params);
        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) as total FROM 202_attribution_postbacks $whereClause";
        $stmt = $this->prepare($countSql);
        $this->bind($stmt, $types, ...$binds);
        $this->execute($stmt, 'Count query failed');
        $countResult = $this->result($stmt);
        $total = (int)(($countResult->fetch_assoc()['total']) ?? 0);
        $stmt->close();

        // Newest first, by receipt time and then by the primary key so the
        // order is total and stable across pages. Ordering by postback_id
        // alone read as the same thing but had no index a tenant's rows are
        // ordered by (every key here is prefixed with user_id), so the
        // server walked the PRIMARY key backwards through every other
        // tenant's rows: 101 ms for page 1 against 0.2 ms on the
        // user_received ref scan, measured on 205k rows of which 5k were
        // the tenant's.
        $sql = 'SELECT ' . self::SELECT_COLUMNS . " FROM 202_attribution_postbacks $whereClause"
            . ' ORDER BY received_at DESC, postback_id DESC LIMIT ? OFFSET ?';
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
            . ' FROM 202_attribution_postbacks WHERE postback_id = ? AND user_id = ? LIMIT 1';
        $stmt = $this->prepare($sql);
        $this->bind($stmt, 'ii', $id, $this->userId);
        $this->execute($stmt, 'Query failed');
        $row = $this->result($stmt)->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new NotFoundException('Postback not found');
        }

        $row = ResponseSanitizer::cleanRowFields($row, self::UNTRUSTED_FIELDS);
        // The signature is served for forensics (copy into /attribution/verify, diff
        // against another implementation), so the default 512-char cap would
        // corrupt exactly the oversized forged values this path exists to
        // inspect. Character hygiene still applies; the length cap is the
        // LARGER of the two receivers' limits — SKAdNetwork caps the
        // base64 signature at 4096 bytes, AdAttributionKit stores the whole
        // compact JWS up to MAX_JWS_LENGTH — because a cap that fits only
        // one protocol truncates the other's stored value and appends the
        // truncation marker, and the result no longer verifies when pasted
        // back into /attribution/verify. Bytes bound characters, so nothing
        // either receiver accepted is cut here.
        $row['attribution_signature'] = ResponseSanitizer::cleanVisitorString(
            (string)($row['attribution_signature'] ?? ''),
            AdAttributionKitProtocol::MAX_JWS_LENGTH
        );

        return ['data' => $row];
    }

    /**
     * Aggregate attribution report with conversion-value decoding.
     *
     * group_by: day (default, UTC), app, ad-network, source, country,
     * version, protocol, conversion-type. Every metric counts unique
     * postbacks (IDENTITY), so a replay of one signed postback with a
     * different unsigned field counts once. By default every trusted
     * metric (installs, losses, redownloads, re-engagements,
     * conversion-value decoding, revenue) counts ONLY
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
        $groupColumns = self::GROUP_MODES[$groupBy];
        $maxGroups = self::boundedInt($params, 'limit', 100, 1, 500);

        // Day mode keeps only the newest $maxGroups day groups, so without a
        // time_from the query aggregated the tenant's entire retained
        // history (verified rows are kept forever) to throw all but the last
        // few months away, and got slower every month: 73 ms against 46 ms
        // for a default report over 24k rows spanning 800 populated days
        // (MariaDB 10.11). So the day query is TRIED bounded to the newest
        // $maxGroups + 1 days, where the user_received index bounds the
        // scan.
        //
        // The bound is an optimisation, never an answer. It is provably
        // result-identical only when the bounded query comes back FULL: it
        // orders by day DESC, and every day the bound excluded is older than
        // every day it kept, so $maxGroups + 1 groups from inside the window
        // ARE the $maxGroups + 1 newest groups overall. A short page proves
        // nothing about older days — a tenant with 6 populated days spread
        // over 700 came back with 2 — so it is thrown away and the query
        // re-run unbounded. Dense tenants (the ones the bound was measured
        // on) fill the page and never pay for the second query; sparse ones
        // get exactly the groups they got before the bound existed. Since
        // the answer is identical either way, nothing about the window is
        // disclosed to the caller.
        //
        // Only day mode: the other modes rank by count, not by time, so a
        // window would change which groups they return and no cheap test
        // could tell that it had.
        $boundedTimeFrom = null;
        if ($groupBy === 'day' && (!isset($params['time_from']) || $params['time_from'] === '')) {
            // Anchored to the caller's time_to when they gave one, so the
            // window covers the newest days they asked about rather than the
            // newest days there are; otherwise to now.
            $anchor = isset($params['time_to']) && $params['time_to'] !== ''
                ? self::strictInt($params['time_to'], 'time_to', 'Invalid time filter', 'Must be a unix timestamp')
                : time();
            $boundedTimeFrom = $anchor - ($maxGroups + 1) * 86400;
        }

        $firstParams = $params;
        if ($boundedTimeFrom !== null) {
            $firstParams['time_from'] = $boundedTimeFrom;
        }
        [$where, $binds, $types, $explicitSignature] = $this->buildFilters($firstParams);

        // The trust gate for headline metrics. With an explicit signature
        // filter the row set is already the class the caller asked about.
        $trusted = $explicitSignature ? '1 = 1' : 'signature_valid = 1';

        $selectGroup = [];
        foreach ($groupColumns as $column) {
            $selectGroup[] = $column['expr'] . ' AS ' . $column['alias'];
        }
        $groupByExpr = implode(', ', array_column($groupColumns, 'expr'));
        $firstAlias = $groupColumns[0]['alias'];

        $winCondition = '(did_win IS NULL OR did_win = 1)';
        $firstWindow = 'COALESCE(postback_sequence_index, 0) = 0';
        $identity = self::IDENTITY;
        $development = SignatureState::DEVELOPMENT->value;
        // conversion_type is the protocol-neutral reading of SKAdNetwork's
        // redownload flag and AdAttributionKit's conversion-type: a
        // re-engagement is neither an install nor a redownload.

        // Day groups keep the NEWEST window (DESC + limit, re-sorted
        // ascending for output); other modes keep the busiest groups.
        $orderBy = $groupBy === 'day' ? "$firstAlias DESC" : 'postbacks DESC';

        // One statement shape, run against whichever filter set wins above.
        // $binds is taken by value, so an attempt never leaves its LIMIT
        // bind behind for the next one.
        $runGroups = function (array $where, array $binds, string $types) use (
            $selectGroup,
            $groupByExpr,
            $orderBy,
            $groupColumns,
            $maxGroups,
            $trusted,
            $identity,
            $winCondition,
            $firstWindow,
            $development
        ): array {
            $whereClause = 'WHERE ' . implode(' AND ', $where);
            $sql = 'SELECT ' . implode(', ', $selectGroup) . ",
                COUNT(DISTINCT $identity) AS postbacks,
                COUNT(DISTINCT CASE WHEN $trusted AND did_win = 0 THEN $identity END) AS losses,
                COUNT(DISTINCT CASE WHEN $trusted AND $winCondition AND $firstWindow AND conversion_type = 'download' THEN $identity END) AS installs,
                COUNT(DISTINCT CASE WHEN $trusted AND $winCondition AND $firstWindow AND conversion_type = 'redownload' THEN $identity END) AS redownloads,
                COUNT(DISTINCT CASE WHEN $trusted AND $winCondition AND $firstWindow AND conversion_type = 're-engagement' THEN $identity END) AS reengagements,
                COUNT(DISTINCT CASE WHEN signature_valid = 1 THEN $identity END) AS signature_valid_count,
                COUNT(DISTINCT CASE WHEN signature_valid = 0 THEN $identity END) AS signature_invalid_count,
                COUNT(DISTINCT CASE WHEN signature_valid IS NULL THEN $identity END) AS signature_unverified_count,
                COUNT(DISTINCT CASE WHEN signature_state = '$development' THEN $identity END) AS signature_development_count
            FROM 202_attribution_postbacks
            $whereClause
            GROUP BY $groupByExpr
            ORDER BY $orderBy
            LIMIT ?";

            $stmt = $this->prepare($sql);
            $binds[] = $maxGroups + 1; // one extra row detects truncation
            $this->bind($stmt, $types . 'i', ...$binds);
            $this->execute($stmt, 'Report query failed');
            $result = $this->result($stmt);

            $groups = [];
            while ($row = $result->fetch_assoc()) {
                $key = $this->groupKey($groupColumns, $row);
                $groups[$key] = $this->initGroupRow($groupColumns, $row);
            }
            $stmt->close();
            return $groups;
        };

        $groupsOut = $runGroups($where, $binds, $types);
        if ($boundedTimeFrom !== null && count($groupsOut) < $maxGroups + 1) {
            // Short of a full page: older populated days may exist outside
            // the bound, so the bounded attempt cannot stand. Re-run with
            // the caller's own filters and let that answer win — including
            // the $where/$binds/$types the decode query below reuses. The
            // signature filter is the same in both, so $trusted (already
            // captured above) does not change with it.
            [$where, $binds, $types] = $this->buildFilters($params);
            $groupsOut = $runGroups($where, $binds, $types);
        }

        $truncated = count($groupsOut) > $maxGroups;
        if ($truncated) {
            $groupsOut = array_slice($groupsOut, 0, $maxGroups, preserve_keys: true);
        }

        // Conversion-value decode: distribution of values per group, folded
        // through the user's rules in PHP (rule resolution — app-specific
        // over default — is not expressible as a sane single JOIN). The row
        // set is bounded to the retained group window so the query never
        // aggregates combinations the fold would discard.
        //
        // Each unique postback decodes ONCE, through its first-received
        // copy: a replay of a signed postback carrying a different (unsigned)
        // conversion value is its own row in the store, and counting it in
        // a second value bucket would let whoever holds a genuine postback
        // mint revenue by resending it with new values. Apple's rule is to
        // discard the later duplicates, which MIN(postback_id) per identity
        // is (postback_id is receipt order).
        $cvWhere = $where;
        $cvBinds = $binds;
        $cvTypes = $types;
        $this->restrictToRetainedGroups($groupBy, $groupsOut, $cvWhere, $cvBinds, $cvTypes);
        $cvWhereClause = 'WHERE ' . implode(' AND ', $cvWhere);

        $cvSql = 'SELECT ' . implode(', ', $selectGroup) . ",
                app_id AS cv_app_id, conversion_value, coarse_conversion_value, COUNT(*) AS cnt
            FROM 202_attribution_postbacks
            $cvWhereClause AND $trusted AND $winCondition
            AND postback_id IN (
                SELECT MIN(postback_id) FROM 202_attribution_postbacks
                $cvWhereClause AND $trusted AND $winCondition
                GROUP BY $identity
            )
            GROUP BY $groupByExpr, app_id, conversion_value, coarse_conversion_value";
        $stmt = $this->prepare($cvSql);
        // The where clause appears twice (outer query and the first-copy
        // subquery), so its binds do too.
        $this->bind($stmt, $cvTypes . $cvTypes, ...$cvBinds, ...$cvBinds);
        $this->execute($stmt, 'Report decode query failed');
        $result = $this->result($stmt);

        $rules = $this->conversionRules();
        while ($row = $result->fetch_assoc()) {
            $key = $this->groupKey($groupColumns, $row);
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
                    . '; every metric counts unique postbacks (protocol, ad network, postback id, window), so a replayed postback counts once and decodes through its first-received copy'
                    . '; the signature_*_count columns count unique postbacks per signature class, so a postback stored in two classes counts in each'
                    . '; installs = winning first-window downloads; redownloads and re-engagements (AdAttributionKit) are reported separately'
                    . '; conversion values decode across all three windows, so measurable/decoded can exceed installs'
                    . '; conversion values decode through /attribution/conversion-values rules',
            ],
        ];
    }

    /**
     * Ad-hoc signature verification for a postback payload — lets an
     * operator or agent confirm end-to-end that a captured postback (or a
     * test fixture) verifies before trusting the pipeline. Nothing is
     * stored.
     *
     * The protocol is detected from the body: a `jws-string` key means an
     * AdAttributionKit postback (the JWS alone is enough — the unsigned
     * envelope fields are not needed to judge the signature); anything
     * else is verified as a SKAdNetwork postback.
     *
     * @param array<string, mixed> $payload The postback JSON, as received.
     */
    public function verify(array $payload): array
    {
        if ($payload === []) {
            throw new ValidationException('Provide the postback JSON object as the request body');
        }

        if (array_key_exists('jws-string', $payload)) {
            $jws = $payload['jws-string'];
            if (!is_string($jws) || trim($jws) === '' || strlen($jws) > AdAttributionKitProtocol::MAX_JWS_LENGTH) {
                throw new ValidationException('Invalid postback', [
                    'jws-string' => 'Must be the compact JWS string, at most ' . AdAttributionKitProtocol::MAX_JWS_LENGTH . ' characters',
                ]);
            }
            $decoded = JwsVerifier::decode($jws);
            if (is_string($decoded)) {
                throw new ValidationException('Invalid postback', ['jws-string' => $decoded]);
            }
            return [
                'data' => [
                    'protocol' => AdAttributionKitProtocol::NAME,
                    'signature' => (new JwsVerifier())->verify($decoded)->value,
                    'key_id' => $decoded['header']['kid'],
                    // The decoded parts, so a caller can read what the
                    // postback claims without a JWS tool of their own.
                    'header' => $decoded['header'],
                    'payload' => $decoded['payload'],
                    'known_key_ids' => array_keys(JwsVerifier::APPLE_KEYS),
                    'development_key_ids' => JwsVerifier::DEVELOPMENT_KEY_IDS,
                ],
            ];
        }

        $verifier = new PostbackVerifier();
        $state = $verifier->verify($payload);
        $message = $verifier->buildSignedMessage($payload);

        return [
            'data' => [
                'protocol' => SkadnetworkProtocol::NAME,
                'signature' => $state->value,
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
        $value = self::strictInt($params[$key], $key, 'Invalid paging value', 'Must be an integer');
        return max($min, min($max, $value));
    }

    /**
     * A filter's integer value, or a 422 naming the parameter.
     *
     * The same strict test boundedInt() uses, for the same reason:
     * is_numeric() accepts '1.9', '1e0' and ' 1' and the (int) cast then
     * silently turns each of them into 1 while the message promises an
     * integer — a mistyped filter answering a question the caller never
     * asked (error patterns #4 and #5).
     */
    private static function strictInt(mixed $raw, string $param, string $title, string $requirement): int
    {
        if (!is_int($raw) && !(is_string($raw) && self::isIntegerString($raw))) {
            throw new ValidationException($title, [
                $param => $requirement,
            ]);
        }
        return (int)$raw;
    }

    /**
     * A digit string the int cast reproduces exactly.
     *
     * A digit-only shape is not enough: '9223372036854775808' and
     * '99999999999999999999' saturate to PHP_INT_MAX under the cast (and a
     * negative one to PHP_INT_MIN), so the filter was bound to a number the
     * caller never typed and the empty result read as an answer about their
     * value — the same silent rewrite the digit test was added to remove.
     * AttributionAppsController::isUsableAppId() refuses the class the same
     * way for the same reason; this is its signed counterpart, so leading
     * zeros ('007' is 7) stay acceptable and only a value the cast MOVES is
     * rejected.
     */
    private static function isIntegerString(string $raw): bool
    {
        if (preg_match('/^-?\d+$/D', $raw) !== 1) {
            return false;
        }
        $negative = $raw[0] === '-';
        $digits = ltrim($negative ? substr($raw, 1) : $raw, '0');
        if ($digits === '') {
            return true; // '0', '-0', '000'
        }
        $canonical = ($negative ? '-' : '') . $digits;
        return $canonical === (string)(int)$canonical;
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
                $where[] = $condition;
                $binds[] = self::strictInt($params[$param], $param, 'Invalid time filter', 'Must be a unix timestamp');
                $types .= 'i';
            }
        }
        foreach (['app_id', 'campaign_id', 'postback_sequence_index'] as $param) {
            if (isset($params[$param]) && $params[$param] !== '') {
                $where[] = "$param = ?";
                $binds[] = self::strictInt($params[$param], $param, 'Invalid filter value', 'Must be an integer');
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
        foreach (['did_win'] as $flag) {
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

        // redownload and fidelity_type are the LEGACY (SKAdNetwork)
        // spellings of the protocol-neutral dimensions conversion_type and
        // ad_interaction_type. Only SkadnetworkProtocol writes the raw
        // columns; AdAttributionKit rows leave them NULL and carry the
        // generic pair alone, so filtering on the raw columns silently
        // dropped every AdAttributionKit row from what reads as the same
        // question. Both names are kept for compatibility and translated
        // onto the generic columns; their validation and messages are
        // unchanged (redownload a boolean, fidelity_type an integer).
        if (isset($params['redownload']) && $params['redownload'] !== '') {
            $bool = filter_var($params['redownload'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === null) {
                throw new ValidationException('Invalid filter value', [
                    'redownload' => 'Must be a boolean (1/0/true/false)',
                ]);
            }
            $where[] = 'conversion_type = ?';
            $binds[] = $bool ? 'redownload' : 'download';
            $types .= 's';
        }
        if (isset($params['fidelity_type']) && $params['fidelity_type'] !== '') {
            $fidelity = self::strictInt($params['fidelity_type'], 'fidelity_type', 'Invalid filter value', 'Must be an integer');
            // The receiver stores only 0 (view-through) and 1 (click), so
            // any other integer selected no row before the translation and
            // must go on selecting none.
            $interaction = match ($fidelity) {
                0 => 'view',
                1 => 'click',
                default => null,
            };
            if ($interaction === null) {
                $where[] = '1 = 0';
            } else {
                $where[] = 'ad_interaction_type = ?';
                $binds[] = $interaction;
                $types .= 's';
            }
        }

        foreach ([
            'protocol' => Protocols::NAMES,
            'conversion_type' => AdAttributionKitProtocol::CONVERSION_TYPES,
            'ad_interaction_type' => AdAttributionKitProtocol::INTERACTION_TYPES,
        ] as $param => $allowed) {
            if (isset($params[$param]) && $params[$param] !== '') {
                $value = strtolower(trim((string)$params[$param]));
                if ($param === 'protocol') {
                    $value = Protocols::normalize($value) ?? $value;
                }
                if (!in_array($value, $allowed, true)) {
                    throw new ValidationException('Invalid filter value', [
                        $param => 'Must be one of: ' . implode(', ', $allowed)
                            . ($param === 'protocol' ? ' (or ' . implode(', ', array_keys(Protocols::ALIASES)) . ')' : ''),
                    ]);
                }
                $where[] = "$param = ?";
                $binds[] = $value;
                $types .= 's';
            }
        }

        // valid/invalid/unverifiable select on the trust bit — the value the
        // report keys on, so "valid" is exactly what the default report
        // counts (including development rows the app opted in to);
        // "development" selects on the verifier's verdict regardless of
        // trust, for an integration tester looking for their own postbacks.
        $explicitSignature = false;
        if (isset($params['signature']) && $params['signature'] !== '') {
            $explicitSignature = true;
            $signature = SignatureState::tryFrom(strtolower(trim((string)$params['signature'])));
            if ($signature === null) {
                throw new ValidationException('Invalid signature filter', [
                    'signature' => 'Must be one of: ' . implode(', ', SignatureState::values()),
                ]);
            }
            $where[] = match ($signature) {
                SignatureState::VALID => 'signature_valid = 1',
                SignatureState::INVALID => 'signature_valid = 0',
                SignatureState::UNVERIFIABLE => 'signature_valid IS NULL',
                SignatureState::DEVELOPMENT => "signature_state = '" . SignatureState::DEVELOPMENT->value . "'",
            };
        }

        return [$where, $binds, $types, $explicitSignature];
    }

    /**
     * Bound the decode query to the groups the report retained, so it never
     * aggregates and ships combinations the fold would discard. Derived
     * from GROUP_MODES, not from a second list of modes: day mode bounds by
     * the retained time window; every other single-column mode binds an IN
     * list of the retained keys (a NULL key — country, version and
     * conversion_type are nullable — becomes an IS NULL branch), with the
     * bind type taken from the column's kind. Multi-column modes (source,
     * two nullable columns) keep the PHP-side discard — their cardinality
     * is bounded by the ad networks' own 4-digit identifier space.
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

        $groupColumns = self::GROUP_MODES[$groupBy];
        if (count($groupColumns) !== 1) {
            return; // multi-column mode: bounded in PHP by the fold
        }
        $column = $groupColumns[0];

        if ($column['kind'] === 'day') {
            $days = array_map(static fn(string $key): int => (int)$key, array_keys($groupsOut));
            $where[] = 'received_at >= ?';
            $binds[] = min($days);
            $types .= 'i';
            $where[] = 'received_at < ?';
            $binds[] = max($days) + 86400;
            $types .= 'i';
            return;
        }

        // No default arm: a kind with no bind type here would otherwise
        // leave the decode query unbounded, silently.
        $bindType = match ($column['kind']) {
            'int', 'nullable-int' => 'i',
            'string', 'nullable-string' => 's',
        };
        $expr = $column['expr'];
        $values = [];
        $hasNull = false;
        foreach (array_keys($groupsOut) as $key) {
            if ($key === '' && $column['kind'] !== 'int') {
                $hasNull = true; // NULL group key
            } else {
                $values[] = $bindType === 'i' ? (int)$key : (string)$key;
            }
        }
        $parts = [];
        if ($values !== []) {
            $parts[] = "$expr IN (" . implode(', ', array_fill(0, count($values), '?')) . ')';
            foreach ($values as $value) {
                $binds[] = $value;
                $types .= $bindType;
            }
        }
        if ($hasNull) {
            $parts[] = "$expr IS NULL";
        }
        // A lone integer IN list needs no parentheses; anything that can
        // grow an OR branch is wrapped so the AND join stays correct.
        $where[] = count($parts) === 1 && $bindType === 'i'
            ? $parts[0]
            : '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * @param list<array{alias: string, expr: string, key: string, kind: string}> $groupColumns
     * @param array<string, mixed> $row
     */
    private function groupKey(array $groupColumns, array $row): string
    {
        $parts = [];
        foreach ($groupColumns as $column) {
            $alias = $column['alias'];
            $parts[] = $row[$alias] === null ? '' : (string)$row[$alias];
        }
        return implode('|', $parts);
    }

    /**
     * The group's own key columns, rendered by kind, plus the metric
     * columns. No default arm on the match: a kind with no renderer is a
     * programming error, not a group row that silently lacks its key.
     *
     * @param list<array{alias: string, expr: string, key: string, kind: string}> $groupColumns
     * @return array<string, mixed>
     */
    private function initGroupRow(array $groupColumns, array $row): array
    {
        $group = [];
        foreach ($groupColumns as $column) {
            $value = $row[$column['alias']] ?? null;
            $group[$column['key']] = match ($column['kind']) {
                'day' => gmdate('Y-m-d', (int)$value),
                'int' => (int)$value,
                'nullable-int' => $value === null ? null : (int)$value,
                'string' => ResponseSanitizer::cleanVisitorString((string)($value ?? '')),
                'nullable-string' => $value === null ? null : ResponseSanitizer::cleanVisitorString((string)$value),
            };
        }

        return $group + [
            'postbacks' => (int)$row['postbacks'],
            'losses' => (int)$row['losses'],
            'installs' => (int)$row['installs'],
            'redownloads' => (int)$row['redownloads'],
            'reengagements' => (int)$row['reengagements'],
            'signature_valid_count' => (int)$row['signature_valid_count'],
            'signature_invalid_count' => (int)$row['signature_invalid_count'],
            'signature_unverified_count' => (int)$row['signature_unverified_count'],
            'signature_development_count' => (int)$row['signature_development_count'],
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
        $sql = 'SELECT app_id, fine_value, coarse_value, event_name, revenue FROM 202_attribution_conversion_values WHERE user_id = ?';
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
        $sql = 'SELECT app_id, app_name FROM 202_attribution_apps WHERE user_id = ?';
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
