<?php

declare(strict_types=1);

namespace Api\V3\Attribution;

/**
 * Accepts SKAdNetwork install-validation postbacks.
 *
 * Devices POST these directly (not Apple's servers) to
 * /.well-known/skadnetwork/report-attribution/ when the advertised app names
 * this Prosper202 install as its NSAdvertisingAttributionReportEndpoint, or
 * when this install is registered as an ad network's postback endpoint. The
 * receiver validates the payload strictly, verifies Apple's signature,
 * resolves the owning user through the 202_attribution_apps registry, and stores one
 * row per postback in 202_attribution_postbacks.
 *
 * Response contract (what the HTTP entry point sends):
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
 * No authentication: devices cannot present credentials. The attribution
 * signature is the trust boundary, which is why signature_valid is stored on
 * every row and surfaced through the reporting API.
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
     * unbounded. Only a row that VERIFIED against Apple's key and belongs to
     * a registered app is operator data kept forever; every other class is
     * something an unauthenticated poster can mint, so each has a window:
     *
     *  - unclaimed    no app registration adopted it (user_id = 0)
     *  - invalid      the signature was checked and is forged
     *  - unverifiable the signature could not be checked at all
     *
     * The third class is not a formality: naming a version outside
     * PostbackVerifier::VERIFIABLE_VERSIONS stores signature_valid = NULL,
     * and if the body names a registered App Store id (a public number) the
     * row is claimed too — so without this class it matched neither of the
     * others and lived forever, unauthenticated and free to repeat.
     *
     * Overridable per class via P202_ATTRIBUTION_RETENTION_DAYS_UNCLAIMED /
     * _INVALID / _UNVERIFIABLE; 0 disables that class of pruning.
     */
    public const DEFAULT_RETENTION_DAYS_UNCLAIMED = 30;
    public const DEFAULT_RETENTION_DAYS_INVALID = 90;
    public const DEFAULT_RETENTION_DAYS_UNVERIFIABLE = 90;

    private const COARSE_VALUES = ['low', 'medium', 'high'];

    public function __construct(
        private readonly \mysqli $db,
        private readonly PostbackVerifier $verifier,
    ) {
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

        $fieldErrors = $this->validate($postback);
        if ($fieldErrors !== []) {
            return $this->error(400, 'Invalid postback', $fieldErrors);
        }

        $signatureState = $this->verifier->verify($postback);
        $signatureValid = match ($signatureState) {
            PostbackVerifier::RESULT_VALID => 1,
            PostbackVerifier::RESULT_INVALID => 0,
            default => null,
        };

        $appId = (int)$postback['app-id'];
        try {
            $userId = $this->resolveUserId($appId);
        } catch (\Throwable $e) {
            // A non-200 makes the device retry later, when the database may
            // be back — the postback is not lost.
            error_log('p202 attribution: app registry lookup failed: ' . $e->getMessage());
            return $this->error(500, 'Failed to store postback');
        }

        $sequenceIndex = array_key_exists('postback-sequence-index', $postback)
            ? (int)$postback['postback-sequence-index'] : null;
        $didWin = array_key_exists('did-win', $postback)
            ? (bool)$postback['did-win'] : null;

        $dedupeHash = self::dedupeHash(
            (string)$postback['ad-network-id'],
            (string)$postback['transaction-id'],
            $sequenceIndex,
            $didWin,
            $rawBody
        );

        // Aligned (type, value) pairs so the bind string cannot drift from
        // the value list (error pattern #7).
        $columns = [
            'user_id'                 => ['i', $userId],
            'received_at'             => ['i', $receivedAt],
            'version'                 => ['s', (string)$postback['version']],
            'ad_network_id'           => ['s', (string)$postback['ad-network-id']],
            'transaction_id'          => ['s', (string)$postback['transaction-id']],
            'app_id'                  => ['i', $appId],
            'source_identifier'       => ['s', self::optString($postback, 'source-identifier')],
            'campaign_id'             => ['i', self::optInt($postback, 'campaign-id')],
            'conversion_value'        => ['i', self::optInt($postback, 'conversion-value')],
            'coarse_conversion_value' => ['s', self::optString($postback, 'coarse-conversion-value')],
            'postback_sequence_index' => ['i', $sequenceIndex],
            'redownload'              => ['i', array_key_exists('redownload', $postback) ? (int)(bool)$postback['redownload'] : null],
            'did_win'                 => ['i', $didWin === null ? null : (int)$didWin],
            'source_app_id'           => ['i', self::optInt($postback, 'source-app-id')],
            'source_domain'           => ['s', self::optString($postback, 'source-domain')],
            'fidelity_type'           => ['i', self::optInt($postback, 'fidelity-type')],
            'country_code'            => ['s', self::optString($postback, 'country-code')],
            'attribution_signature'   => ['s', (string)$postback['attribution-signature']],
            'signature_valid'         => ['i', $signatureValid],
            'dedupe_hash'             => ['s', $dedupeHash],
            'raw_payload'             => ['s', $rawBody],
            'remote_ip'               => ['s', substr($remoteIp, 0, 45)],
            'created_at'              => ['i', $receivedAt],
        ];

        $insert = $this->insertPostback($columns);
        if ($insert === 'error') {
            return $this->error(500, 'Failed to store postback');
        }

        if ($insert === 'stored' && mt_rand(1, 100) === 1) {
            // Opportunistic retention: the endpoint is open, so aged
            // unclaimed/forged rows must not accumulate forever. Never
            // fatal — the accepted postback outcome stands regardless.
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
                    'signature' => $signatureState,
                ],
            ],
        ];
    }

    /**
     * The identity of a postback for retry deduplication. Apple documents
     * transaction-id as the dedupe value and device retries are
     * byte-identical, so the raw body is part of the identity: without it,
     * a forged postback carrying a real (ad-network-id, transaction-id,
     * window) tuple with different content would occupy the slot first and
     * the later genuine signed postback would be dropped as its
     * "duplicate". With the body folded in, only true retries collide;
     * mutated forgeries store as their own rows, and reporting separates
     * them by signature state.
     */
    public static function dedupeHash(string $adNetworkId, string $transactionId, ?int $sequenceIndex, ?bool $didWin, string $rawBody): string
    {
        // Length-prefixed serialization: with a plain joining character, an
        // ad-network-id containing that character could collide with a
        // different (network, transaction) pair. The prefixes pin the field
        // boundaries whatever the strings contain.
        return sha1(sprintf(
            '%d:%s|%d:%s|%s|%s|%s',
            strlen($adNetworkId),
            $adNetworkId,
            strlen($transactionId),
            $transactionId,
            $sequenceIndex === null ? '-' : (string)$sequenceIndex,
            $didWin === null ? '-' : ($didWin ? 'w' : 'l'),
            sha1($rawBody)
        ));
    }

    /**
     * Bounded retention pass: delete aged rows nobody will ever act on —
     * unclaimed postbacks (no app registration adopted them) and rows whose
     * signature verified as forged. Verified rows belonging to a user are
     * never touched. LIMITed so a pass stays cheap on the request path.
     */
    public function prunePostbacks(int $now): void
    {
        $classes = [
            ['P202_ATTRIBUTION_RETENTION_DAYS_UNCLAIMED', self::DEFAULT_RETENTION_DAYS_UNCLAIMED,
                'DELETE FROM 202_attribution_postbacks WHERE user_id = 0 AND received_at < ? LIMIT 500'],
            ['P202_ATTRIBUTION_RETENTION_DAYS_INVALID', self::DEFAULT_RETENTION_DAYS_INVALID,
                'DELETE FROM 202_attribution_postbacks WHERE signature_valid = 0 AND received_at < ? LIMIT 500'],
            ['P202_ATTRIBUTION_RETENTION_DAYS_UNVERIFIABLE', self::DEFAULT_RETENTION_DAYS_UNVERIFIABLE,
                'DELETE FROM 202_attribution_postbacks WHERE signature_valid IS NULL AND received_at < ? LIMIT 500'],
        ];
        foreach ($classes as [$envName, $defaultDays, $sql]) {
            $days = self::retentionDays($envName, $defaultDays);
            if ($days <= 0) {
                continue; // disabled, or a value we refused to guess at
            }
            $cutoff = $now - ($days * 86400);
            $stmt = $this->prepare($sql);
            $this->bind($stmt, 'i', $cutoff);
            $this->execute($stmt, 'Retention delete failed');
            $stmt->close();
        }
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
            error_log(sprintf(
                'p202 attribution: ignoring malformed %s (%s); expected a whole number of days, 0 to disable. Nothing pruned for this class.',
                $envName,
                $raw
            ));
            return 0;
        }
        return (int)$raw;
    }

    /**
     * Strict structural validation. Anything a genuine device would never
     * send is named here and rejected with a 400 — not stored half-parsed.
     *
     * @param array<string, mixed> $postback
     * @return array<string, string> field => problem (empty when valid)
     */
    private function validate(array $postback): array
    {
        $errors = [];

        $version = $postback['version'] ?? null;
        if (!is_string($version) || preg_match('/^\d{1,2}\.\d{1,2}$/D', $version) !== 1) {
            $errors['version'] = 'Required: a version string such as "4.0"';
        }

        $adNetworkId = $postback['ad-network-id'] ?? null;
        if (!is_string($adNetworkId) || trim($adNetworkId) === '' || strlen($adNetworkId) > 100) {
            $errors['ad-network-id'] = 'Required: a non-empty string of at most 100 characters';
        }

        $transactionId = $postback['transaction-id'] ?? null;
        if (!is_string($transactionId) || trim($transactionId) === '' || strlen($transactionId) > 64) {
            $errors['transaction-id'] = 'Required: a non-empty string of at most 64 characters';
        }

        if (!is_int($postback['app-id'] ?? null) || $postback['app-id'] < 0) {
            $errors['app-id'] = 'Required: a non-negative integer App Store id';
        }

        $signature = $postback['attribution-signature'] ?? null;
        if (!is_string($signature) || trim($signature) === '' || strlen($signature) > 4096) {
            $errors['attribution-signature'] = 'Required: a non-empty base64 string';
        }

        if (array_key_exists('source-identifier', $postback)) {
            $sourceIdentifier = $postback['source-identifier'];
            if (!is_string($sourceIdentifier) || preg_match('/^\d{1,4}$/D', $sourceIdentifier) !== 1) {
                $errors['source-identifier'] = 'Must be a string of 1-4 digits';
            }
        }
        if (array_key_exists('campaign-id', $postback) && (!is_int($postback['campaign-id']) || $postback['campaign-id'] < 0)) {
            $errors['campaign-id'] = 'Must be a non-negative integer';
        }
        if (array_key_exists('conversion-value', $postback)) {
            $conversionValue = $postback['conversion-value'];
            if (!is_int($conversionValue) || $conversionValue < 0 || $conversionValue > 63) {
                $errors['conversion-value'] = 'Must be an integer from 0 to 63';
            }
        }
        if (
            array_key_exists('coarse-conversion-value', $postback)
            && !in_array($postback['coarse-conversion-value'], self::COARSE_VALUES, true)
        ) {
            $errors['coarse-conversion-value'] = 'Must be one of: low, medium, high';
        }
        if (array_key_exists('postback-sequence-index', $postback)) {
            $sequenceIndex = $postback['postback-sequence-index'];
            if (!is_int($sequenceIndex) || $sequenceIndex < 0 || $sequenceIndex > 2) {
                $errors['postback-sequence-index'] = 'Must be an integer from 0 to 2';
            }
        }
        if (array_key_exists('redownload', $postback) && !is_bool($postback['redownload'])) {
            $errors['redownload'] = 'Must be a boolean';
        }
        if (array_key_exists('did-win', $postback) && !is_bool($postback['did-win'])) {
            $errors['did-win'] = 'Must be a boolean';
        }
        if (array_key_exists('source-app-id', $postback) && (!is_int($postback['source-app-id']) || $postback['source-app-id'] < 0)) {
            $errors['source-app-id'] = 'Must be a non-negative integer App Store id';
        }
        if (array_key_exists('source-domain', $postback)) {
            $sourceDomain = $postback['source-domain'];
            if (!is_string($sourceDomain) || trim($sourceDomain) === '' || strlen($sourceDomain) > 255) {
                $errors['source-domain'] = 'Must be a non-empty string of at most 255 characters';
            }
        }
        if (array_key_exists('fidelity-type', $postback)) {
            $fidelityType = $postback['fidelity-type'];
            if (!is_int($fidelityType) || $fidelityType < 0 || $fidelityType > 1) {
                $errors['fidelity-type'] = 'Must be 0 (view-through) or 1 (StoreKit-rendered or web ad)';
            }
        }
        if (array_key_exists('country-code', $postback)) {
            $countryCode = $postback['country-code'];
            if (!is_string($countryCode) || strlen($countryCode) > 8 || trim($countryCode) === '') {
                $errors['country-code'] = 'Must be a short country identifier string';
            }
        }

        return $errors;
    }

    /**
     * Which user's reporting this postback belongs to: the owner of the
     * advertised app's registration, or 0 (unclaimed) when the app is not
     * registered. Registering the app later claims unclaimed history — see
     * AttributionAppsController::afterCreate().
     */
    private function resolveUserId(int $appId): int
    {
        $stmt = $this->prepare('SELECT user_id FROM 202_attribution_apps WHERE app_id = ? LIMIT 1');
        $this->bind($stmt, 'i', $appId);
        $this->execute($stmt, 'SKAN app lookup failed');
        $row = $this->result($stmt)->fetch_assoc();
        $stmt->close();
        return is_array($row) ? (int)$row['user_id'] : 0;
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

    /** @param array<string, mixed> $postback */
    private static function optString(array $postback, string $key): ?string
    {
        return array_key_exists($key, $postback) ? (string)$postback[$key] : null;
    }

    /** @param array<string, mixed> $postback */
    private static function optInt(array $postback, string $key): ?int
    {
        return array_key_exists($key, $postback) ? (int)$postback[$key] : null;
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
