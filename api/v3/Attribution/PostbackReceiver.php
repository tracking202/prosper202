<?php

declare(strict_types=1);

namespace Api\V3\Attribution;

/**
 * Accepts platform-signed attribution postbacks — SKAdNetwork today,
 * AdAttributionKit beside it — and stores one row per postback in
 * 202_attribution_postbacks.
 *
 * Devices POST these directly (not Apple's servers) to the protocol's
 * well-known URL when the advertised app names this Prosper202 install as
 * its attribution endpoint, or when this install is registered as an ad
 * network's postback endpoint. The protocol (PostbackProtocol) validates
 * the body strictly, verifies the platform's signature and normalizes the
 * fields; the receiver does everything the protocols share: it resolves the
 * owning user through the 202_attribution_apps registry, decides what the
 * signature verdict is worth (SignatureState::trustBit()), dedupes retries,
 * stores the row, and prunes what the open endpoint lets strangers mint.
 *
 * Response contract (what PostbackEndpoint sends):
 *  - 200 once the postback is stored — including replays of one already
 *    stored (the device retries up to nine times when it does not get a 200,
 *    so a duplicate must not look like a failure) and postbacks whose
 *    signature does not verify (stored flagged; retrying cannot fix a bad
 *    signature, and reports separate verified from unverified).
 *  - 400 when the body is not a postback at all (bad JSON, missing or
 *    mis-typed fields). Genuine devices never send these.
 *  - 413 when the body exceeds MAX_BODY_BYTES. Also terminal: no genuine
 *    postback is anywhere near that size, and the same body would fail
 *    every retry, so there is nothing for the device to come back for.
 *  - 429/500 for rate limiting and storage failures — non-200, so the device
 *    retries later.
 *
 * No authentication: devices cannot present credentials. The platform's
 * signature is the trust boundary, which is why signature_state and the
 * signature_valid trust bit are stored on every row and surfaced through
 * the reporting API.
 */
final class PostbackReceiver
{
    use \Api\V3\Support\MysqliStatements;

    /**
     * Largest body accepted; real postbacks are under 2 KB. Kept well below
     * the raw_payload column's TEXT capacity (65535 bytes) so an accepted
     * body can never fail the INSERT on size and turn into a retry loop.
     */
    public const MAX_BODY_BYTES = 32768;

    /**
     * Retention defaults (days) for rows the endpoint's openness makes
     * unbounded. Only a row that VERIFIED against the platform's production
     * key and belongs to a registered app is operator data kept forever;
     * every other class is something an unauthenticated poster can mint, so
     * each has a window:
     *
     *  - unclaimed    no app registration adopted it (user_id = 0)
     *  - invalid      the signature was checked and is forged
     *  - unverifiable the signature could not be checked at all, or was
     *                 made with a development key the app did not opt into
     *
     * The third class is not a formality: naming a version outside
     * PostbackVerifier::VERIFIABLE_VERSIONS stores signature_valid = NULL,
     * and if the body names a registered App Store id (a public number) the
     * row is claimed too — so without this class it matched neither of the
     * others and lived forever, unauthenticated and free to repeat. A
     * development-signed AdAttributionKit postback is the same shape: any
     * developer with a phone in Developer Mode can mint one naming any app.
     *
     * Overridable per class via P202_ATTRIBUTION_RETENTION_DAYS_UNCLAIMED /
     * _INVALID / _UNVERIFIABLE; 0 disables that class of pruning. The
     * overrides are read from the environment of whichever process prunes,
     * so an operator setting them for the cron
     * (202-cronjobs/attribution-retention.php) and an operator setting them
     * for php-fpm are configuring two different pruners — the cron prints
     * the windows it resolved for exactly that reason.
     */
    public const DEFAULT_RETENTION_DAYS_UNCLAIMED = 30;
    public const DEFAULT_RETENTION_DAYS_INVALID = 90;
    public const DEFAULT_RETENTION_DAYS_UNVERIFIABLE = 90;

    /**
     * Rows one pass deletes per class. Bounded so a pass stays cheap on the
     * request path; the cron loops passes until the backlog drains.
     */
    public const PRUNE_BATCH_LIMIT = 500;

    /**
     * The prune classes in one place: label => [environment override,
     * default window in days, the predicate that selects the class]. The
     * pruner, the resolved-policy report and the backlog count all read
     * this — a second copy of "which rows are prunable" is how a retention
     * policy and the numbers an operator is shown drift apart.
     */
    private const PRUNE_CLASSES = [
        'unclaimed' => [
            'P202_ATTRIBUTION_RETENTION_DAYS_UNCLAIMED',
            self::DEFAULT_RETENTION_DAYS_UNCLAIMED,
            'user_id = 0',
        ],
        'invalid' => [
            'P202_ATTRIBUTION_RETENTION_DAYS_INVALID',
            self::DEFAULT_RETENTION_DAYS_INVALID,
            'signature_valid = 0',
        ],
        'unverifiable' => [
            'P202_ATTRIBUTION_RETENTION_DAYS_UNVERIFIABLE',
            self::DEFAULT_RETENTION_DAYS_UNVERIFIABLE,
            'signature_valid IS NULL',
        ],
    ];

    /**
     * The columns the receiver writes for every protocol. A protocol's
     * ParsedPostback::$columns may not name any of these: the identity,
     * ownership and trust columns have one author so that no protocol can
     * (deliberately or by a typo) overwrite the trust bit or the owner.
     */
    public const GENERIC_COLUMNS = [
        'user_id', 'received_at', 'protocol', 'ad_network_id', 'transaction_id',
        'app_id', 'postback_sequence_index', 'did_win', 'signature_state',
        'signature_valid', 'key_id', 'dedupe_hash', 'raw_payload', 'remote_ip',
        'created_at',
    ];

    public function __construct(
        private readonly \mysqli $db,
        private readonly PostbackProtocol $protocol,
    ) {
    }

    /**
     * A receiver for maintenance work only — the retention cron, which
     * prunes without receiving anything. Pruning is protocol-blind: every
     * protocol's rows live in one table and the retention classes are about
     * ownership and trust, not about which platform sent the row. The
     * protocol here is therefore arbitrary and unused; do not call
     * receive() on a receiver built this way.
     */
    public static function forMaintenance(\mysqli $db): self
    {
        return new self($db, new SkadnetworkProtocol());
    }

    /**
     * Process one postback request body.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function receive(string $rawBody, string $remoteIp, ?int $receivedAt = null): array
    {
        $receivedAt ??= time();

        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $this->error(413, 'Request body too large');
        }
        if (trim($rawBody) === '') {
            return $this->error(400, 'Empty request body');
        }

        $postback = json_decode($rawBody, true);
        if (!is_array($postback)) {
            // Error pattern #4: malformed input is rejected, never coerced.
            return $this->error(400, 'Body is not a JSON object');
        }

        $parsed = $this->protocol->parse($postback);
        if (is_array($parsed)) {
            return $this->error(400, 'Invalid postback', $parsed);
        }

        try {
            [$userId, $acceptDevelopment] = $this->resolveOwner($parsed->appId);
        } catch (\Throwable $e) {
            // A non-200 makes the device retry later, when the database may
            // be back — the postback is not lost.
            error_log('p202 attribution: app registry lookup failed: ' . $e->getMessage());
            return $this->error(500, 'Failed to store postback');
        }

        $dedupeHash = self::dedupeHash(
            $this->protocol->name(),
            $parsed->adNetworkId,
            $parsed->postbackId,
            $parsed->sequenceIndex,
            $parsed->didWin,
            $rawBody
        );

        // Aligned (type, value) pairs so the bind string cannot drift from
        // the value list (error pattern #7). Keys follow GENERIC_COLUMNS.
        $columns = [
            'user_id'                 => ['i', $userId],
            'received_at'             => ['i', $receivedAt],
            'protocol'                => ['s', $this->protocol->name()],
            'ad_network_id'           => ['s', $parsed->adNetworkId],
            'transaction_id'          => ['s', $parsed->postbackId],
            'app_id'                  => ['i', $parsed->appId],
            'postback_sequence_index' => ['i', $parsed->sequenceIndex],
            'did_win'                 => ['i', $parsed->didWin === null ? null : (int)$parsed->didWin],
            'signature_state'         => ['s', $parsed->signatureState->value],
            'signature_valid'         => ['i', $parsed->signatureState->trustBit($acceptDevelopment)],
            'key_id'                  => ['s', $parsed->keyId],
            'dedupe_hash'             => ['s', $dedupeHash],
            'raw_payload'             => ['s', $rawBody],
            'remote_ip'               => ['s', substr($remoteIp, 0, 45)],
            'created_at'              => ['i', $receivedAt],
        ];

        $reserved = array_intersect_key($parsed->columns, $columns);
        if ($reserved !== []) {
            throw new \LogicException(sprintf(
                'Protocol %s tried to write receiver-owned column(s): %s',
                $this->protocol->name(),
                implode(', ', array_keys($reserved))
            ));
        }
        $columns += $parsed->columns;

        $insert = $this->insertPostback($columns);
        if ($insert === 'error') {
            return $this->error(500, 'Failed to store postback');
        }

        if ($insert === 'stored' && mt_rand(1, 100) === 1) {
            // Opportunistic retention, a safety net rather than the policy:
            // it only ever runs while postbacks are still arriving, in the
            // web SAPI's environment, and one bounded pass at a time.
            // 202-cronjobs/attribution-retention.php is the documented
            // pruner — deterministic, logged, and drained to completion.
            // Never fatal here: the accepted postback outcome stands
            // regardless.
            try {
                $this->prunePostbacks($receivedAt);
            } catch (\Throwable $e) {
                error_log('p202 attribution: postback retention pruning failed: ' . $e->getMessage());
            }
        }

        return [
            'status' => 200,
            'body' => [
                'data' => [
                    'accepted' => true,
                    'duplicate' => $insert === 'duplicate',
                    'signature' => $parsed->signatureState->value,
                ],
            ],
        ];
    }

    /**
     * The identity of a postback for retry deduplication. Apple documents
     * the postback id (SKAdNetwork transaction-id, AdAttributionKit
     * postback-identifier) as the dedupe value and device retries are
     * byte-identical, so the raw body is part of the identity: without it,
     * a forged postback carrying a real (network, id, window) tuple with
     * different content would occupy the slot first and the later genuine
     * signed postback would be dropped as its "duplicate". With the body
     * folded in, only true retries collide; mutated forgeries store as
     * their own rows, and reporting separates them by signature state.
     * The protocol is part of the identity too: one table holds every
     * protocol's rows, and each protocol's id space is its own.
     */
    public static function dedupeHash(
        string $protocol,
        string $adNetworkId,
        string $postbackId,
        ?int $sequenceIndex,
        ?bool $didWin,
        string $rawBody
    ): string {
        // Length-prefixed serialization: with a plain joining character, an
        // ad-network-id containing that character could collide with a
        // different (network, id) pair. The prefixes pin the field
        // boundaries whatever the strings contain.
        return sha1(sprintf(
            '%d:%s|%d:%s|%d:%s|%s|%s|%s',
            strlen($protocol),
            $protocol,
            strlen($adNetworkId),
            $adNetworkId,
            strlen($postbackId),
            $postbackId,
            $sequenceIndex === null ? '-' : (string)$sequenceIndex,
            $didWin === null ? '-' : ($didWin ? 'w' : 'l'),
            sha1($rawBody)
        ));
    }

    /**
     * Bounded retention pass: delete aged rows nobody will ever act on —
     * unclaimed postbacks (no app registration adopted them), rows whose
     * signature verified as forged, and rows nobody vouched for. Verified
     * rows belonging to a user are never touched. LIMITed so a pass stays
     * cheap on the request path; 202-cronjobs/attribution-retention.php is
     * the documented pruner and loops passes until the backlog drains.
     */
    public function prunePostbacks(int $now): void
    {
        foreach (self::retentionPolicy($now) as $label => $window) {
            if ($window['cutoff'] === null) {
                continue; // disabled, or a value we refused to guess at
            }
            $stmt = $this->prepare(
                'DELETE FROM 202_attribution_postbacks WHERE ' . self::PRUNE_CLASSES[$label][2]
                . ' AND received_at < ? LIMIT ' . self::PRUNE_BATCH_LIMIT
            );
            $this->bind($stmt, 'i', $window['cutoff']);
            $this->execute($stmt, 'Retention delete failed');
            $stmt->close();
        }
    }

    /**
     * The retention windows in force for THIS process: label =>
     * {days, cutoff}. A class that prunes nothing — 0 days, or an override
     * this process refused to guess at — reports 0 days and a null cutoff.
     *
     * Public because the windows come from the environment, which differs
     * between the web SAPI that runs the opportunistic pruner and the
     * crontab that runs the retention cron: the cron prints what it
     * resolved so an operator can see whether their override reached the
     * process that actually prunes.
     *
     * @return array<string, array{days: int, cutoff: int|null}>
     */
    public static function retentionPolicy(int $now): array
    {
        $policy = [];
        foreach (self::PRUNE_CLASSES as $label => [$envName, $defaultDays]) {
            $days = self::retentionDays($envName, $defaultDays);
            $policy[$label] = [
                'days' => $days,
                'cutoff' => $days > 0 ? $now - ($days * 86400) : null,
            ];
        }
        return $policy;
    }

    /**
     * How many rows each class would delete if it ran until it drained:
     * label => rows already past that class's window. A class that prunes
     * nothing reports 0.
     *
     * This is what the cron reports, before and after its passes. It counts
     * rather than reading affected_rows so the number is the operator's
     * question ("how much aged data is still here") rather than the
     * pruner's ("how much did this batch remove").
     *
     * @return array<string, int>
     */
    public function retentionBacklog(int $now): array
    {
        $backlog = [];
        foreach (self::retentionPolicy($now) as $label => $window) {
            if ($window['cutoff'] === null) {
                $backlog[$label] = 0;
                continue;
            }
            $stmt = $this->prepare(
                'SELECT COUNT(*) AS aged FROM 202_attribution_postbacks WHERE '
                . self::PRUNE_CLASSES[$label][2] . ' AND received_at < ?'
            );
            $this->bind($stmt, 'i', $window['cutoff']);
            $this->execute($stmt, 'Retention backlog count failed');
            $row = $this->result($stmt)->fetch_assoc();
            $stmt->close();
            if (!is_array($row) || !isset($row['aged'])) {
                // COUNT(*) always answers with exactly one row, so no row is
                // a transport failure — never "nothing to prune", which is
                // what a 0 here would tell the operator (error pattern #1).
                throw new \Api\V3\Exception\DatabaseException(
                    'Retention backlog count for class ' . $label . ' returned no row'
                );
            }
            $backlog[$label] = (int)$row['aged'];
        }
        return $backlog;
    }

    /**
     * Days to retain one prune class, or 0 to prune nothing.
     *
     * An unset variable means "use the default"; a value we cannot parse
     * does NOT. Falling back to the default here would delete rows on a
     * window the operator never chose — an operator who wrote "never"
     * meant keep (error patterns #4 and #11: a malformed value must not
     * resolve to the destructive reading). Skip the class instead, and
     * name the variable so the misconfiguration is findable.
     */
    private static function retentionDays(string $envName, int $defaultDays): int
    {
        $raw = getenv($envName);
        if ($raw === false || trim($raw) === '') {
            return $defaultDays;
        }
        $raw = trim($raw);
        if (preg_match('/^\d+$/D', $raw) !== 1) {
            // Once per process per (variable, value): the policy is resolved
            // again on every pruning pass and by every reporter, and the cron
            // makes hundreds of passes — the same misconfiguration repeated
            // that many times in a log is noise, not information. Only the
            // warning is suppressed; the value is re-parsed and re-refused
            // every time.
            static $warned = [];
            $key = $envName . '=' . $raw;
            if (!isset($warned[$key])) {
                $warned[$key] = true;
                error_log(sprintf(
                    'p202 attribution: ignoring malformed %s (%s); expected a whole number of days, 0 to disable. Nothing pruned for this class.',
                    $envName,
                    $raw
                ));
            }
            return 0;
        }
        return (int)$raw;
    }

    /**
     * Which user's reporting this postback belongs to — the owner of the
     * advertised app's registration, or 0 (unclaimed) when the app is not
     * registered — and whether that registration accepts development-signed
     * postbacks as trusted. Registering the app later claims unclaimed
     * history — see AttributionAppsController::afterCreate().
     *
     * @return array{0: int, 1: bool} [user_id, accept_development_postbacks]
     */
    private function resolveOwner(int $appId): array
    {
        $stmt = $this->prepare('SELECT user_id, accept_development_postbacks FROM 202_attribution_apps WHERE app_id = ? LIMIT 1');
        $this->bind($stmt, 'i', $appId);
        $this->execute($stmt, 'Attribution app lookup failed');
        $row = $this->result($stmt)->fetch_assoc();
        $stmt->close();
        if (!is_array($row)) {
            return [0, false];
        }
        // The opt-in is read strictly: only a stored 1 turns development
        // signatures into trusted rows. Anything else — 0, NULL, a value
        // that failed to read — is the untrusting default (error pattern
        // #11: a malformed security value never resolves permissively).
        return [(int)$row['user_id'], (int)$row['accept_development_postbacks'] === 1];
    }

    /**
     * @param array<string, array{0: string, 1: mixed}> $columns
     * @return 'stored'|'duplicate'|'error'
     */
    private function insertPostback(array $columns): string
    {
        $sql = 'INSERT INTO 202_attribution_postbacks (' . implode(', ', array_keys($columns)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $types = implode('', array_column($columns, 0));
        $values = array_column($columns, 1);

        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                error_log('p202 attribution: postback INSERT prepare failed: ' . $this->db->error);
                return 'error';
            }
            // @phpstan-ignore-next-line the receiver is its own checked bind wrapper; no Connection in scope
            if (!$stmt->bind_param($types, ...$values)) {
                $stmt->close();
                error_log('p202 attribution: postback INSERT bind failed');
                return 'error';
            }
            // @phpstan-ignore-next-line the receiver is its own checked execute wrapper; duplicate-key handled on both report modes
            if (!$stmt->execute()) {
                // STRICT-only report mode: failure returns false.
                $errno = $stmt->errno;
                $stmt->close();
                if ($errno === 1062) {
                    return 'duplicate';
                }
                error_log('p202 attribution: postback INSERT failed: errno ' . $errno);
                return 'error';
            }
            $stmt->close();
            return 'stored';
        } catch (\mysqli_sql_exception $e) {
            // Default (ERROR|STRICT) report mode: the same failures throw.
            if ((int)$e->getCode() === 1062) {
                return 'duplicate';
            }
            error_log('p202 attribution: postback INSERT failed: ' . $e->getMessage());
            return 'error';
        }
    }

    /**
     * @param array<string, string> $fieldErrors
     * @return array{status: int, body: array<string, mixed>}
     */
    private function error(int $status, string $message, array $fieldErrors = []): array
    {
        $body = ['error' => true, 'message' => $message, 'status' => $status];
        if ($fieldErrors !== []) {
            $body['field_errors'] = $fieldErrors;
        }
        return ['status' => $status, 'body' => $body];
    }
}
