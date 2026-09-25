<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android\Integrity;

use Api\V3\Apps\AppIdentity;
use Api\V3\Apps\AppPolicy;
use Api\V3\Apps\AppRegistration;
use Api\V3\Apps\Android\InstallClassifier;
use Api\V3\Apps\Android\InstallIntake;
use Api\V3\Apps\Android\InstallPayload;
use Api\V3\Apps\Android\InstallTokenKey;
use Api\V3\Apps\Android\MatchState;
use Api\V3\Apps\Android\MissingInstallKey;
use Api\V3\Apps\Android\ReferrerParser;
use Prosper202\Database\Connection;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\MysqlGoalRepository;
use Throwable;

/**
 * The Play Integrity verdict worker (plan §5.6, §5.11), run by
 * 202-cronjobs/app-installs.php: it decodes the tokens installs arrived
 * with under `observe` and `require`, off the request path.
 *
 * One install at a time, in three steps, so no network call is ever made
 * inside a transaction (plan §5.2 step 4):
 *
 *  1. **Claim** it by compare-and-set on its attempt count, pushing its next
 *     attempt out by the backoff: two workers never decode one token, and a
 *     worker killed mid-call leaves the install to be retried when that
 *     lease runs out, never lost and never double-settled.
 *  2. **Decode** outside any transaction: skip the call when the install
 *     was refuted on its referrer (`skipped`) or its token is already owned
 *     by a verified install (`invalid`, a replay); otherwise load the
 *     registration's credential and ask the client. A decoded verdict is
 *     judged by IntegrityPolicy.
 *  3. **Settle** in one transaction, the same shape as the intake's (plan
 *     §5.2): the install row locked FOR UPDATE and re-checked (still
 *     pending, still our claim), the verdict written, and — for an install
 *     waiting as `pending_integrity` — its classification redone under the
 *     click's lock from the stored body and written through
 *     InstallIntake::settle(), whose require gate turns the verdict into
 *     attributed, integrity_failed or integrity_unverified. The conversion,
 *     the MTA outbox row and the traffic-source notification of an
 *     attributed install commit here, with the verdict that allowed them.
 *
 * Retries: a `retry` answer is tried again after 1 minute, doubling to at
 * most an hour, until the install is DEADLINE old or has had MAX_ATTEMPTS;
 * then it is `error`, terminal, and under require the install is
 * integrity_unverified — recorded, never paid. A quota refusal is a retry
 * like any other: it is never waved through (error pattern #11). The limits
 * hold on every path: an install already past them is retired before its
 * token is decoded, a passing verdict that lands after the deadline is not
 * accepted, and an attempt that throws spends its attempt and, at the
 * limits, retires the install rather than leaving it due for ever.
 */
final class IntegrityVerifier
{
    public const DEADLINE = 86400;
    public const MAX_ATTEMPTS = 24;
    public const FIRST_BACKOFF = 60;
    public const MAX_BACKOFF = 3600;
    /** 202_app_installs.integrity_attempts is a tinyint unsigned; the claim's LEAST() spells the same number. */
    public const ATTEMPTS_CEILING = 255;

    private Connection $conn;
    private GoalEngine $engine;
    private InstallIntake $intake;
    private IntegrityCredentialStore $credentials;

    /**
     * @param (callable(): int)|null $clock
     * @param (callable(): ?string)|null $keyLoader the install-token key (tests plant one)
     */
    public function __construct(
        private readonly \mysqli $db,
        private readonly PlayIntegrityClient $client,
        private $clock = null,
        private $keyLoader = null,
    ) {
        $this->conn = new Connection($db);
        $this->engine = new GoalEngine($this->conn, new MysqlGoalRepository($this->conn), null, $clock);
        $this->intake = new InstallIntake($db, $clock, $this->engine);
        $this->credentials = new IntegrityCredentialStore($this->conn);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    public static function backoff(int $attempt): int
    {
        return min(self::MAX_BACKOFF, self::FIRST_BACKOFF * (2 ** max(0, min(20, $attempt - 1))));
    }

    /**
     * Process up to $limit installs whose next attempt is due, oldest first.
     *
     * @return array{examined: int, verdicts: array<string, int>, retrying: int, failed: int}
     */
    public function run(int $limit = 200): array
    {
        // Installs whose registration is gone can never be decoded (the
        // credential went with it) or settled (every read below joins the
        // registration): they are finalized, never left due. And the
        // selection joins the registration too, so a row processOne() would
        // decline can never hold a slot in this oldest-first batch — enough
        // of them would otherwise starve every other app's verdicts.
        (new UnverifiableInstalls($this->conn))->settleOrphans($this->now());

        $stmt = $this->conn->prepareWrite(
            "SELECT i.install_row_id FROM 202_app_installs i JOIN 202_app_registrations r ON r.registration_id = i.registration_id
             WHERE i.integrity_state = 'pending' AND i.integrity_next_at <= ?
             ORDER BY i.integrity_next_at, i.install_row_id LIMIT ?"
        );
        $this->conn->bind($stmt, 'ii', [$this->now(), max(1, $limit)]);
        $ids = array_map(static fn (array $r): int => (int) $r['install_row_id'], $this->conn->fetchAll($stmt));

        $out = ['examined' => count($ids), 'verdicts' => [], 'retrying' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            try {
                $state = $this->processOne($id);
            } catch (Throwable $e) {
                // One bad row must not stop the others; its lease expires and
                // it is tried again, and the attempt it used counts.
                $out['failed']++;
                error_log('p202 play integrity: install ' . $id . ' failed: ' . $e->getMessage());
                continue;
            }
            if ($state === null) {
                continue; // claimed or settled meanwhile by another run
            }
            if ($state === IntegrityState::PENDING) {
                $out['retrying']++;
                continue;
            }
            $out['verdicts'][$state->value] = ($out['verdicts'][$state->value] ?? 0) + 1;
        }

        return $out;
    }

    /** The integrity state the install was left in, or null when it was not ours to process. */
    public function processOne(int $installRowId): ?IntegrityState
    {
        $row = $this->read($installRowId);
        if ($row === null || (string) $row['integrity_state'] !== IntegrityState::PENDING->value) {
            return null;
        }
        $attempts = (int) $row['integrity_attempts'];
        $now = $this->now();
        $claim = $this->conn->prepareWrite(
            "UPDATE 202_app_installs SET integrity_attempts = LEAST(integrity_attempts + 1, 255), integrity_next_at = ?
             WHERE install_row_id = ? AND integrity_state = 'pending' AND integrity_attempts = ? AND integrity_next_at <= ?"
        );
        // Due, and still at the count we read: a second worker — or this one
        // run again while an earlier claim's call is in flight — finds the
        // lease (next_at pushed out) or the bumped count, and leaves it. The
        // count stops at the column's ceiling rather than overflowing it: an
        // unsigned tinyint past 255 is an error under strict mode, which
        // would fail this claim on every run and pin the row at the head of
        // the oldest-first batch for good.
        $this->conn->bind($claim, 'iiii', [$now + self::backoff($attempts + 1), $installRowId, $attempts, $now]);
        if ($this->conn->executeUpdate($claim) !== 1) {
            return null;
        }
        $attempt = min($attempts + 1, self::ATTEMPTS_CEILING);
        $receivedAt = (int) $row['received_at'];

        // The deadline is enforced before anything is decoded: a worker that
        // was stopped or backlogged past it must not turn a late verdict into
        // an attribution the 24-hour rule already refused.
        if (self::expired($receivedAt, $attempt, $now)) {
            return $this->retire($installRowId, $attempt, self::expiredReason($receivedAt, $attempt, $now, null));
        }

        try {
            $outcome = $this->decide($row, $now);
            if ($outcome['state'] === IntegrityState::PENDING) {
                if (!self::expired($receivedAt, $attempt, $now)) {
                    $this->recordRetry($installRowId, $attempt, $outcome['reason']);

                    return IntegrityState::PENDING;
                }
                $outcome = self::errorOutcome('No verdict after ' . $attempt . ' attempt' . ($attempt === 1 ? '' : 's') . ': ' . $outcome['reason']);
            }

            return $this->finish($installRowId, $attempt, $outcome);
        } catch (Throwable $e) {
            // The claim has spent an attempt. Below the limits the row is
            // retried when its lease runs out (run() counts the failure);
            // at them it is retired here, so a failure that repeats on
            // every run (a key that cannot be loaded, a stored body that no
            // longer parses) ends like any other exhausted install instead
            // of being claimed for ever.
            if (!self::expired($receivedAt, $attempt, $this->now())) {
                throw $e;
            }

            return $this->retire($installRowId, $attempt, self::expiredReason($receivedAt, $attempt, $this->now(), $e->getMessage()));
        }
    }

    /** Past the deadline, or out of attempts. */
    private static function expired(int $receivedAt, int $attempt, int $now): bool
    {
        return $now >= $receivedAt + self::DEADLINE || $attempt >= self::MAX_ATTEMPTS;
    }

    private static function expiredReason(int $receivedAt, int $attempt, int $now, ?string $failure): string
    {
        $why = $now >= $receivedAt + self::DEADLINE
            ? 'No verdict within ' . intdiv(self::DEADLINE, 3600) . ' hours of receipt'
            : 'No verdict after ' . $attempt . ' attempts';

        return $why . ($failure !== null ? ': the last attempt failed (' . $failure . ')' : '; the token was not decoded') . '.';
    }

    /** @return array{state: IntegrityState, reason: string, verdict: string|null, decoded: bool} */
    private static function errorOutcome(string $reason): array
    {
        return ['state' => IntegrityState::ERROR, 'reason' => $reason, 'verdict' => null, 'decoded' => false];
    }

    /**
     * Retire an expired install as `error`: through finish() when it can
     * run, so a held install is settled by settle()'s gate like every other
     * (integrity_unverified, evaluated for the funnel); and when finish()
     * itself fails, by UnverifiableInstalls' constant transition, which
     * needs no key, no body and no classification — terminal, never paid.
     */
    private function retire(int $installRowId, int $attempt, string $reason): ?IntegrityState
    {
        try {
            return $this->finish($installRowId, $attempt, self::errorOutcome($reason));
        } catch (Throwable $e) {
            error_log('p202 play integrity: install ' . $installRowId . ' could not be settled normally; retiring it: ' . $e->getMessage());
            $retired = (new UnverifiableInstalls($this->conn))->settleExhausted($installRowId, $attempt, self::cut($reason), $this->now());

            return $retired > 0 ? IntegrityState::ERROR : null;
        }
    }

    /**
     * Step 2: what this attempt concludes, without writing.
     *
     * @param array<string, mixed> $row
     * @return array{state: IntegrityState, reason: string, verdict: string|null, decoded: bool}
     */
    private function decide(array $row, int $now): array
    {
        $retry = static fn (string $reason): array => ['state' => IntegrityState::PENDING, 'reason' => $reason, 'verdict' => null, 'decoded' => false];
        $final = static fn (IntegrityState $state, string $reason, ?string $verdict = null, bool $decoded = false): array
            => ['state' => $state, 'reason' => $reason, 'verdict' => $verdict, 'decoded' => $decoded];

        if (MatchState::tryFrom((string) $row['match_state'])?->isRefuted() === true) {
            return $final(IntegrityState::SKIPPED, 'The install was refuted on its referrer (' . $row['match_state'] . '); its token was not decoded.');
        }
        $owner = $this->verifiedOwnerOf((int) $row['registration_id'], (string) $row['integrity_token_hash'], (int) $row['install_row_id']);
        if ($owner !== null) {
            return $final(IntegrityState::INVALID, 'This integrity token was already verified for install ' . $owner . ': a replayed token.',
                (string) json_encode(['code' => 'replayed_token']));
        }

        $payload = self::storedPayload((string) $row['raw_payload']);
        $token = $payload->integrityToken;
        if ($token === null || !hash_equals((string) $row['integrity_token_hash'], IntegrityBinding::tokenHash($token))) {
            throw new \RuntimeException('install ' . $row['install_row_id'] . ' is pending integrity but its stored token is missing or does not match its hash');
        }
        try {
            $credential = $this->credentials->load((int) $row['user_id'], (int) $row['registration_id']);
        } catch (\RuntimeException $e) {
            return $retry($e->getMessage());
        }
        if ($credential === null) {
            return $retry('No Play Integrity credential is configured for this app (PUT /apps/' . $row['registration_id'] . '/integrity-credential).');
        }

        $result = $this->client->decode($credential, (string) $row['app_key'], $token);
        if ($result->kind === DecodeResult::RETRY) {
            return $retry($result->reason);
        }
        if ($result->kind === DecodeResult::REJECTED || $result->payload === null) {
            return $final(IntegrityState::INVALID, $result->reason, (string) json_encode(['code' => 'undecodable']));
        }
        $judgement = IntegrityPolicy::judge($result->payload, (string) $row['app_key'], IntegrityBinding::requestHash($payload), (int) $row['received_at']);

        return $final($judgement->valid ? IntegrityState::VALID : IntegrityState::INVALID, $judgement->reason, $judgement->summaryJson(), true);
    }

    private function recordRetry(int $installRowId, int $attempt, string $reason): void
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_app_installs SET integrity_reason = ? WHERE install_row_id = ? AND integrity_state = 'pending' AND integrity_attempts = ?"
        );
        $this->conn->bind($stmt, 'sii', [self::cut('Attempt ' . $attempt . ': ' . $reason), $installRowId, $attempt]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * Step 3: write the verdict and, for a waiting install, its final state,
     * in one transaction.
     *
     * @param array{state: IntegrityState, reason: string, verdict: string|null, decoded: bool} $outcome
     */
    private function finish(int $installRowId, int $attempt, array $outcome): ?IntegrityState
    {
        $work = function () use ($installRowId, $attempt, $outcome): array {
            $none = ['state' => null, 'post' => ['ledger' => [], 'clicks' => []], 'user' => 0];
            $lock = $this->conn->prepareWrite(
                'SELECT i.*, r.platform, r.app_key, r.accept_test_signals, r.attribution_window_days, r.trust_client_revenue
                 FROM 202_app_installs i JOIN 202_app_registrations r ON r.registration_id = i.registration_id
                 WHERE i.install_row_id = ? LIMIT 1 FOR UPDATE'
            );
            $this->conn->bind($lock, 'i', [$installRowId]);
            $row = $this->conn->fetchOne($lock);
            if ($row === null || (string) $row['integrity_state'] !== IntegrityState::PENDING->value || (int) $row['integrity_attempts'] !== $attempt) {
                return $none; // another worker's claim superseded ours
            }
            $now = $this->now();
            if ($outcome['state'] === IntegrityState::VALID && $now >= (int) $row['received_at'] + self::DEADLINE) {
                // Decoded, but after the deadline: the rule is "a verdict
                // within 24 hours", and a slow call or a backlogged worker
                // does not extend it. Recorded (the verdict is kept), never
                // accepted.
                $outcome = [
                    'state' => IntegrityState::ERROR,
                    'reason' => 'A passing verdict arrived after the ' . intdiv(self::DEADLINE, 3600) . '-hour deadline and was not accepted.',
                    'verdict' => $outcome['verdict'],
                    'decoded' => $outcome['decoded'],
                ];
            }
            $write = $this->conn->prepareWrite(
                'UPDATE 202_app_installs SET integrity_state = ?, integrity_reason = ?, integrity_verdict = ?, integrity_next_at = NULL,
                        integrity_checked_at = IF(?, ?, integrity_checked_at)
                 WHERE install_row_id = ?'
            );
            $this->conn->bind($write, 'sssiii', [
                $outcome['state']->value, self::cut($outcome['reason']), $outcome['verdict'], $outcome['decoded'] ? 1 : 0, $now, $installRowId,
            ]);
            $this->conn->executeUpdate($write);

            if ((string) $row['match_state'] !== MatchState::PENDING_INTEGRITY->value) {
                // observe, or an install that never waited: the verdict is recorded, nothing else moves.
                return ['state' => $outcome['state'], 'post' => $none['post'], 'user' => 0];
            }
            $registration = new AppRegistration(
                (int) $row['registration_id'],
                (int) $row['user_id'],
                AppIdentity::fromKey((string) $row['platform'], (string) $row['app_key']),
                AppPolicy::fromRow($row),
            );
            // Re-classified from the stored body under the click's lock, as
            // the intake would now: another install may have taken the click
            // meanwhile (duplicate_click). settle()'s gate reads the verdict
            // just written.
            $payload = self::storedPayload((string) $row['raw_payload']);
            $first = InstallClassifier::fromReferrer($payload, ReferrerParser::parse((string) $payload->installReferrer), $this->key());
            if ($first['state'] !== null || $first['click_id'] === null) {
                throw new \RuntimeException('install ' . $installRowId . ' is pending_integrity but its stored referrer no longer names a verified click');
            }
            $classified = $this->intake->classifyWithClick($registration, $payload, (int) $first['click_id'], $installRowId, (int) $row['received_at'], $now);
            $settled = $this->intake->settle($registration, (int) $row['is_test'] === 1, $installRowId, $classified['state'], $classified['reason'], $classified['click_id'], $now);

            return ['state' => $outcome['state'], 'post' => $settled['post'], 'user' => $registration->userId];
        };
        try {
            $done = $this->conn->transaction($work);
        } catch (Throwable $e) {
            if (!Connection::isRetryableLockError($e)) {
                throw $e;
            }
            $done = $this->conn->transaction($work);
        }
        if ($done['user'] > 0) {
            try {
                $this->engine->finishCommitted($done['user'], $done['post']);
            } catch (Throwable $e) {
                error_log('p202 play integrity: install ' . $installRowId . ' settled; its report refresh failed: ' . $e->getMessage());
            }
        }

        return $done['state'];
    }

    /** @return array<string, mixed>|null */
    private function read(int $installRowId): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT i.install_row_id, i.user_id, i.registration_id, i.match_state, i.integrity_state, i.integrity_attempts, i.integrity_token_hash,
                    i.raw_payload, i.received_at, r.app_key
             FROM 202_app_installs i JOIN 202_app_registrations r ON r.registration_id = i.registration_id
             WHERE i.install_row_id = ? LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$installRowId]);

        return $this->conn->fetchOne($stmt);
    }

    /** The install id (uuid) of another install of the registration whose verified token this is, or null. */
    private function verifiedOwnerOf(int $registrationId, string $tokenHash, int $installRowId): ?string
    {
        $stmt = $this->conn->prepareWrite(
            "SELECT install_uuid FROM 202_app_installs
             WHERE registration_id = ? AND integrity_token_hash = ? AND install_row_id <> ? AND integrity_state = 'valid' LIMIT 1"
        );
        $this->conn->bind($stmt, 'isi', [$registrationId, $tokenHash, $installRowId]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : (string) $row['install_uuid'];
    }

    /** The body the intake validated, re-read exactly as the pending-click settler reads it. */
    private static function storedPayload(string $raw): InstallPayload
    {
        return InstallPayload::fromDecoded(json_decode($raw, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING));
    }

    private function key(): string
    {
        $key = $this->keyLoader !== null ? ($this->keyLoader)() : InstallTokenKey::load($this->db);
        if ($key === null) {
            throw new MissingInstallKey();
        }

        return $key;
    }

    private static function cut(string $text): string
    {
        return mb_strimwidth($text, 0, 255, '…', 'UTF-8');
    }
}
