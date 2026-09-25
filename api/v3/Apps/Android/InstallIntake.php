<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

use Api\V3\Apps\AppIdentity;
use Api\V3\Apps\AppRegistration;
use Api\V3\Apps\AppRegistry;
use Api\V3\Apps\AppToken;
use Api\V3\Exception\ValidationException;
use Prosper202\Database\Connection;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\MysqlGoalRepository;
use Throwable;

/**
 * POST /apps/installs: the Android intake (plan §5.2).
 *
 * The request is pre-auth (an app binary holds no API key) and selects its
 * registration by the X-P202-App-Token header. Everything the SDK claims is
 * validated before anything is written, and everything durable commits in
 * ONE transaction — the install row, its classification, the built-in
 * install goal's outcome and ledger row, every other install-triggered
 * outcome, the MTA outbox rows and the traffic-source notifications — so a
 * failure anywhere answers 500 with nothing stored and the SDK's retry does
 * the whole job again (CLAUDE.md #13, seen from the retry side). The
 * invariant the transaction buys: an attributed, trusted install row always
 * names its conversion.
 *
 * Lock order on the install paths (the intake and the pending-click
 * settler): the install row (the INSERT here, the settler's FOR UPDATE),
 * then the click the token names (FOR UPDATE, so two installs naming one
 * click serialise on the duplicate check), then the install's goal subject
 * row, then the click again inside the ledger writer (a lock this
 * transaction already holds). The engine's own paths take a subject before
 * its click; the two orders cannot meet in a cycle, because the subject an
 * install path locks is the install's own, created in this same
 * transaction, which no other request can hold: events for an install are
 * accepted only once the install has committed and settled.
 *
 * Identity is what the sender cannot choose (CLAUDE.md #16): the install
 * is keyed on (registration, install_uuid), where the registration comes
 * from the token, the uuid is 122 random bits only the device knows, and a
 * reused uuid carrying different content is refused (409) rather than
 * answered as the stored install; the click comes only from a token whose
 * MAC verifies. Answers never carry click data the referrer did not
 * already hold.
 */
final class InstallIntake
{
    public const MAX_BODY_BYTES = 16384;

    private Connection $conn;
    private GoalEngine $engine;
    private MysqlGoalRepository $goals;

    /**
     * @param (callable(): int)|null $clock
     * @param (callable(): ?string)|null $keyLoader the install-token key (tests plant one)
     */
    public function __construct(
        private readonly \mysqli $db,
        private $clock = null,
        ?GoalEngine $engine = null,
        private $keyLoader = null,
    ) {
        $this->conn = new Connection($db);
        $this->goals = new MysqlGoalRepository($this->conn);
        $this->engine = $engine ?? new GoalEngine($this->conn, $this->goals, null, $clock);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    /**
     * @return array{status: int, body: array<string, mixed>, headers?: array<string, string>}
     */
    public function receive(?string $token, string $rawBody, string $remoteIp): array
    {
        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return self::error(413, 'The install body is larger than ' . self::MAX_BODY_BYTES . ' bytes');
        }
        $registration = $this->registration($token);
        if (!$registration instanceof AppRegistration) {
            return $registration;
        }

        try {
            $decoded = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return self::error(400, 'The install body is not valid JSON');
        }
        try {
            $payload = InstallPayload::fromDecoded($decoded);
        } catch (ValidationException $e) {
            return self::error(400, $e->getMessage(), ['field_errors' => $e->getFieldErrors()]);
        }
        if ($payload->appKey !== $registration->identity->appKey) {
            return self::error(422, 'This app token belongs to ' . $registration->identity->appKey . ', but the install names '
                . $payload->appKey . '. A build must present its own app\'s token.', [
                    'field_errors' => ['app_key' => 'is ' . $payload->appKey . '; the token\'s registration is ' . $registration->identity->appKey],
                ]);
        }

        $stored = $this->stored($registration->registrationId, $payload->installUuid);
        if ($stored !== null) {
            return $this->replay($stored, $payload);
        }

        $parsed = $payload->referrerStatus === 'ok' ? ReferrerParser::parse((string) $payload->installReferrer) : null;
        try {
            $first = InstallClassifier::fromReferrer($payload, $parsed, $this->installKey($parsed));
        } catch (MissingInstallKey $e) {
            error_log('p202 android intake: ' . $e->getMessage());

            return self::error(503, 'This server cannot verify install tokens right now; retry later.', [], ['Retry-After' => '300']);
        }

        $work = fn (): array => $this->record($registration, $payload, $parsed, $first, $remoteIp);
        try {
            try {
                $done = $this->conn->transaction($work);
            } catch (Throwable $e) {
                if (!Connection::isRetryableLockError($e)) {
                    throw $e;
                }
                $done = $this->conn->transaction($work);
            }
        } catch (Throwable $e) {
            if (Connection::isMysqlError($e, 1062, 'Duplicate entry')) {
                // A concurrent request for the same install committed first.
                $stored = $this->stored($registration->registrationId, $payload->installUuid);
                if ($stored !== null) {
                    return $this->replay($stored, $payload);
                }
            }
            throw $e;
        }

        // Committed. What follows describes committed state; a failure here
        // still answers 200, because the install is recorded and a retry
        // would only be answered as its duplicate.
        try {
            $this->engine->finishCommitted($registration->userId, $done['post']);
        } catch (Throwable $e) {
            error_log('p202 android intake: install ' . $payload->installUuid . ' committed; its report refresh failed: ' . $e->getMessage());
        }

        $data = self::present($done['row'], false) + $this->customer($done['row'], $payload->customer);

        return ['status' => 200, 'body' => ['data' => $data]];
    }

    /**
     * Link the install's click to the signed customer id the body carried,
     * after commit (InstallCustomerLink). Empty when the body carried none.
     *
     * @param array<string, mixed> $row the committed install row
     * @return array{customer?: string}
     */
    public function customer(array $row, ?CustomerClaim $claim): array
    {
        if ($claim === null) {
            return [];
        }
        try {
            return ['customer' => (new InstallCustomerLink($this->conn))->link($row, $claim)];
        } catch (Throwable $e) {
            error_log('p202 android customer: install ' . (string) $row['install_uuid']
                . ' is recorded; linking its customer failed: ' . $e->getMessage());

            return ['customer' => InstallCustomerLink::NOT_LINKED];
        }
    }

    /**
     * The transaction body: insert, classify, evaluate, link.
     *
     * @param array{class: string, tokens: list<string>, fields: array<string, string|null>, raw: string, truncated: bool, meta_envelope: bool}|null $parsed
     * @param array{state: MatchState|null, reason: string, click_id: int|null} $first
     * @return array{row: array<string, mixed>, post: array{ledger: list<array<string, mixed>>, clicks: array<int, true>}}
     */
    private function record(AppRegistration $registration, InstallPayload $payload, ?array $parsed, array $first, string $remoteIp): array
    {
        $now = $this->now();
        $state = $first['state'] ?? MatchState::PENDING_CLICK;
        $reason = $first['state'] !== null ? $first['reason'] : 'Classifying.';
        $rowId = $this->insert($registration, $payload, $parsed, $state, $reason, $remoteIp, $now);

        $clickId = null;
        if ($first['state'] === null) {
            $classified = $this->classifyWithClick($registration, $payload, (int) $first['click_id'], $rowId, $now, $now);
            $state = $classified['state'];
            $reason = $classified['reason'];
            $clickId = $classified['click_id'];
        }

        $result = $this->settle($registration, $payload->test, $rowId, $state, $reason, $clickId, $now);

        return ['row' => $this->row($rowId), 'post' => $result];
    }

    /**
     * Write a classification onto the install row and, for a state that is
     * final, evaluate the install subject — in the caller's transaction.
     * Shared by the intake and the pending-click settler.
     *
     * @return array{ledger: list<array<string, mixed>>, clicks: array<int, true>}
     */
    public function settle(AppRegistration $registration, bool $test, int $rowId, MatchState $state, string $reason, ?int $clickId, int $now): array
    {
        $trusted = (new InstallVerdict($state, $test))->trustBit($registration->policy);
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_app_installs SET match_state = ?, match_reason = ?, trusted = ?, click_id = ?, settled_at = ?
             WHERE install_row_id = ?'
        );
        $this->conn->bind($stmt, 'ssiiii', [
            $state->value, mb_strimwidth($reason, 0, 255, '…', 'UTF-8'), $trusted, $clickId,
            $state->isPending() ? null : $now, $rowId,
        ]);
        $this->conn->executeUpdate($stmt);

        $post = ['ledger' => [], 'clicks' => []];
        if ($state->isPending() || $state->isRefuted()) {
            // A pending install is evaluated when it settles; a refuted one
            // (a forged or implausible claim) never reaches the goals.
            return $post;
        }

        $this->goals->ensureBuiltinInstallGoal($registration->userId, $registration->registrationId, $now);
        $subject = $this->engine->installSubject($registration->userId, $rowId);
        $evaluated = $this->engine->evaluateInstallInTransaction($registration->userId, $subject);
        if ($subject->clickId !== null) {
            if ($evaluated['install_conversion_id'] === null) {
                throw new \RuntimeException('install ' . $rowId . ' is attributed to click ' . $subject->clickId . ' but its install goal wrote no conversion');
            }
            $link = $this->conn->prepareWrite('UPDATE 202_app_installs SET conversion_id = ? WHERE install_row_id = ?');
            $this->conn->bind($link, 'ii', [$evaluated['install_conversion_id'], $rowId]);
            $this->conn->executeUpdate($link);
        }

        return $evaluated['post'];
    }

    /**
     * Step 2 of the classification: the click the verified token names,
     * locked, against this registration.
     *
     * @return array{state: MatchState, reason: string, click_id: int|null}
     */
    public function classifyWithClick(AppRegistration $registration, InstallPayload $payload, int $clickId, int $rowId, int $receivedAt, int $now): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT c.click_id, c.user_id, c.aff_campaign_id, c.click_time, ac.app_registration_id
             FROM 202_clicks c LEFT JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id
             WHERE c.click_id = ? LIMIT 1 FOR UPDATE'
        );
        $this->conn->bind($stmt, 'i', [$clickId]);
        $row = $this->conn->fetchOne($stmt);
        $click = $row === null ? null : [
            'user_id' => (int) $row['user_id'],
            'campaign_id' => (int) $row['aff_campaign_id'],
            'click_time' => (int) $row['click_time'],
            'campaign_registration_id' => $row['app_registration_id'] !== null ? (int) $row['app_registration_id'] : null,
        ];

        $hasInstall = false;
        if ($click !== null) {
            // Under the click lock, so two installs naming one click
            // serialise here and the second sees the first.
            $dup = $this->conn->prepareWrite(
                "SELECT (SELECT COUNT(*) FROM 202_app_installs WHERE click_id = ? AND match_state = 'attributed' AND install_row_id <> ?)
                      + (SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id = ? AND dedupe_key = 'install') AS n"
            );
            $this->conn->bind($dup, 'iii', [$clickId, $rowId, $clickId]);
            $hasInstall = (int) (($this->conn->fetchOne($dup) ?? ['n' => 0])['n']) > 0;
        }

        return InstallClassifier::withClick(
            $payload,
            $clickId,
            $click,
            $registration->userId,
            $registration->registrationId,
            $registration->policy->attributionWindowDays,
            $hasInstall,
            $receivedAt,
            $now,
        );
    }

    /**
     * @param array{class: string, tokens: list<string>, fields: array<string, string|null>, raw: string, truncated: bool, meta_envelope: bool}|null $parsed
     */
    private function insert(AppRegistration $registration, InstallPayload $payload, ?array $parsed, MatchState $state, string $reason, string $remoteIp, int $now): int
    {
        $fields = $parsed['fields'] ?? array_fill_keys([...ReferrerParser::UTM, 'gclid'], null);
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_app_installs
                (user_id, registration_id, install_uuid, body_hash, store, click_id, conversion_id, match_state, match_reason, trusted,
                 is_test, has_events, referrer_status, referrer_raw, referrer_truncated,
                 utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid,
                 referrer_click_at, install_begin_at, referrer_click_server_at, install_begin_server_at,
                 install_version, google_play_instant, app_version, sdk_version, os_version,
                 integrity_state, first_open_at, received_at, settled_at, raw_payload, remote_ip)
             VALUES (?, ?, ?, ?, ?, NULL, NULL, ?, ?, NULL,
                     ?, 0, ?, ?, ?,
                     ?, ?, ?, ?, ?, ?,
                     ?, ?, ?, ?,
                     ?, ?, ?, ?, ?,
                     ?, ?, ?, NULL, ?, ?)'
        );
        $this->conn->bind($stmt, 'iisssssississssssiiiisissssiiss', [
            $registration->userId, $registration->registrationId, $payload->installUuid, $payload->fingerprint(), $payload->store,
            $state->value, mb_strimwidth($reason, 0, 255, '…', 'UTF-8'),
            $payload->test ? 1 : 0, $payload->referrerStatus, $parsed['raw'] ?? null, !empty($parsed['truncated']) ? 1 : 0,
            $fields['utm_source'], $fields['utm_medium'], $fields['utm_campaign'], $fields['utm_term'], $fields['utm_content'], $fields['gclid'],
            $payload->referrerClickAt, $payload->installBeginAt, $payload->referrerClickServerAt, $payload->installBeginServerAt,
            $payload->installVersion, $payload->googlePlayInstant === null ? null : ($payload->googlePlayInstant ? 1 : 0),
            $payload->appVersion, $payload->sdkVersion, $payload->osVersion,
            // Play Integrity is PR 6: the token is kept in raw_payload for its
            // verdict worker, and nothing here judges it.
            $payload->integrityToken === null ? 'not_requested' : 'received',
            $payload->firstOpenAt, $now, $payload->raw(), self::ip($remoteIp),
        ]);
        $id = $this->conn->executeInsert($stmt);
        if ($id <= 0) {
            throw new \RuntimeException('the install insert returned no id');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $stored
     * @return array{status: int, body: array<string, mixed>}
     */
    private function replay(array $stored, InstallPayload $payload): array
    {
        if (!hash_equals((string) $stored['body_hash'], $payload->fingerprint())) {
            return self::error(409, 'install_uuid ' . $payload->installUuid . ' was already reported with different content. '
                . 'An install_uuid names one install; resend the stored body byte for byte, or mint a new id for a new install.');
        }

        // A replay links again: harmless when the first answer linked, and
        // the repair when the process died between the commit and the link.
        $data = self::present($stored, true) + $this->customer($stored, $payload->customer);

        return ['status' => 200, 'body' => ['data' => $data]];
    }

    /**
     * The registration a token names, or the answer that refuses it.
     *
     * @return AppRegistration|array{status: int, body: array<string, mixed>}
     */
    public function registration(?string $token): AppRegistration|array
    {
        $token = trim((string) $token);
        if ($token === '') {
            return self::error(400, 'Provide the app\'s token in the ' . AppToken::HEADER . ' header');
        }
        if (!AppToken::isWellFormed($token)) {
            return self::error(400, 'App tokens are 64 hexadecimal characters; use the app_token from POST /apps or GET /apps/{id}');
        }
        $registration = (new AppRegistry($this->db))->byToken($token);
        if ($registration === null) {
            return self::error(404, 'Unknown app token');
        }
        if ($registration->identity->platform !== AppIdentity::ANDROID) {
            return self::error(422, 'This app token belongs to an iOS app; installs are reported by the Android SDK. iOS installs arrive as Apple postbacks.');
        }

        return $registration;
    }

    /** @return array<string, mixed>|null */
    public function stored(int $registrationId, string $installUuid): ?array
    {
        $stmt = $this->conn->prepareWrite('SELECT * FROM 202_app_installs WHERE registration_id = ? AND install_uuid = ? LIMIT 1');
        $this->conn->bind($stmt, 'is', [$registrationId, $installUuid]);

        return $this->conn->fetchOne($stmt);
    }

    /** @return array<string, mixed> */
    public function installRow(int $rowId): array
    {
        return $this->row($rowId);
    }

    /** @return array<string, mixed> */
    private function row(int $rowId): array
    {
        $stmt = $this->conn->prepareWrite('SELECT * FROM 202_app_installs WHERE install_row_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$rowId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            throw new \RuntimeException('install ' . $rowId . ' vanished inside its own transaction');
        }

        return $row;
    }

    /**
     * The key, only when the referrer carries a token (organic installs
     * never need it, so a missing key cannot refuse them).
     *
     * @param array{class: string}|null $parsed
     */
    private function installKey(?array $parsed): ?string
    {
        if ($parsed === null || $parsed['class'] !== 'ours') {
            return null;
        }
        if ($this->keyLoader !== null) {
            return ($this->keyLoader)();
        }
        try {
            return InstallTokenKey::load($this->db);
        } catch (\RuntimeException $e) {
            error_log('p202 android intake: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * What the device is told: the install's own id, its state and reason,
     * its trust and whether this answer is a replay. Never the click, the
     * conversion or any money.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function present(array $row, bool $duplicate): array
    {
        return [
            'install_uuid' => (string) $row['install_uuid'],
            'match' => (string) $row['match_state'],
            'reason' => (string) $row['match_reason'],
            'trusted' => $row['trusted'] === null ? null : (int) $row['trusted'],
            'test' => (int) $row['is_test'] === 1,
            'duplicate' => $duplicate,
        ];
    }

    private static function ip(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>, headers?: array<string, string>}
     */
    public static function error(int $status, string $message, array $extra = [], array $headers = []): array
    {
        $out = ['status' => $status, 'body' => ['error' => true, 'message' => $message, 'status' => $status] + $extra];
        if ($headers !== []) {
            $out['headers'] = $headers;
        }

        return $out;
    }
}
